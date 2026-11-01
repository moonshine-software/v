<?php

declare(strict_types=1);

namespace App\ApiAdmin\MCP\Tools;

use App\ApiAdmin\MCP\Generator\CustomComponentGenerator;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * MCP Tool: create_component
 *
 * Scaffold a caller-defined MoonShine component under
 * `app/ApiAdmin/Components/Custom/<Name>.php` + paired blade view,
 * and register the result in `api-admin.custom_components` so
 * `list_components` picks it up and `layout.form.tree` can reference
 * it.
 */
final class CreateComponentTool implements ToolContract
{
    /**
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return [
            'name' => 'create_component',
            'description' => 'Generation changes are serialized and rolled back on failure. Available only in trusted local mode; hosted calls are rejected even with wildcard token scopes. Generate a custom MoonShine component (class + blade view). Registers it in api-admin.custom_components so list_components surfaces it for layout.form.tree use.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string', 'description' => 'StudlyCase component name (e.g. "InfoBanner").'],
                    'extends' => ['type' => 'string', 'description' => 'Parent component. `MoonShineComponent` (default), `Box`, or a fully-qualified class-string.'],
                    'view_content' => ['type' => 'string', 'description' => 'Blade markup inserted into the wrapper div.'],
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

        Log::info('CreateComponentTool: executing', ['name' => $name]);

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
            Log::error('CreateComponentTool: unexpected error', ['error' => $e->getMessage()]);

            return ['success' => false, 'code' => 'INTERNAL_ERROR', 'error' => $e->getMessage()];
        }

        return [
            'success' => true,
            'file' => $result['file'],
            'view' => $result['view'],
            'class' => $result['class'],
            'name' => $name,
        ];
    }
}
