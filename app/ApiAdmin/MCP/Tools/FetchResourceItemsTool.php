<?php

declare(strict_types=1);

namespace App\ApiAdmin\MCP\Tools;

use App\ApiAdmin\MCP\EndpointBinder;
use App\ApiAdmin\MCP\Generator\ResourceGenerator;
use App\ApiAdmin\MCP\ItemSerializer;
use App\ApiAdmin\MCP\ResourceLocator;
use App\ApiAdmin\MCP\ResourceNotFoundException;
use App\ApiAdmin\MCP\ResourceRequestContext;
use App\ApiAdmin\MCP\UnresolvedPlaceholdersException;
use App\ApiAdmin\MCP\UpstreamErrorResponder;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * MCP Tool: fetch_resource_items
 *
 * List items from a registered REST resource through the same data layer
 * the admin-panel UI uses (AbstractRestResource::getItems) — so filter
 * format, pagination strategy, auth headers, default_query, and any
 * per-resource detail_strategy override apply automatically. The agent
 * doesn't have to hand-build query strings or guess paginator shapes.
 */
final class FetchResourceItemsTool implements ToolContract
{
    /**
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return [
            'name' => 'fetch_resource_items',
            'description' => 'Fetch paginated list of items from a registered REST resource, passing through the resource\'s configured filter format, sort/search param names, pagination strategy, and auth. Use this instead of probe_endpoint when you need actual data (not shape discovery).',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'service' => ['type' => 'string', 'description' => 'Registered service name (e.g. "blog").'],
                    'resource' => ['type' => 'string', 'description' => 'Resource name in snake_case (e.g. "post", "comment").'],
                    'parent_context' => [
                        'type' => 'object',
                        'description' => 'Required when the resource\'s endpoint has {placeholders} like {post_id} — supply scalar values for each. Ignored (with warning) on non-relation resources.',
                    ],
                    'filter' => ['type' => 'object', 'description' => 'Filter map, serialised according to the service\'s filter_format (nested / flat).'],
                    'search' => ['type' => 'string', 'description' => 'Free-text search term, routed through the configured search_param_name.'],
                    'sort' => ['type' => 'string', 'description' => 'Sort column; prefix with "-" for descending (e.g. "-created_at").'],
                    'page' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Page number (length_aware / link_header only).'],
                    'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'description' => 'Items per page.'],
                    'cursor' => ['type' => 'string', 'description' => 'Opaque cursor token for cursor-paginated resources — use the value of pagination.next_cursor from a prior call.'],
                ],
                'required' => ['service', 'resource'],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public function execute(array $params): array
    {
        $service = isset($params['service']) && is_string($params['service']) ? $params['service'] : '';
        $resourceName = isset($params['resource']) && is_string($params['resource']) ? $params['resource'] : '';

        if ($service === '' || $resourceName === '') {
            return [
                'success' => false,
                'code' => 'VALIDATION',
                'error' => "Missing required 'service' or 'resource'",
            ];
        }

        Log::info('FetchResourceItemsTool: executing', ['service' => $service, 'resource' => $resourceName]);

        $locator = new ResourceLocator;

        try {
            $resource = $locator->instantiate($service, $resourceName);
        } catch (ResourceNotFoundException $e) {
            return [
                'success' => false,
                'code' => 'RESOURCE_NOT_FOUND',
                'error' => $e->getMessage(),
                'available_resources' => array_map(
                    static function (array $r): string {
                        $name = $r['name'] ?? '';

                        return is_string($name) ? $name : '';
                    },
                    $e->availableResources,
                ),
                'hint' => 'Call list_resources to see what is generated, or create_resource first.',
            ];
        }

        $parentContextRaw = $params['parent_context'] ?? [];
        /** @var array<string, scalar|null> $parentContext */
        $parentContext = is_array($parentContextRaw) ? $parentContextRaw : [];

        try {
            $binding = EndpointBinder::bind($resource, $parentContext);
        } catch (UnresolvedPlaceholdersException $e) {
            return [
                'success' => false,
                'code' => 'RELATION_RESOURCE_REQUIRES_PARENT',
                'error' => $e->getMessage(),
                'placeholders' => $e->placeholders,
                'hint' => 'Pass parent_context with keys matching the placeholders (e.g. {"post_id": 7}).',
            ];
        }

        $seed = $this->buildSeedParams($params);

        try {
            $result = ResourceRequestContext::seed(
                $resource,
                $seed,
                static fn ($r): iterable|CursorPaginator|Paginator => $r->getItems(),
            );
        } catch (Throwable $e) {
            return UpstreamErrorResponder::fromThrowable($e);
        } finally {
            $resource->setEndpointOverride(null);
        }

        $mapping = $resource->getServiceConfig()->responseMapping;
        $payload = ItemSerializer::serializePaginator($result, $mapping);

        [$availableFilters, $availableSorts] = $this->loadDiscoveryHints($service, $resourceName);

        $out = [
            'success' => true,
            'service' => $service,
            'resource' => $resourceName,
            'endpoint' => $binding['resolved'],
            'data' => $payload,
            'available_filters' => $availableFilters,
            'available_sorts' => $availableSorts,
        ];

        if ($binding['ignoredParentContext']) {
            $out['ignored_parent_context'] = true;
        }

        return $out;
    }

    /**
     * Read the spec sidecar at `.ai-factory/specs/<service>/<resource>.json`
     * and surface the filter keys and sortable columns declared at
     * create_resource time. Non-authoritative (the agent may well send a
     * filter outside this list — upstream decides), but it saves a round-
     * trip of "does this resource support filter.status?" guessing.
     *
     * Missing sidecar or malformed JSON → two empty lists, never throws.
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private function loadDiscoveryHints(string $service, string $resource): array
    {
        $path = ResourceGenerator::specSidecarPath($service, $resource);
        if (! File::exists($path)) {
            return [[], []];
        }

        /** @var mixed $decoded */
        $decoded = json_decode(File::get($path), associative: true);
        if (! is_array($decoded)) {
            return [[], []];
        }

        $filters = [];
        if (isset($decoded['filters']) && is_array($decoded['filters'])) {
            foreach ($decoded['filters'] as $filter) {
                if (is_array($filter) && isset($filter['column']) && is_string($filter['column']) && $filter['column'] !== '') {
                    $filters[] = $filter['column'];

                    continue;
                }
                if (is_string($filter) && $filter !== '') {
                    $filters[] = $filter;
                }
            }
        }

        $sorts = [];
        if (isset($decoded['fields']) && is_array($decoded['fields'])) {
            foreach ($decoded['fields'] as $field) {
                if (! is_array($field)) {
                    continue;
                }
                if (! isset($field['column'])) {
                    continue;
                }
                if (! is_string($field['column'])) {
                    continue;
                }
                $methodsRaw = $field['methods'] ?? null;
                $isSortable = false;
                if (is_array($methodsRaw)) {
                    if ($this->listContainsSortable($methodsRaw)) {
                        $isSortable = true;
                    }
                    foreach ($methodsRaw as $methodEntry) {
                        if (is_array($methodEntry) && $this->listContainsSortable($methodEntry)) {
                            $isSortable = true;
                        }
                    }
                }
                if ($isSortable) {
                    $sorts[] = $field['column'];
                }
            }
        }

        return [array_values(array_unique($filters)), array_values(array_unique($sorts))];
    }

    /**
     * @param array<mixed> $methods
     */
    private function listContainsSortable(array $methods): bool
    {
        foreach ($methods as $m) {
            if (is_string($m) && $m === 'sortable') {
                return true;
            }
            if (is_array($m) && isset($m['name']) && $m['name'] === 'sortable') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{
     *     page?: int,
     *     per_page?: int,
     *     filter?: array<string, mixed>,
     *     search?: string|null,
     *     sort?: string|null,
     *     cursor?: string|null,
     * }
     */
    private function buildSeedParams(array $params): array
    {
        $seed = [];

        if (isset($params['page']) && is_int($params['page'])) {
            $seed['page'] = $params['page'];
        }
        if (isset($params['per_page']) && is_int($params['per_page'])) {
            $seed['per_page'] = $params['per_page'];
        }
        if (isset($params['filter']) && is_array($params['filter'])) {
            /** @var array<string, mixed> $filter */
            $filter = $params['filter'];
            $seed['filter'] = $filter;
        }
        if (isset($params['search']) && is_string($params['search'])) {
            $seed['search'] = $params['search'];
        }
        if (isset($params['sort']) && is_string($params['sort'])) {
            $seed['sort'] = $params['sort'];
        }
        if (isset($params['cursor']) && is_string($params['cursor'])) {
            $seed['cursor'] = $params['cursor'];
        }

        return $seed;
    }
}
