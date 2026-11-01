<?php

declare(strict_types=1);

namespace App\ApiAdmin\MCP\Analytics;

use App\ApiAdmin\MCP\EndpointBinder;
use App\ApiAdmin\MCP\ItemSerializer;
use App\ApiAdmin\MCP\ResourceLocator;
use App\ApiAdmin\MCP\ResourceNotFoundException;
use App\ApiAdmin\MCP\ResourceRequestContext;
use App\ApiAdmin\MCP\UnresolvedPlaceholdersException;
use App\ApiAdmin\MCP\UpstreamErrorResponder;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Shared data-fetch path for MCP analytics tools.
 *
 * Analytics must go through the generated resource instead of hand-building
 * HTTP calls so auth, response mapping, default_query, filter format,
 * pagination strategy, and relation endpoint binding all behave exactly like
 * the admin UI and fetch_resource_items.
 */
final class AnalyticsQueryBuilder
{
    private const int DEFAULT_PER_PAGE = 200;

    private const int MAX_PER_PAGE = 500;

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public function fetch(array $params): array
    {
        $service = isset($params['service']) && is_string($params['service']) ? $params['service'] : '';
        $resourceName = isset($params['resource']) && is_string($params['resource']) ? $params['resource'] : '';

        Log::debug('AnalyticsQueryBuilder::fetch — entering', [
            'service' => $service,
            'resource' => $resourceName,
            'query_keys' => array_keys($params),
        ]);

        if ($service === '' || $resourceName === '') {
            Log::warning('AnalyticsQueryBuilder::fetch — missing target', [
                'has_service' => $service !== '',
                'has_resource' => $resourceName !== '',
            ]);

            return [
                'success' => false,
                'code' => 'VALIDATION',
                'error' => "Missing required 'service' or 'resource'",
            ];
        }

        $filterResult = $this->buildFilter($params);
        if ($filterResult['success'] === false) {
            return $filterResult;
        }

        try {
            $resource = (new ResourceLocator)->instantiate($service, $resourceName);
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

        Log::debug('AnalyticsQueryBuilder::fetch — endpoint bound', [
            'service' => $service,
            'resource' => $resourceName,
            'endpoint' => $binding['resolved'],
            'ignored_parent_context' => $binding['ignoredParentContext'],
        ]);

        $filter = $filterResult['filter'];
        $seed = $this->buildSeedParams($params, $filter);

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

        $out = [
            'success' => true,
            'service' => $service,
            'resource' => $resourceName,
            'endpoint' => $binding['resolved'],
            'data' => $payload,
            'applied_filters' => $filter,
        ];

        if ($binding['ignoredParentContext']) {
            $out['ignored_parent_context'] = true;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{success: true, filter: array<string, mixed>}|array{success: false, code: string, error: string, hint?: string}
     */
    private function buildFilter(array $params): array
    {
        $filter = [];
        if (isset($params['filter'])) {
            if (! is_array($params['filter'])) {
                Log::warning('AnalyticsQueryBuilder::buildFilter — invalid filter shape');

                return [
                    'success' => false,
                    'code' => 'VALIDATION',
                    'error' => "'filter' must be an object when provided",
                    'hint' => 'Pass filter as a column/value map, e.g. {"status": "published"}.',
                ];
            }

            $filter = $this->normalizeStringKeyMap($params['filter']);
        }

        if (! array_key_exists('time_window', $params)) {
            return ['success' => true, 'filter' => $filter];
        }

        $timeWindow = $params['time_window'];
        if (! is_array($timeWindow)) {
            Log::warning('AnalyticsQueryBuilder::buildFilter — time_window is not an object');

            return [
                'success' => false,
                'code' => 'VALIDATION',
                'error' => "'time_window' must be an object",
                'hint' => 'Use {"field": "created_at", "from": "2026-01-01", "to": "2026-01-31"}.',
            ];
        }

        $field = $timeWindow['field'] ?? null;
        if (! is_string($field) || $field === '') {
            Log::warning('AnalyticsQueryBuilder::buildFilter — time_window field missing');

            return [
                'success' => false,
                'code' => 'VALIDATION',
                'error' => "'time_window.field' is required",
                'hint' => 'Use a generated resource field name, e.g. created_at.',
            ];
        }

        $range = [];
        foreach (['from', 'to'] as $key) {
            $value = $timeWindow[$key] ?? null;
            if ($value === null) {
                continue;
            }
            if ($value === '') {
                continue;
            }
            if (! is_scalar($value)) {
                Log::warning('AnalyticsQueryBuilder::buildFilter — time_window boundary is not scalar', [
                    'field' => $field,
                    'boundary' => $key,
                ]);

                return [
                    'success' => false,
                    'code' => 'VALIDATION',
                    'error' => "'time_window.{$key}' must be a scalar value",
                    'hint' => 'Use ISO-8601 strings or timestamps for time window boundaries.',
                ];
            }
            $range[$key] = $value;
        }

        if ($range === []) {
            Log::warning('AnalyticsQueryBuilder::buildFilter — time_window has no boundaries', [
                'field' => $field,
            ]);

            return [
                'success' => false,
                'code' => 'VALIDATION',
                'error' => "'time_window' requires at least one of 'from' or 'to'",
                'hint' => 'Use {"field": "created_at", "from": "2026-01-01"} or include both boundaries.',
            ];
        }

        $filter[$field] = $range;

        return ['success' => true, 'filter' => $filter];
    }

    /**
     * @param array<mixed> $values
     *
     * @return array<string, mixed>
     */
    private function normalizeStringKeyMap(array $values): array
    {
        $out = [];
        foreach ($values as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $filter
     *
     * @return array<string, mixed>
     */
    private function buildSeedParams(array $params, array $filter): array
    {
        $seed = [
            'page' => 1,
            'per_page' => self::DEFAULT_PER_PAGE,
        ];

        $perPage = $params['per_page'] ?? $params['limit'] ?? null;
        if (is_int($perPage)) {
            $seed['per_page'] = max(1, min(self::MAX_PER_PAGE, $perPage));
        }

        if (isset($params['page']) && is_int($params['page'])) {
            $seed['page'] = max(1, $params['page']);
        }

        if ($filter !== []) {
            $seed['filter'] = $filter;
        }

        if (isset($params['search']) && is_string($params['search']) && $params['search'] !== '') {
            $seed['search'] = $params['search'];
        }

        if (isset($params['cursor']) && is_string($params['cursor']) && $params['cursor'] !== '') {
            $seed['cursor'] = $params['cursor'];
        }

        return $seed;
    }
}
