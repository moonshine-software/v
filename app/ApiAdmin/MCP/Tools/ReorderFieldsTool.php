<?php

declare(strict_types=1);

namespace App\ApiAdmin\MCP\Tools;

use App\ApiAdmin\MCP\Generator\ResourceGenerator;
use App\ApiAdmin\MCP\Generator\ResourceValidator;
use App\ApiAdmin\MCP\ServiceRegistry;
use App\ApiAdmin\MCP\SpecStore\SpecStore;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * MCP Tool: reorder_fields
 *
 * Reorder a resource's `fields[]` without re-listing the full spec.
 * `order` is a partial-by-default list — entries appear in the given
 * order, unlisted columns retain relative order and are appended after
 * the listed ones. Set `strict: true` to require an exhaustive `order`
 * (every column must appear); missing entries → INCOMPLETE_ORDER.
 *
 * Layout tree is NOT touched — author-controlled. Reordering visual
 * groups is a separate intent that belongs in update_resource.
 */
final class ReorderFieldsTool implements ToolContract
{
    /**
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return [
            'name' => 'reorder_fields',
            'description' => 'Generation changes are serialized and rolled back on failure. Reorder fields[] on an existing resource. `order` is partial by default — listed columns come in the given order, unlisted retain relative order and append after. Set strict:true to require an exhaustive list.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'service' => ['type' => 'string'],
                    'resource' => ['type' => 'string'],
                    'order' => [
                        'type' => 'array',
                        'description' => 'Desired column order (partial subset by default).',
                        'items' => ['type' => 'string'],
                    ],
                    'strict' => [
                        'type' => 'boolean',
                        'description' => 'Require order[] to enumerate every existing column. Default false.',
                    ],
                ],
                'required' => ['service', 'resource', 'order'],
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
        $service = is_string($params['service'] ?? null) ? $params['service'] : '';
        $resource = is_string($params['resource'] ?? null) ? $params['resource'] : '';
        $orderRaw = $params['order'] ?? null;
        $strict = (bool) ($params['strict'] ?? false);

        if ($service === '' || $resource === '' || ! is_array($orderRaw)) {
            return [
                'success' => false,
                'error' => "Missing required 'service', 'resource' or 'order'",
                'code' => 'VALIDATION',
            ];
        }

        $order = [];
        foreach ($orderRaw as $entry) {
            if (! is_string($entry) || $entry === '') {
                return [
                    'success' => false,
                    'error' => "'order' must contain only non-empty strings.",
                    'code' => 'VALIDATION',
                ];
            }
            $order[] = $entry;
        }

        if (count($order) !== count(array_unique($order))) {
            return [
                'success' => false,
                'error' => "'order' contains duplicate columns.",
                'code' => 'VALIDATION',
            ];
        }

        if (config("api-admin.services.{$service}") === null) {
            return [
                'success' => false,
                'error' => "Service '{$service}' is not registered",
                'code' => 'SERVICE_NOT_REGISTERED',
            ];
        }

        $registry = new ServiceRegistry;
        $resources = $registry->listResources($service);
        $match = array_find(
            $resources,
            fn ($r): bool => $r['name'] === Str::snake($resource)
                || $r['base_name'] === Str::studly(Str::singular($resource)),
        );

        if ($match === null) {
            return [
                'success' => false,
                'error' => "Resource '{$service}/{$resource}' does not exist",
                'code' => 'RESOURCE_NOT_FOUND',
            ];
        }

        $spec = SpecStore::read($service, $resource);
        if ($spec === null) {
            return [
                'success' => false,
                'error' => "No spec sidecar for '{$service}/{$resource}'",
                'code' => 'SPEC_MISSING',
                'hint' => 'Run update_resource once with the full current spec to seed the sidecar.',
            ];
        }

        /** @var list<array{column: string} & array<string, mixed>> $fields */
        $fields = [];
        $existingColumns = [];
        $byColumn = [];
        if (is_array($spec['fields'] ?? null)) {
            foreach ($spec['fields'] as $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                $col = $entry['column'] ?? null;
                if (! is_string($col)) {
                    continue;
                }
                if ($col === '') {
                    continue;
                }
                /** @var array{column: string} & array<string, mixed> $typed */
                $typed = $entry;
                $fields[] = $typed;
                $existingColumns[] = $col;
                $byColumn[$col] = $typed;
            }
        }

        $before = $existingColumns;

        foreach ($order as $col) {
            if (! in_array($col, $existingColumns, true)) {
                return [
                    'success' => false,
                    'error' => "Column '{$col}' in 'order' does not exist on '{$service}/{$resource}'.",
                    'code' => 'FIELD_NOT_FOUND',
                ];
            }
        }

        if ($strict) {
            $missing = array_diff($existingColumns, $order)
                |> array_values(...);
            if ($missing !== []) {
                return [
                    'success' => false,
                    'error' => 'strict:true requires order[] to enumerate every column. Missing: '.implode(', ', $missing),
                    'code' => 'INCOMPLETE_ORDER',
                    'missing' => $missing,
                ];
            }
        }

        $sortedColumns = $order;
        foreach ($existingColumns as $col) {
            if (! in_array($col, $sortedColumns, true)) {
                $sortedColumns[] = $col;
            }
        }

        $sortedFields = [];
        foreach ($sortedColumns as $col) {
            $sortedFields[] = $byColumn[$col];
        }

        $spec['fields'] = $sortedFields;

        $generatorParams = $spec;
        $generatorParams['service'] = $service;
        $generatorParams['name'] = $resource;
        $generatorParams['kind'] ??= $match['kind'] ?? 'main';
        $generatorParams['endpoint'] ??= $match['endpoint'] ?? '/'.$resource;

        Log::info('reorder_fields: applying', [
            'service' => $service,
            'resource' => $resource,
            'before' => $before,
            'after' => $sortedColumns,
        ]);

        $result = (new ResourceGenerator)->generate($generatorParams);

        try {
            Artisan::call('api-admin:reload');
        } catch (Throwable $e) {
            Log::warning('reorder_fields: reload failed (non-blocking)', [
                'error' => $e->getMessage(),
            ]);
        }

        $validation = (new ResourceValidator)->validate($result);

        return [
            'success' => $validation['ok'],
            'service' => $service,
            'resource' => $resource,
            'before' => $before,
            'after' => $sortedColumns,
            'file' => $result['file'],
            'validation' => $validation,
            'message' => $validation['ok']
                ? 'Fields reordered.'
                : 'Fields reordered, but validation reported errors — see validation.issues.',
        ];
    }
}
