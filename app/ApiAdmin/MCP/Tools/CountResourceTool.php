<?php

declare(strict_types=1);

namespace App\ApiAdmin\MCP\Tools;

use App\ApiAdmin\MCP\Analytics\AnalyticsQueryBuilder;
use Illuminate\Support\Facades\Log;

/**
 * MCP Tool: count_resource
 *
 * Counts records for a generated REST resource through the same resource
 * layer the admin UI uses. Prefer upstream paginator totals when available;
 * otherwise count the materialized page and mark the result as partial when
 * pagination says more rows exist.
 */
final class CountResourceTool implements ToolContract
{
    /**
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return [
            'name' => 'count_resource',
            'description' => 'Count items for a registered REST resource. Honors auth, response mapping, default_query, filter/search param names, relation parent_context, and time_window filters.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'service' => ['type' => 'string', 'description' => 'Registered service name.'],
                    'resource' => ['type' => 'string', 'description' => 'Resource name in snake_case.'],
                    'parent_context' => [
                        'type' => 'object',
                        'description' => 'Required when the resource endpoint has {placeholders}.',
                    ],
                    'filter' => ['type' => 'object', 'description' => 'Filter map routed through the service response_mapping filter format.'],
                    'search' => ['type' => 'string', 'description' => 'Free-text search term routed through search_param_name.'],
                    'time_window' => [
                        'type' => 'object',
                        'description' => 'Time filter shape: {"field": "created_at", "from": "2026-01-01", "to": "2026-01-31"}. At least one boundary is required.',
                    ],
                    'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500, 'description' => 'Rows to materialize if the upstream does not return total metadata.'],
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

        Log::info('CountResourceTool: executing', [
            'service' => $service,
            'resource' => $resourceName,
        ]);

        $query = (new AnalyticsQueryBuilder)->fetch($params);
        if (($query['success'] ?? false) !== true) {
            return $query;
        }

        $data = $query['data'] ?? [];
        $data = is_array($data) ? $data : [];
        $items = isset($data['items']) && is_array($data['items']) ? $data['items'] : [];
        $pagination = isset($data['pagination']) && is_array($data['pagination']) ? $data['pagination'] : [];

        $total = $pagination['total'] ?? null;
        $strategy = is_string($pagination['strategy'] ?? null) ? $pagination['strategy'] : 'unknown';
        $hasUpstreamTotal = is_int($total) && $strategy !== 'none';
        $count = $hasUpstreamTotal ? $total : count($items);
        $source = $hasUpstreamTotal ? 'upstream_total' : 'materialized_items';
        $hasMore = ($pagination['has_more'] ?? false) === true;

        Log::debug('CountResourceTool: count resolved', [
            'service' => $query['service'] ?? $service,
            'resource' => $query['resource'] ?? $resourceName,
            'pagination_strategy' => $strategy,
            'source' => $source,
            'count' => $count,
            'has_more' => $hasMore,
        ]);

        $out = [
            'success' => true,
            'service' => $query['service'] ?? $service,
            'resource' => $query['resource'] ?? $resourceName,
            'endpoint' => $query['endpoint'] ?? '',
            'count' => $count,
            'source' => $source,
            'applied_filters' => $query['applied_filters'] ?? [],
        ];

        if ($hasMore && ! $hasUpstreamTotal) {
            Log::warning('CountResourceTool: count is partial', [
                'service' => $query['service'] ?? $service,
                'resource' => $query['resource'] ?? $resourceName,
                'materialized_count' => $count,
            ]);

            $out['warning'] = 'PARTIAL_COUNT';
            $out['hint'] = 'Upstream response did not include total metadata and indicated more pages; count reflects only the materialized page.';
        }

        if (($query['ignored_parent_context'] ?? false) === true) {
            $out['ignored_parent_context'] = true;
        }

        return $out;
    }
}
