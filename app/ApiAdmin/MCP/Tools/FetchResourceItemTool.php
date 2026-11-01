<?php

declare(strict_types=1);

namespace App\ApiAdmin\MCP\Tools;

use App\ApiAdmin\MCP\EndpointBinder;
use App\ApiAdmin\MCP\ItemSerializer;
use App\ApiAdmin\MCP\ResourceLocator;
use App\ApiAdmin\MCP\ResourceNotFoundException;
use App\ApiAdmin\MCP\ResourceRequestContext;
use App\ApiAdmin\MCP\UnresolvedPlaceholdersException;
use App\ApiAdmin\MCP\UpstreamErrorResponder;
use Illuminate\Support\Facades\Log;
use MoonShine\Contracts\Core\TypeCasts\DataWrapperContract;
use Throwable;

/**
 * MCP Tool: fetch_resource_item
 *
 * Fetch a single item by id via the resource's configured detail strategy
 * (GET /{id} or list-filter lookup — per-resource override wins). Supports
 * relation endpoints through `parent_context` the same way the list tool
 * does, so the agent can ask for "comment 12 of post 7" without knowing
 * what the endpoint template looks like.
 */
final class FetchResourceItemTool implements ToolContract
{
    /**
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return [
            'name' => 'fetch_resource_item',
            'description' => 'Fetch a single item by id from a registered REST resource, honoring the configured detail_strategy (endpoint / list). Returns the raw upstream payload with primary_key guaranteed at the top level.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'service' => ['type' => 'string', 'description' => 'Registered service name (e.g. "blog").'],
                    'resource' => ['type' => 'string', 'description' => 'Resource name in snake_case (e.g. "post").'],
                    'id' => [
                        'type' => ['string', 'integer'],
                        'description' => 'Item id (scalar). Matches the resource\'s primary_key.',
                    ],
                    'parent_context' => [
                        'type' => 'object',
                        'description' => 'Required when the resource\'s endpoint has {placeholders}. Ignored with a warning on non-relation resources.',
                    ],
                ],
                'required' => ['service', 'resource', 'id'],
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
        $id = $params['id'] ?? null;

        if ($service === '' || $resourceName === '' || ! is_int($id) && ! is_string($id) || $id === '') {
            return [
                'success' => false,
                'code' => 'VALIDATION',
                'error' => "Missing required 'service', 'resource', or 'id'",
            ];
        }

        Log::info('FetchResourceItemTool: executing', [
            'service' => $service,
            'resource' => $resourceName,
            'id' => $id,
        ]);

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

        try {
            $wrapper = ResourceRequestContext::seed(
                $resource,
                ['id' => $id],
                static fn ($r): ?DataWrapperContract => $r->findItem(false),
            );
        } catch (Throwable $e) {
            return UpstreamErrorResponder::fromThrowable($e);
        } finally {
            $resource->setEndpointOverride(null);
        }

        if (! $wrapper instanceof DataWrapperContract) {
            return [
                'success' => false,
                'code' => 'NOT_FOUND',
                'error' => "Item '{$id}' not found in resource '{$resourceName}' of service '{$service}'.",
                'service' => $service,
                'resource' => $resourceName,
                'id' => $id,
            ];
        }

        $mapping = $resource->getServiceConfig()->responseMapping;

        $out = [
            'success' => true,
            'service' => $service,
            'resource' => $resourceName,
            'endpoint' => $binding['resolved'],
            'item' => ItemSerializer::serialize($wrapper, $mapping),
        ];

        if ($binding['ignoredParentContext']) {
            $out['ignored_parent_context'] = true;
        }

        return $out;
    }
}
