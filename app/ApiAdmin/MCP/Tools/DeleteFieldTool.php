<?php

declare(strict_types=1);

namespace App\ApiAdmin\MCP\Tools;

use App\ApiAdmin\MCP\Generator\CustomFieldGenerator;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * MCP Tool: delete_field
 *
 * Destructive — requires `confirm: true`. Refuses to drop a field that
 * is referenced by any existing resource spec sidecar unless `force`
 * is set. Dependents list scans `.ai-factory/specs/**\/*.json` fields[]
 * for `type: <field-name>` so the agent gets a concrete "X is used by
 * Y,Z — fix those first" response.
 */
final class DeleteFieldTool implements ToolContract
{
    /**
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return [
            'name' => 'delete_field',
            'description' => 'Generation changes are serialized and rolled back on failure. Available only in trusted local mode; hosted calls are rejected even with wildcard token scopes. Remove a custom MoonShine field. Destructive — requires `confirm: true`. Rejects with FIELD_IN_USE when any resource spec references it; `force: true` overrides.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string'],
                    'confirm' => ['type' => 'boolean'],
                    'force' => ['type' => 'boolean', 'description' => 'Override FIELD_IN_USE. Orphans every dependent resource spec — caller is responsible for regeneration.'],
                ],
                'required' => ['name', 'confirm'],
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
        $name = isset($params['name']) && is_string($params['name']) ? $params['name'] : '';
        $confirm = $params['confirm'] ?? null;
        $force = isset($params['force']) && $params['force'] === true;

        if ($name === '') {
            return ['success' => false, 'code' => 'VALIDATION', 'error' => "Missing required 'name'"];
        }

        if ($confirm !== true) {
            return [
                'success' => false,
                'code' => 'CONFIRM_REQUIRED',
                'error' => 'Destructive operation requires `confirm: true`.',
            ];
        }

        $typeKey = Str::snake($name);
        $dependents = $this->scanDependents($typeKey);

        if ($dependents !== [] && ! $force) {
            return [
                'success' => false,
                'code' => 'FIELD_IN_USE',
                'error' => "Custom field '{$typeKey}' is referenced by ".count($dependents).' resource spec(s).',
                'dependents' => $dependents,
                'hint' => 'Re-run with `force: true` to proceed — but dependent resources will need regeneration.',
            ];
        }

        $existed = (new CustomFieldGenerator)->delete($name);

        if (! $existed) {
            return [
                'success' => false,
                'code' => 'FIELD_NOT_FOUND',
                'error' => "Custom field '{$name}' does not exist.",
            ];
        }

        Log::info('DeleteFieldTool: deleted', ['name' => $name, 'force' => $force]);

        return [
            'success' => true,
            'deleted' => $name,
            'type' => $typeKey,
            'forced' => $force,
            'dependents_at_delete' => $dependents,
        ];
    }

    /**
     * Scan every .ai-factory/specs/<service>/<resource>.json and return
     * {service, resource, columns: [...]} entries whose fields[].type
     * matches the field being deleted.
     *
     * @return list<array{service: string, resource: string, columns: list<string>}>
     */
    private function scanDependents(string $typeKey): array
    {
        $root = $this->specsRoot();
        if (! File::isDirectory($root)) {
            return [];
        }

        $out = [];
        foreach (File::directories($root) as $serviceDir) {
            if (! is_string($serviceDir)) {
                continue;
            }
            $service = basename($serviceDir);
            foreach (File::files($serviceDir) as $specFile) {
                if ($specFile->getExtension() !== 'json') {
                    continue;
                }
                $resource = $specFile->getFilenameWithoutExtension();
                /** @var mixed $decoded */
                $decoded = json_decode(File::get($specFile->getPathname()), associative: true);
                if (! is_array($decoded)) {
                    continue;
                }

                /** @var array<string, mixed> $spec */
                $spec = $decoded;
                $cols = $this->collectMatchingColumns($spec, $typeKey);
                if ($cols !== []) {
                    $out[] = ['service' => $service, 'resource' => $resource, 'columns' => $cols];
                }
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $spec
     *
     * @return list<string>
     */
    private function collectMatchingColumns(array $spec, string $typeKey): array
    {
        $cols = [];
        $fields = $spec['fields'] ?? null;
        if (! is_array($fields)) {
            return [];
        }

        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }
            if (($field['type'] ?? null) === $typeKey
                && is_string($field['column'] ?? null)
                && $field['column'] !== ''
            ) {
                $cols[] = $field['column'];
            }

            // Descend into has_many/has_one sub-fields too — a sub-field
            // can reference the custom type just like a top-level field.
            if (isset($field['fields']) && is_array($field['fields'])) {
                $subCols = $this->collectMatchingColumns(['fields' => $field['fields']], $typeKey);
                $parentCol = is_string($field['column'] ?? null) ? $field['column'] : '';
                foreach ($subCols as $sc) {
                    $cols[] = $parentCol.'.'.$sc;
                }
            }
        }

        return $cols;
    }

    private function specsRoot(): string
    {
        $override = config('api-admin.specs_path');

        return is_string($override) && $override !== ''
            ? rtrim($override, '/')
            : base_path('.ai-factory/specs');
    }
}
