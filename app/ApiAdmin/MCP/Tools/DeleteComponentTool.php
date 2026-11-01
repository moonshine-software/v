<?php

declare(strict_types=1);

namespace App\ApiAdmin\MCP\Tools;

use App\ApiAdmin\MCP\Generator\CustomComponentGenerator;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * MCP Tool: delete_component
 *
 * Destructive — requires `confirm: true`. Scans every resource spec
 * sidecar's `layout.form.tree` for nodes whose `type` matches the
 * component name; rejects with COMPONENT_IN_USE unless `force: true`.
 */
final class DeleteComponentTool implements ToolContract
{
    /**
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return [
            'name' => 'delete_component',
            'description' => 'Generation changes are serialized and rolled back on failure. Available only in trusted local mode; hosted calls are rejected even with wildcard token scopes. Remove a custom MoonShine component. Destructive — requires `confirm: true`. Rejects with COMPONENT_IN_USE when any resource layout tree references it; `force: true` overrides.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string'],
                    'confirm' => ['type' => 'boolean'],
                    'force' => ['type' => 'boolean'],
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

        $dependents = $this->scanDependents($name);
        if ($dependents !== [] && ! $force) {
            return [
                'success' => false,
                'code' => 'COMPONENT_IN_USE',
                'error' => "Custom component '{$name}' is referenced by ".count($dependents).' resource spec(s).',
                'dependents' => $dependents,
                'hint' => 'Re-run with `force: true` to proceed — but dependent layouts will orphan the reference.',
            ];
        }

        $existed = (new CustomComponentGenerator)->delete($name);
        if (! $existed) {
            return [
                'success' => false,
                'code' => 'COMPONENT_NOT_FOUND',
                'error' => "Custom component '{$name}' does not exist.",
            ];
        }

        Log::info('DeleteComponentTool: deleted', ['name' => $name, 'force' => $force]);

        return [
            'success' => true,
            'deleted' => $name,
            'forced' => $force,
            'dependents_at_delete' => $dependents,
        ];
    }

    /**
     * Walk `layout.form.tree` recursively in every spec sidecar looking
     * for nodes whose `type` matches the component name.
     *
     * @return list<array{service: string, resource: string}>
     */
    private function scanDependents(string $name): array
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
                /** @var mixed $decoded */
                $decoded = json_decode(File::get($specFile->getPathname()), associative: true);
                if (! is_array($decoded)) {
                    continue;
                }

                $layout = $decoded['layout'] ?? null;
                if (! is_array($layout)) {
                    continue;
                }
                $form = $layout['form'] ?? null;
                if (! is_array($form)) {
                    continue;
                }
                $tree = $form['tree'] ?? null;
                if (! is_array($tree)) {
                    continue;
                }

                /** @var array<string, mixed> $treeTyped */
                $treeTyped = $tree;
                if ($this->treeReferencesComponent($treeTyped, $name)) {
                    $out[] = ['service' => $service, 'resource' => $specFile->getFilenameWithoutExtension()];
                }
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function treeReferencesComponent(array $node, string $name): bool
    {
        if (isset($node['type']) && $node['type'] === $name) {
            return true;
        }
        if (isset($node['children']) && is_array($node['children'])) {
            foreach ($node['children'] as $child) {
                if (! is_array($child)) {
                    continue;
                }
                /** @var array<string, mixed> $childTyped */
                $childTyped = $child;
                if ($this->treeReferencesComponent($childTyped, $name)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function specsRoot(): string
    {
        $override = config('api-admin.specs_path');

        return is_string($override) && $override !== ''
            ? rtrim($override, '/')
            : base_path('.ai-factory/specs');
    }
}
