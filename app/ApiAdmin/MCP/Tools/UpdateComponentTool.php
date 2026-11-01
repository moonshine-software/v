<?php

declare(strict_types=1);

namespace App\ApiAdmin\MCP\Tools;

use App\ApiAdmin\MCP\Generator\CustomComponentGenerator;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * MCP Tool: update_component
 *
 * Rewrite an existing custom component wholesale. Contract mirrors
 * update_field — full replacement; refuses if the component does not
 * already exist.
 */
final class UpdateComponentTool implements ToolContract
{
    /**
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return [
            'name' => 'update_component',
            'description' => 'Generation changes are serialized and rolled back on failure. Available only in trusted local mode; hosted calls are rejected even with wildcard token scopes. Rewrite an existing custom MoonShine component. Input shape matches create_component. Returns COMPONENT_NOT_FOUND if no prior generation exists.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string'],
                    'extends' => ['type' => 'string'],
                    'view_content' => ['type' => 'string'],
                    'properties' => ['type' => 'array', 'items' => ['type' => 'object']],
                ],
                'required' => ['name'],
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
        if ($name === '') {
            return ['success' => false, 'code' => 'VALIDATION', 'error' => "Missing required 'name'"];
        }

        $file = base_path('app/ApiAdmin/Components/Custom/'.Str::studly($name).'.php');
        if (! File::exists($file)) {
            return [
                'success' => false,
                'code' => 'COMPONENT_NOT_FOUND',
                'error' => "Custom component '{$name}' does not exist.",
                'hint' => 'Use create_component to generate a new custom component.',
            ];
        }

        Log::info('UpdateComponentTool: rewriting', ['name' => $name]);

        $generatorParams = ['name' => $name];
        if (isset($params['extends']) && is_string($params['extends'])) {
            $generatorParams['extends'] = $params['extends'];
        }
        if (isset($params['view_content']) && is_string($params['view_content'])) {
            $generatorParams['view_content'] = $params['view_content'];
        }
        if (isset($params['properties']) && is_array($params['properties'])) {
            $generatorParams['properties'] = array_filter($params['properties'], is_array(...))
                |> array_values(...);
        }

        try {
            /** @var array{
             *     name: string,
             *     extends?: string,
             *     view_content?: string,
             *     properties?: list<array{name: string, type: string, default?: string}>,
             * } $typedParams */
            $typedParams = $generatorParams;
            $result = (new CustomComponentGenerator)->generate($typedParams);
        } catch (RuntimeException $e) {
            return ['success' => false, 'code' => 'GENERATOR_ERROR', 'error' => $e->getMessage()];
        } catch (Throwable $e) {
            Log::error('UpdateComponentTool: unexpected error', ['error' => $e->getMessage()]);

            return ['success' => false, 'code' => 'INTERNAL_ERROR', 'error' => $e->getMessage()];
        }

        return [
            'success' => true,
            'file' => $result['file'],
            'view' => $result['view'],
            'class' => $result['class'],
            'name' => $name,
            'rewritten' => true,
        ];
    }
}
