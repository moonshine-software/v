<?php

declare(strict_types=1);

namespace App\ApiAdmin\MCP\Tools;

use App\ApiAdmin\MCP\Generator\ResourceGenerator;
use App\ApiAdmin\MCP\Generator\ResourceValidator;
use App\ApiAdmin\MCP\ServiceRegistry;
use App\ApiAdmin\MCP\SpecStore\SpecStore;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * MCP Tool: add_field
 *
 * Insert a single field into an existing resource without re-listing the
 * full spec. Reads the spec sidecar (single source of truth), splices in
 * the new field at the requested position, and re-emits the resource via
 * ResourceGenerator — same code path as create_resource/update_resource,
 * so the generated PHP cannot diverge.
 *
 * Position is `before` / `after` / `index` (mutually exclusive). Defaults
 * to append. `pages` controls visibility — pass a subset of
 * `["index","form","detail"]` and the tool fills `hide_on` with the
 * complement.
 */
final class AddFieldTool implements ToolContract
{
    private const array PAGES = ['index', 'form', 'detail'];

    /**
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return [
            'name' => 'add_field',
            'description' => 'Generation changes are serialized and rolled back on failure. Insert a single field into an existing resource without re-emitting the full spec. Position via before/after/index (mutually exclusive). `pages` controls which pages render the new column — defaults to all three.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'service' => ['type' => 'string', 'description' => 'Registered service name'],
                    'resource' => ['type' => 'string', 'description' => 'Existing resource name (e.g. "post")'],
                    'field' => [
                        'type' => 'object',
                        'description' => 'Single field spec — same shape as one entry of create_resource.fields[]. Must include column + type.',
                        'properties' => [
                            'column' => ['type' => 'string', 'description' => 'API field name'],
                            'type' => ['type' => 'string', 'description' => 'Field type, including built-in relation types and custom types from list_fields'],
                            'label' => ['type' => 'string', 'description' => 'Display label'],
                            'methods' => ['description' => 'Same as create_resource.fields[].methods'],
                            'hide_on' => [
                                'type' => 'array',
                                'description' => 'Pages to omit this field from; normally use the add_field `pages` argument instead.',
                                'items' => ['type' => 'string', 'enum' => self::PAGES],
                            ],
                            'options' => ['type' => 'object', 'description' => 'Static options for select fields'],
                            'endpoint' => ['type' => 'string', 'description' => 'API endpoint for relation fields'],
                            'target_service' => [
                                'type' => 'string',
                                'description' => 'Target service name for cross-service relation fields. Omit for same-service relations.',
                            ],
                            'related_resource' => ['type' => 'string', 'description' => 'Existing generated REST resource name in target_service or the same service; inherits its response mapping. Generate the target first.'],
                            'value_key' => ['type' => 'string', 'description' => 'Value key for relation fields; inherits related_resource primary key, or id without a link. An explicit value overrides the inherited key.'],
                            'label_key' => ['type' => 'string', 'description' => 'Label key for relation fields (default: name)'],
                            'endpoint_query' => ['type' => 'object', 'description' => 'Static query params attached to this relation field calls'],
                            'depends_on' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                                'description' => 'Sibling columns that drive this relation field options',
                            ],
                        ],
                        'required' => ['column', 'type'],
                    ],
                    'position' => [
                        'type' => 'object',
                        'description' => 'Where to insert. Provide exactly ONE of before/after/index. Default: append to end.',
                        'properties' => [
                            'before' => ['type' => 'string', 'description' => 'Insert immediately before this column.'],
                            'after' => ['type' => 'string', 'description' => 'Insert immediately after this column.'],
                            'index' => ['type' => 'integer', 'description' => '0-based insertion index (clamped to [0, len]).'],
                        ],
                    ],
                    'pages' => [
                        'type' => 'array',
                        'description' => 'Subset of ["index","form","detail"] where the new field appears. Defaults to all three (full visibility). Translates to hide_on for unlisted pages.',
                        'items' => ['type' => 'string', 'enum' => self::PAGES],
                    ],
                ],
                'required' => ['service', 'resource', 'field'],
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
        $field = is_array($params['field'] ?? null) ? $params['field'] : null;

        if ($service === '' || $resource === '' || $field === null) {
            return [
                'success' => false,
                'error' => "Missing required 'service', 'resource' or 'field'",
                'code' => 'VALIDATION',
            ];
        }

        $column = is_string($field['column'] ?? null) ? $field['column'] : '';
        $type = is_string($field['type'] ?? null) ? $field['type'] : '';

        if ($column === '' || $type === '') {
            return [
                'success' => false,
                'error' => "Field must include non-empty 'column' and 'type'",
                'code' => 'VALIDATION',
            ];
        }

        if (config("api-admin.services.{$service}") === null) {
            return [
                'success' => false,
                'error' => "Service '{$service}' is not registered",
                'code' => 'SERVICE_NOT_REGISTERED',
                'hint' => 'Run list_services to see what exists, or register_service first.',
            ];
        }

        $match = (new ServiceRegistry)->findResource($service, $resource);

        if ($match === null) {
            return [
                'success' => false,
                'error' => "Resource '{$service}/{$resource}' does not exist",
                'code' => 'RESOURCE_NOT_FOUND',
                'hint' => 'Call list_resources({service:"'.$service.'"}) or use create_resource to create it.',
            ];
        }

        $spec = SpecStore::read($service, $resource);
        if ($spec === null) {
            return [
                'success' => false,
                'error' => "No spec sidecar for '{$service}/{$resource}'",
                'code' => 'SPEC_MISSING',
                'hint' => 'Resource was likely created before the sidecar feature shipped. Run update_resource once with the full current spec — the sidecar is then written for subsequent partial-delta calls.',
            ];
        }

        $fields = $this->normalizeFields($spec);

        if (SpecStore::fieldByColumn($spec, $column) !== null) {
            Log::warning('add_field: duplicate column', [
                'service' => $service,
                'resource' => $resource,
                'column' => $column,
            ]);

            return [
                'success' => false,
                'error' => "Field '{$column}' already exists on '{$service}/{$resource}'",
                'code' => 'FIELD_ALREADY_EXISTS',
                'hint' => 'Use rename_field to rename, remove_field to drop and re-add, or update_resource to replace the whole spec.',
            ];
        }

        $pagesError = $this->validatePages($params['pages'] ?? null);
        if (is_array($pagesError)) {
            return $pagesError;
        }

        /** @var list<string>|null $pagesVisible */
        $pagesVisible = is_array($params['pages'] ?? null) ? array_values(array_filter(
            $params['pages'],
            fn ($p): bool => is_string($p) && in_array($p, self::PAGES, true),
        )) : null;

        $insertResult = $this->resolveInsertIndex($fields, $params['position'] ?? null);
        if (! is_int($insertResult)) {
            return $insertResult;
        }
        $insertIndex = $insertResult;

        $fieldToInsert = $field;
        if ($pagesVisible !== null && count($pagesVisible) < count(self::PAGES)) {
            $hideOn = array_diff(self::PAGES, $pagesVisible)
                |> array_values(...);
            $fieldToInsert['hide_on'] = $hideOn;
        }

        $newFields = $fields;
        array_splice($newFields, $insertIndex, 0, [$fieldToInsert]);
        $spec['fields'] = $newFields;

        // If per-page overrides exist on the spec they REPLACE canonical
        // fields[] for that page (see ResourceGenerator::resolvePageFields).
        // We must mirror the insert into those overrides so the new column
        // actually appears — but only on pages where it should be visible.
        $visibleSet = $pagesVisible ?? self::PAGES;
        $overridesTouched = [];
        foreach (self::PAGES as $page) {
            $key = $page.'_fields';
            if (! is_array($spec[$key] ?? null)) {
                continue;
            }
            if (! in_array($page, $visibleSet, true)) {
                continue; // page hidden — leave override unchanged
            }
            $existing = [];
            foreach ($spec[$key] as $entry) {
                if (is_array($entry)) {
                    $existing[] = $entry;
                }
            }
            // Skip if column already in override (defensive, would have been
            // caught by the global FIELD_ALREADY_EXISTS check earlier).
            $alreadyPresent = array_any($existing, fn (array $entry): bool => ($entry['column'] ?? null) === $column);
            if ($alreadyPresent) {
                continue;
            }
            $existing[] = $field;
            $spec[$key] = $existing;
            $overridesTouched[] = $page;
        }

        $generatorParams = $spec;
        $generatorParams['service'] = $service;
        $generatorParams['name'] = $resource;
        $generatorParams['kind'] ??= $match['kind'] ?? 'main';
        $generatorParams['endpoint'] ??= $match['endpoint'] ?? '/'.$resource;

        Log::info('add_field: inserting', [
            'service' => $service,
            'resource' => $resource,
            'column' => $column,
            'index' => $insertIndex,
            'pages' => $pagesVisible,
        ]);

        $result = (new ResourceGenerator)->generate($generatorParams);

        try {
            Artisan::call('api-admin:reload');
        } catch (Throwable $e) {
            Log::warning('add_field: reload failed (non-blocking)', [
                'error' => $e->getMessage(),
            ]);
        }

        $validation = (new ResourceValidator)->validate($result);

        return [
            'success' => $validation['ok'],
            'service' => $service,
            'resource' => $resource,
            'column' => $column,
            'position' => ['index' => $insertIndex],
            'pages_visible' => $pagesVisible ?? self::PAGES,
            'page_overrides_touched' => $overridesTouched,
            'file' => $result['file'],
            'validation' => $validation,
            'message' => $validation['ok']
                ? "Field '{$column}' inserted at index {$insertIndex}."
                : 'Field inserted, but validation reported errors — see validation.issues.',
        ];
    }

    /**
     * Pull `fields[]` out of a spec into a normalised list of associative
     * arrays. Drops non-array entries defensively so downstream callers
     * can rely on the array<string, mixed> shape.
     *
     * @param array<string, mixed> $spec
     *
     * @return list<array<string, mixed>>
     */
    private function normalizeFields(array $spec): array
    {
        $raw = $spec['fields'] ?? null;
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            /** @var array<string, mixed> $typed */
            $typed = $entry;
            $out[] = $typed;
        }

        return $out;
    }

    /**
     * Resolve the 0-based insertion index from the user-supplied position
     * descriptor. Returns the index on success, or an error envelope.
     *
     * @param list<array<string, mixed>> $fields
     *
     * @return int|array<string, mixed>
     */
    private function resolveInsertIndex(array $fields, mixed $position): int|array
    {
        $count = count($fields);

        if ($position === null) {
            return $count;
        }

        if (! is_array($position)) {
            return [
                'success' => false,
                'error' => 'Position must be an object with one of: before, after, index',
                'code' => 'VALIDATION',
            ];
        }

        $before = $position['before'] ?? null;
        $after = $position['after'] ?? null;
        $index = $position['index'] ?? null;

        $provided = array_filter(
            ['before' => $before, 'after' => $after, 'index' => $index],
            fn ($v): bool => $v !== null,
        );

        if (count($provided) > 1) {
            return [
                'success' => false,
                'error' => 'Position keys before/after/index are mutually exclusive — provide exactly one.',
                'code' => 'VALIDATION',
            ];
        }

        if (count($provided) === 0) {
            return $count;
        }

        if (is_string($before) && $before !== '') {
            $idx = $this->columnIndex($fields, $before);
            if ($idx === null) {
                return $this->invalidPosition($before, 'before');
            }

            return $idx;
        }

        if (is_string($after) && $after !== '') {
            $idx = $this->columnIndex($fields, $after);
            if ($idx === null) {
                return $this->invalidPosition($after, 'after');
            }

            return $idx + 1;
        }

        if (is_int($index)) {
            if ($index < 0 || $index > $count) {
                return [
                    'success' => false,
                    'error' => "Position index {$index} out of bounds for {$count} fields.",
                    'code' => 'INVALID_POSITION',
                    'hint' => 'Use 0..'.$count.' or omit position to append.',
                ];
            }

            return $index;
        }

        return [
            'success' => false,
            'error' => 'Position must specify before:string, after:string, or index:int.',
            'code' => 'VALIDATION',
        ];
    }

    /**
     * @param list<array<string, mixed>> $fields
     */
    private function columnIndex(array $fields, string $column): ?int
    {
        foreach ($fields as $i => $field) {
            if (($field['column'] ?? null) === $column) {
                return $i;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function invalidPosition(string $column, string $key): array
    {
        return [
            'success' => false,
            'error' => "Position {$key}:'{$column}' references a column that does not exist on the resource.",
            'code' => 'INVALID_POSITION',
            'hint' => 'Run get_resource to see the current column list.',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function validatePages(mixed $pages): ?array
    {
        if ($pages === null) {
            return null;
        }

        if (! is_array($pages)) {
            return [
                'success' => false,
                'error' => "'pages' must be an array of strings (subset of index/form/detail).",
                'code' => 'VALIDATION',
            ];
        }

        if ($pages === []) {
            return [
                'success' => false,
                'error' => "'pages' must contain at least one of index/form/detail. Use remove_field to fully hide a column.",
                'code' => 'VALIDATION',
            ];
        }

        foreach ($pages as $page) {
            if (! is_string($page) || ! in_array($page, self::PAGES, true)) {
                return [
                    'success' => false,
                    'error' => "Unknown page value in 'pages'. Allowed: index, form, detail.",
                    'code' => 'VALIDATION',
                ];
            }
        }

        return null;
    }
}
