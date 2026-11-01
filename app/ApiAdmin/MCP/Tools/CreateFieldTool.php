<?php

declare(strict_types=1);

namespace App\ApiAdmin\MCP\Tools;

use App\ApiAdmin\MCP\Generator\CustomFieldGenerator;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * MCP Tool: create_field
 *
 * Scaffold a caller-defined custom MoonShine field under
 * `app/ApiAdmin/Fields/Custom/<Name>.php` and register it in
 * `api-admin.custom_fields` so `list_fields` surfaces it and
 * `create_resource` accepts the new `type` key.
 */
final class CreateFieldTool implements ToolContract
{
    /**
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return [
            'name' => 'create_field',
            'description' => 'Generation changes are serialized and rolled back on failure. Available only in trusted local mode; hosted calls are rejected even with wildcard token scopes. Generate a custom MoonShine field class extending a built-in type. Writes the PHP file, registers the new `type` in api-admin.custom_fields, and returns the type key to use in fields[].type.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string', 'description' => 'StudlyCase class name (e.g. "MoneyInput"). The type key is the snake_case form.'],
                    'extends' => ['type' => 'string', 'description' => 'Parent field type — either a built-in key (text, select, password, …) or a fully-qualified class-string.'],
                    'alias' => ['type' => 'string', 'description' => 'Optional PHP use-statement alias. Defaults to the class name.'],
                    'methods_override' => [
                        'type' => 'array',
                        'description' => 'Optional list of method bodies to override on the parent. Each entry: {name, body, return?}.',
                        'items' => ['type' => 'object'],
                    ],
                    'properties' => [
                        'type' => 'array',
                        'description' => 'Optional protected properties to emit on the class. Each entry: {name, type, default?}.',
                        'items' => ['type' => 'object'],
                    ],
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

        Log::info('CreateFieldTool: executing', ['name' => $name, 'extends' => $extends]);

        $generatorParams = [
            'name' => $name,
            'extends' => $extends,
        ];

        if (isset($params['alias']) && is_string($params['alias'])) {
            $generatorParams['alias'] = $params['alias'];
        }
        if (isset($params['methods_override']) && is_array($params['methods_override'])) {
            /** @var list<array{name: string, body: string, return?: string}> $methods */
            $methods = array_filter($params['methods_override'], is_array(...))
                |> array_values(...);
            $generatorParams['methods_override'] = $methods;
        }
        if (isset($params['properties']) && is_array($params['properties'])) {
            /** @var list<array{name: string, type: string, default?: string}> $props */
            $props = array_filter($params['properties'], is_array(...))
                |> array_values(...);
            $generatorParams['properties'] = $props;
        }

        try {
            /** @var array{
             *     name: string,
             *     extends: string,
             *     alias?: string,
             *     methods_override?: list<array{name: string, body: string, return?: string}>,
             *     properties?: list<array{name: string, type: string, default?: string}>,
             * } $typedParams */
            $typedParams = $generatorParams;
            $result = (new CustomFieldGenerator)->generate($typedParams);
        } catch (RuntimeException $e) {
            return [
                'success' => false,
                'code' => 'GENERATOR_ERROR',
                'error' => $e->getMessage(),
            ];
        } catch (Throwable $e) {
            Log::error('CreateFieldTool: unexpected error', ['error' => $e->getMessage()]);

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
            'hint' => 'Use `type: '.$result['type'].'` in create_resource fields[].type. If hot-reload is needed in a long-running process, run `composer dump-autoload` + `config:clear` on the host.',
        ];
    }
}
