<?php

declare(strict_types=1);

namespace App\ApiAdmin\MCP\Tools;

use App\ApiAdmin\MCP\Generator\CustomFieldGenerator;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * MCP Tool: update_field
 *
 * Rewrite an existing custom field class wholesale. Refuses if the
 * field does not exist (the caller probably meant `create_field`).
 * Contract mirrors update_resource — full replacement, no merge.
 */
final class UpdateFieldTool implements ToolContract
{
    /**
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return [
            'name' => 'update_field',
            'description' => 'Generation changes are serialized and rolled back on failure. Available only in trusted local mode; hosted calls are rejected even with wildcard token scopes. Rewrite an existing custom MoonShine field. Input shape matches create_field. Returns FIELD_NOT_FOUND if no prior generation exists.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string'],
                    'extends' => ['type' => 'string'],
                    'alias' => ['type' => 'string'],
                    'methods_override' => ['type' => 'array', 'items' => ['type' => 'object']],
                    'properties' => ['type' => 'array', 'items' => ['type' => 'object']],
                ],
                'required' => ['name', 'extends'],
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
        $extends = isset($params['extends']) && is_string($params['extends']) ? $params['extends'] : '';
        if ($name === '' || $extends === '') {
            return [
                'success' => false,
                'code' => 'VALIDATION',
                'error' => "Missing required 'name' or 'extends'",
            ];
        }

        $file = base_path('app/ApiAdmin/Fields/Custom/'.Str::studly($name).'.php');
        if (! File::exists($file)) {
            return [
                'success' => false,
                'code' => 'FIELD_NOT_FOUND',
                'error' => "Custom field '{$name}' does not exist.",
                'hint' => 'Use create_field to generate a new custom field.',
            ];
        }

        Log::info('UpdateFieldTool: rewriting', ['name' => $name]);

        /** @var array{
         *     name: string,
         *     extends: string,
         *     alias?: string,
         *     methods_override?: list<array{name: string, body: string, return?: string}>,
         *     properties?: list<array{name: string, type: string, default?: string}>,
         * } $typedParams */
        $typedParams = $this->extractGeneratorParams($params);

        try {
            $result = (new CustomFieldGenerator)->generate($typedParams);
        } catch (RuntimeException $e) {
            return [
                'success' => false,
                'code' => 'GENERATOR_ERROR',
                'error' => $e->getMessage(),
            ];
        } catch (Throwable $e) {
            Log::error('UpdateFieldTool: unexpected error', ['error' => $e->getMessage()]);

            return [
                'success' => false,
                'code' => 'INTERNAL_ERROR',
                'error' => $e->getMessage(),
            ];
        }

        return [
            'success' => true,
            'file' => $result['file'],
            'class' => $result['class'],
            'alias' => $result['alias'],
            'type' => $result['type'],
            'rewritten' => true,
        ];
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function extractGeneratorParams(array $params): array
    {
        $out = [
            'name' => is_string($params['name'] ?? null) ? $params['name'] : '',
            'extends' => is_string($params['extends'] ?? null) ? $params['extends'] : '',
        ];

        if (isset($params['alias']) && is_string($params['alias'])) {
            $out['alias'] = $params['alias'];
        }
        if (isset($params['methods_override']) && is_array($params['methods_override'])) {
            $out['methods_override'] = array_filter($params['methods_override'], is_array(...))
                |> array_values(...);
        }
        if (isset($params['properties']) && is_array($params['properties'])) {
            $out['properties'] = array_filter($params['properties'], is_array(...))
                |> array_values(...);
        }

        return $out;
    }
}
