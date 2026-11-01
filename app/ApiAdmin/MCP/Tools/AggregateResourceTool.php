<?php

declare(strict_types=1);

namespace App\ApiAdmin\MCP\Tools;

use App\ApiAdmin\MCP\Analytics\AggregateCalculator;
use App\ApiAdmin\MCP\Analytics\AnalyticsQueryBuilder;
use Illuminate\Support\Facades\Log;

/**
 * MCP Tool: aggregate_resource
 *
 * Runs simple aggregate operations over items fetched through the generated
 * resource layer. This keeps auth, mapping, filters, default_query and
 * relation endpoint handling consistent with fetch_resource_items.
 */
final class AggregateResourceTool implements ToolContract
{
    /**
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return [
            'name' => 'aggregate_resource',
            'description' => 'Aggregate items for a registered REST resource with count/sum/avg/min/max, optional group_by, filters, search, parent_context, and time_window.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'service' => ['type' => 'string', 'description' => 'Registered service name.'],
                    'resource' => ['type' => 'string', 'description' => 'Resource name in snake_case.'],
                    'operation' => ['type' => 'string', 'enum' => ['count', 'sum', 'avg', 'min', 'max'], 'description' => 'Aggregate operation. Numeric operations require field.'],
                    'field' => ['type' => 'string', 'description' => 'Numeric field for sum/avg/min/max. Optional for count. Dot paths are allowed.'],
                    'group_by' => ['type' => 'string', 'description' => 'Optional field to group by. Dot paths are allowed.'],
                    'parent_context' => ['type' => 'object', 'description' => 'Required when the resource endpoint has {placeholders}.'],
                    'filter' => ['type' => 'object', 'description' => 'Filter map routed through response_mapping filter format.'],
                    'search' => ['type' => 'string', 'description' => 'Free-text search term routed through search_param_name.'],
                    'time_window' => [
                        'type' => 'object',
                        'description' => 'Time filter shape: {"field": "created_at", "from": "2026-01-01", "to": "2026-01-31"}.',
                    ],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500, 'description' => 'Rows to materialize for aggregation when upstream pagination is page-based.'],
                    'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500, 'description' => 'Alias for limit.'],
                ],
                'required' => ['service', 'resource', 'operation'],
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
        $operation = isset($params['operation']) && is_string($params['operation']) ? $params['operation'] : '';
        $field = isset($params['field']) && is_string($params['field']) ? $params['field'] : null;
        $groupBy = isset($params['group_by']) && is_string($params['group_by']) ? $params['group_by'] : null;

        Log::info('AggregateResourceTool: executing', [
            'service' => $service,
            'resource' => $resourceName,
            'operation' => $operation,
            'field' => $field,
            'group_by' => $groupBy,
        ]);

        if ($operation === '') {
            return [
                'success' => false,
                'code' => 'VALIDATION',
                'error' => "Missing required 'operation'",
                'hint' => 'Use one of: count, sum, avg, min, max.',
            ];
        }

        $query = (new AnalyticsQueryBuilder)->fetch($params);
        if (($query['success'] ?? false) !== true) {
            return $query;
        }

        $data = $query['data'] ?? [];
        $data = is_array($data) ? $data : [];
        $itemsRaw = isset($data['items']) && is_array($data['items']) ? $data['items'] : [];
        $items = [];
        foreach ($itemsRaw as $item) {
            if (is_array($item)) {
                /** @var array<string, mixed> $item */
                $items[] = $item;
            }
        }

        Log::debug('AggregateResourceTool: items materialized', [
            'service' => $query['service'] ?? $service,
            'resource' => $query['resource'] ?? $resourceName,
            'endpoint' => $query['endpoint'] ?? '',
            'item_count' => count($items),
        ]);

        $calculated = (new AggregateCalculator)->calculate($items, $operation, $field, $groupBy);
        if (($calculated['success'] ?? false) !== true) {
            return $calculated;
        }

        $pagination = isset($data['pagination']) && is_array($data['pagination']) ? $data['pagination'] : [];
        $hasMore = ($pagination['has_more'] ?? false) === true;

        $out = [
            'success' => true,
            'service' => $query['service'] ?? $service,
            'resource' => $query['resource'] ?? $resourceName,
            'endpoint' => $query['endpoint'] ?? '',
            'operation' => strtolower($operation),
            'field' => $field,
            'group_by' => $groupBy,
            'applied_filters' => $query['applied_filters'] ?? [],
        ];

        if (isset($calculated['groups']) && is_array($calculated['groups'])) {
            $out['groups'] = $calculated['groups'];
        } else {
            $out['result'] = $calculated['result'] ?? null;
            $out['count'] = $calculated['count'] ?? count($items);
            $out['skipped_non_numeric'] = $calculated['skipped_non_numeric'] ?? 0;
        }

        if ($hasMore) {
            Log::warning('AggregateResourceTool: aggregate may be partial', [
                'service' => $query['service'] ?? $service,
                'resource' => $query['resource'] ?? $resourceName,
                'materialized_count' => count($items),
            ]);

            $out['warning'] = 'PARTIAL_AGGREGATE';
            $out['hint'] = 'Upstream indicated more pages; aggregate reflects only the materialized page. Increase limit/per_page if appropriate.';
        }

        if (($query['ignored_parent_context'] ?? false) === true) {
            $out['ignored_parent_context'] = true;
        }

        return $out;
    }
}
