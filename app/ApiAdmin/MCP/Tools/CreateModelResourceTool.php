<?php

declare(strict_types=1);

namespace App\ApiAdmin\MCP\Tools;

use App\ApiAdmin\MCP\Generator\ModelResourceGenerator;
use App\ApiAdmin\MCP\ServiceRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * MCP Tool: create_model_resource
 *
 * Generates a native MoonShine ModelResource for a local Eloquent service.
 */
final class CreateModelResourceTool implements ToolContract
{
    /**
     * @return array{
     *     name: string,
     *     description: string,
     *     inputSchema: array{
     *         type: string,
     *         properties: array<string, mixed>,
     *         required: list<string>,
     *     },
     * }
     */
    public static function schema(): array
    {
        return [
            'name' => 'create_model_resource',
            'description' => 'Generation changes are serialized and rolled back on failure. Available only in trusted local mode; hosted calls are rejected even with wildcard token scopes. Create a native MoonShine ModelResource for a registered local/Eloquent service. Refuses REST services, native platform names and existing resources owned by another panel.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'service' => [
                        'type' => 'string',
                        'description' => 'Registered local service name created with initialize_local_service.',
                    ],
                    'name' => [
                        'type' => 'string',
                        'description' => 'Resource name, e.g. "post", "product", "order".',
                    ],
                    'model' => [
                        'type' => 'string',
                        'description' => 'Fully-qualified Eloquent model class, e.g. App\\Models\\Post.',
                    ],
                    'title' => [
                        'type' => 'string',
                        'description' => 'Display title for the resource.',
                    ],
                    'panel' => [
                        'type' => 'string',
                        'description' => 'Panel identifier. Defaults to and must match the registered local service panel.',
                    ],
                    'group' => [
                        'type' => 'string',
                        'description' => 'Optional MoonShine menu group label.',
                    ],
                    'icon' => [
                        'type' => 'string',
                        'description' => 'Optional Heroicons outline slug emitted as #[Icon(...)] on the resource.',
                    ],
                    'column' => [
                        'type' => 'string',
                        'description' => 'Display column for the resource title. Defaults to the first useful field.',
                    ],
                    'detail' => [
                        'type' => 'boolean',
                        'description' => 'Whether to generate a DetailPage. Defaults to true.',
                    ],
                    'fields' => self::fieldsSchema('canonical field list used by all pages unless overridden'),
                    'index_fields' => self::fieldsSchema('field list for IndexPage; replaces fields for index when present'),
                    'form_fields' => self::fieldsSchema('field list for FormPage; replaces fields for form when present'),
                    'detail_fields' => self::fieldsSchema('field list for DetailPage; replaces fields for detail when present'),
                    'rules' => [
                        'type' => 'object',
                        'description' => 'Form validation rules keyed by column. Values may be strings or string arrays.',
                    ],
                    'search' => [
                        'type' => 'array',
                        'description' => 'Searchable model columns.',
                        'items' => ['type' => 'string'],
                    ],
                    'actions' => [
                        'type' => 'array',
                        'description' => 'Allowlist of active MoonShine actions.',
                        'items' => [
                            'type' => 'string',
                            'enum' => ['create', 'view', 'update', 'delete', 'mass_delete'],
                        ],
                    ],
                ],
                'required' => ['service', 'name', 'model', 'fields'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function fieldsSchema(string $description): array
    {
        return [
            'type' => 'array',
            'description' => $description,
            'items' => [
                'type' => 'object',
                'properties' => [
                    'column' => ['type' => 'string', 'description' => 'Model attribute / database column.'],
                    'label' => ['type' => 'string', 'description' => 'Display label.'],
                    'type' => [
                        'type' => 'string',
                        'enum' => [
                            'id', 'text', 'textarea', 'number', 'email', 'password',
                            'date', 'datetime', 'checkbox', 'switcher', 'select',
                            'image', 'file', 'json', 'color', 'slug', 'url',
                        ],
                        'description' => 'Local MoonShine field type. REST relation/upstream field types are intentionally not accepted here.',
                    ],
                    'hide_on' => [
                        'type' => 'array',
                        'description' => 'Pages to omit this field from when page-specific fields are not provided.',
                        'items' => ['type' => 'string', 'enum' => ['index', 'form', 'detail']],
                    ],
                    'methods' => [
                        'type' => 'array',
                        'description' => 'Supported simple zero-argument field method names: badge, copy, disabled, eye, locked, nullable, readonly, reactive, required, sortable.',
                        'items' => ['type' => 'string'],
                    ],
                    'options' => [
                        'type' => 'object',
                        'description' => 'Static options for select fields.',
                    ],
                ],
                'required' => ['column', 'type'],
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
        $service = is_string($params['service'] ?? null) ? trim($params['service']) : '';
        $resource = is_string($params['name'] ?? null) ? trim($params['name']) : '';
        $model = is_string($params['model'] ?? null) ? ltrim(trim($params['model']), '\\') : '';
        $fields = $params['fields'] ?? [];

        Log::info('CreateModelResourceTool: executing', [
            'service' => $service !== '' ? $service : null,
            'resource' => $resource !== '' ? $resource : null,
            'model' => $model !== '' ? $model : null,
            'fields_count' => is_array($fields) ? count($fields) : 0,
        ]);

        $serviceConfig = config("api-admin.services.{$service}");
        if (! is_array($serviceConfig)) {
            Log::warning('CreateModelResourceTool: service not registered', [
                'service' => $service,
            ]);

            return [
                'success' => false,
                'code' => 'SERVICE_NOT_REGISTERED',
                'error' => "Service '{$service}' is not registered.",
            ];
        }

        if (! $this->isLocalService($serviceConfig)) {
            Log::warning('CreateModelResourceTool: rejected non-local service', [
                'service' => $service,
                'kind' => $serviceConfig['kind'] ?? null,
                'driver' => $serviceConfig['driver'] ?? null,
            ]);

            return [
                'success' => false,
                'code' => 'LOCAL_SERVICE_REQUIRED',
                'error' => "Service '{$service}' is not local/Eloquent. Use create_resource for REST services.",
            ];
        }

        if ($model === '' || ! class_exists($model) || ! is_subclass_of($model, Model::class)) {
            Log::warning('CreateModelResourceTool: missing or invalid model', [
                'service' => $service,
                'resource' => $resource,
                'model' => $model,
            ]);

            return [
                'success' => false,
                'code' => 'MODEL_NOT_FOUND',
                'error' => $model === ''
                    ? 'Model class is required.'
                    : "Model class '{$model}' does not exist or is not an Eloquent model.",
            ];
        }

        $panel = $serviceConfig['panel'] ?? null;
        if (is_string($params['panel'] ?? null) && trim($params['panel']) !== '' && trim($params['panel']) !== $panel) {
            Log::warning('CreateModelResourceTool: rejected foreign panel', ['service' => $service]);

            return [
                'success' => false,
                'code' => 'LOCAL_PANEL_MISMATCH',
                'error' => 'The resource panel must match the registered local service panel.',
            ];
        }
        if (is_string($panel) && $panel !== '') {
            $params['panel'] = $panel;
        }

        try {
            $result = (new ModelResourceGenerator)->generate($params);
            $this->reload();

            Log::info('CreateModelResourceTool: completed', [
                'service' => $service,
                'resource' => $resource,
                'model' => $model,
                'file' => $result['file'],
                'pages_count' => count($result['pages']),
            ]);

            return [
                'success' => true,
                'service' => $service,
                'kind' => ServiceRegistry::SERVICE_KIND_LOCAL,
                'resource' => $result['className'],
                'model' => $model,
                'class' => $result['class'],
                'files' => [
                    'resource' => $result['file'],
                    'pages' => $result['pages'],
                ],
                'message' => "Local model resource '{$result['className']}' created for service '{$service}'.",
                'next_steps' => [
                    'Open the MoonShine panel and verify the generated resource appears in the sidebar.',
                    'Adjust field rules/actions if the model has guarded or computed attributes.',
                ],
            ];
        } catch (RuntimeException $e) {
            Log::error('CreateModelResourceTool: generator validation failed', [
                'service' => $service,
                'resource' => $resource,
                'model' => $model,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'code' => 'MODEL_NOT_FOUND',
                'error' => $e->getMessage(),
            ];
        } catch (Throwable $e) {
            Log::error('CreateModelResourceTool: generator failed', [
                'service' => $service,
                'resource' => $resource,
                'model' => $model,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'code' => 'GENERATION_FAILED',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * @param array<array-key, mixed> $config
     */
    private function isLocalService(array $config): bool
    {
        $kind = $config['kind'] ?? null;
        if ($kind === ServiceRegistry::SERVICE_KIND_LOCAL) {
            return true;
        }

        $driver = $config['driver'] ?? null;

        return $driver === 'eloquent';
    }

    private function reload(): void
    {
        Log::debug('CreateModelResourceTool: triggering reload');

        try {
            Artisan::call('api-admin:reload');

            Log::info('CreateModelResourceTool: reload completed');
        } catch (Throwable $e) {
            Log::warning('CreateModelResourceTool: reload failed (non-blocking)', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
