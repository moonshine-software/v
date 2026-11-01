<?php

declare(strict_types=1);

namespace App\ApiAdmin\MCP\Tools;

use App\ApiAdmin\Layouts\LocalPanelLayout;
use App\ApiAdmin\MCP\AtomicFileWriter;
use App\ApiAdmin\MCP\ServiceRegistry;
use App\ApiAdmin\Tenancy\TenantContext;
use App\ApiAdmin\Tenancy\TenantResolver;
use App\ApiAdmin\Tenancy\TenantRoutes;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * MCP Tool: initialize_local_service
 *
 * Registers a local MoonShine panel for native Eloquent-backed resources.
 * This deliberately does not create a ServiceConfig or upstream HTTP client:
 * local services are standard MoonShine model-resource panels, not REST APIs.
 */
final class InitializeLocalServiceTool implements ToolContract
{
    /**
     * @var list<string>
     */
    private const array UPSTREAM_ONLY_KEYS = [
        'base_url',
        'api_key',
        'auth_header',
        'admin_login',
        'admin_auth_mode',
        'admin_auth',
        'health_path',
        'token_url',
        'oauth_client_id',
        'oauth_client_secret',
        'oauth_scope',
        'oauth_username',
        'oauth_password',
        'oauth_refresh_token',
        'session_login_endpoint',
        'session_login_method',
        'session_username_field',
        'session_password_field',
        'session_username',
        'session_password',
        'csrf_token_source',
        'csrf_token_key',
        'csrf_header_name',
        'csrf_bootstrap_path',
        'session_ttl',
        'response_format',
        'response_mapping',
        'realtime',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return [
            'name' => 'initialize_local_service',
            'description' => 'Generation changes are serialized and rolled back on failure. Available only in trusted local mode; hosted calls are rejected even with wildcard token scopes. Initialize a local MoonShine panel for native Eloquent ModelResource generation. Use this for monolith apps, then call create_model_resource.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'name' => [
                        'type' => 'string',
                        'description' => 'Unique local service identifier, e.g. "admin" or "catalog".',
                    ],
                    'panel' => [
                        'type' => 'string',
                        'description' => 'Panel key and route prefix. Defaults to "<name>-admin".',
                    ],
                    'title' => [
                        'type' => 'string',
                        'description' => 'Panel display title. Defaults to "<Name> Admin".',
                    ],
                    'auth_type' => [
                        'type' => 'string',
                        'enum' => ['local', 'none'],
                        'description' => 'Optional compatibility flag. Local services always use MoonShine\'s local moonshine_users guard; pass "local" or omit.',
                    ],
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
        Log::info('InitializeLocalServiceTool: executing', [
            'param_keys' => array_keys($params),
        ]);

        $name = $this->serviceName($params['name'] ?? null);
        if ($name === '') {
            Log::warning('InitializeLocalServiceTool: validation failed', [
                'missing' => ['name'],
            ]);

            return [
                'success' => false,
                'error' => "Missing required 'name'",
                'code' => 'VALIDATION',
                'hint' => 'Pass a stable local service identifier, e.g. {"name": "admin"}.',
            ];
        }

        $invalidKeys = $this->invalidUpstreamKeys($params);
        if ($invalidKeys !== []) {
            Log::warning('InitializeLocalServiceTool: rejected upstream-only arguments', [
                'name' => $name,
                'invalid_keys' => $invalidKeys,
            ]);

            return [
                'success' => false,
                'error' => 'initialize_local_service does not accept upstream API arguments: '.implode(', ', $invalidKeys),
                'code' => 'LOCAL_ONLY',
                'hint' => 'Use register_service for REST microservices. For local MoonShine panels, pass only name, panel, title, and optional auth_type="local".',
            ];
        }

        $authType = $params['auth_type'] ?? 'local';
        if (! is_string($authType) || ! in_array($authType, ['local', 'none'], true)) {
            Log::warning('InitializeLocalServiceTool: rejected auth type', [
                'name' => $name,
                'auth_type' => is_scalar($authType) ? (string) $authType : get_debug_type($authType),
            ]);

            return [
                'success' => false,
                'error' => 'auth_type must be "local" or "none" for initialize_local_service.',
                'code' => 'LOCAL_ONLY',
                'hint' => 'Local services use MoonShine\'s local moonshine_users guard. Use register_service for bearer, OAuth, session, or other upstream auth schemes.',
            ];
        }

        $panel = $this->stringParam($params['panel'] ?? null, $name.'-admin');
        $title = $this->stringParam($params['title'] ?? null, Str::title(str_replace(['-', '_'], ' ', $name)).' Admin');
        $tenant = $this->currentTenantContext();

        Log::debug('InitializeLocalServiceTool: normalized local service', [
            'service' => $name,
            'panel' => $panel,
            'kind' => ServiceRegistry::SERVICE_KIND_LOCAL,
            'tenant_id' => $tenant->id,
        ]);

        $servicesFile = $this->updateServices($name, $panel, $title, $tenant);
        $panelsFile = $this->registerPanel($name, $panel, $title);

        Log::info('InitializeLocalServiceTool: completed', [
            'service' => $name,
            'panel' => $panel,
            'kind' => ServiceRegistry::SERVICE_KIND_LOCAL,
            'tenant_id' => $tenant->id,
            'files' => [$servicesFile, $panelsFile],
        ]);

        return [
            'success' => true,
            'service' => $name,
            'panel' => $panel,
            'kind' => ServiceRegistry::SERVICE_KIND_LOCAL,
            'files' => [$servicesFile, $panelsFile],
            'message' => "Local MoonShine service '{$name}' initialized. Panel: '{$panel}'.",
            'guardrails' => [
                'Use create_model_resource next; local services do not use probe_endpoint or create_resource.',
                'Do not pass base_url, api_key, response_mapping, OAuth, session, or realtime settings to local service tools.',
            ],
        ];
    }

    private function serviceName(mixed $value): string
    {
        if (! is_string($value)) {
            return '';
        }

        $name = Str::of($value)
            ->trim()
            ->replace([' ', '.'], '-')
            ->lower()
            ->toString();

        return preg_match('/^[a-z][a-z0-9_-]*$/', $name) === 1 ? $name : '';
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return list<string>
     */
    private function invalidUpstreamKeys(array $params): array
    {
        $invalid = [];

        foreach (self::UPSTREAM_ONLY_KEYS as $key) {
            if (array_key_exists($key, $params)) {
                $invalid[] = $key;
            }
        }

        return $invalid;
    }

    private function stringParam(mixed $value, string $default): string
    {
        if (! is_string($value)) {
            return $default;
        }

        $value = trim($value);

        return $value !== '' ? $value : $default;
    }

    private function updateServices(string $name, string $panel, string $title, TenantContext $tenant): string
    {
        $path = $this->generatedServicesPath();
        $loaded = File::exists($path) ? include $path : ['services' => []];
        /** @var array{services: array<string, mixed>} $config */
        $config = is_array($loaded) && isset($loaded['services']) && is_array($loaded['services'])
            ? $loaded
            : ['services' => []];

        $config['services'][$name] = [
            'name' => $name,
            'tenant_id' => $tenant->id,
            'kind' => ServiceRegistry::SERVICE_KIND_LOCAL,
            'driver' => 'eloquent',
            'panel' => $panel,
            'title' => $title,
            'auth_type' => 'none',
            'admin_auth' => [
                'mode' => 'moonshine',
                'validate_endpoint' => '',
                'token_url' => '',
                'authorize_url' => '',
                'scopes' => [],
                'redirect_uri' => '',
            ],
        ];

        AtomicFileWriter::ensureDirectoryExists(dirname($path));
        AtomicFileWriter::put($path, "<?php\n\ndeclare(strict_types=1);\n\nreturn ".$this->exportPhpArray($config).";\n");
        config(['api-admin.services' => array_replace(
            is_array(config('api-admin.services', [])) ? config('api-admin.services', []) : [],
            $config['services'],
        )]);

        return $path;
    }

    private function registerPanel(string $name, string $panel, string $title): string
    {
        $path = $this->generatedPanelsPath();
        $loaded = File::exists($path) ? include $path : [];
        /** @var array<string, array<string, mixed>> $panels */
        $panels = is_array($loaded) ? $loaded : [];
        $panels[$panel] = [
            'prefix' => TenantRoutes::scopedPrefix($panel),
            'title' => $title,
            'layout' => LocalPanelLayout::class,
            'api_admin_service' => $name,
            'api_admin_kind' => ServiceRegistry::SERVICE_KIND_LOCAL,
        ];

        AtomicFileWriter::ensureDirectoryExists(dirname($path));
        AtomicFileWriter::put($path, "<?php\n\ndeclare(strict_types=1);\n\nreturn ".$this->exportPhpArray($panels).";\n");

        $currentPanels = config('moonshine.panels', []);
        $mergedPanels = array_replace(
            is_array($currentPanels) ? $currentPanels : [],
            $panels,
        );

        config(['moonshine.panels' => $mergedPanels]);
        if (function_exists('moonshineConfig')) {
            try {
                moonshineConfig()->set('panels', $mergedPanels);
            } catch (Throwable $e) {
                Log::debug('InitializeLocalServiceTool: skipped MoonShine runtime panel refresh', [
                    'panel' => $panel,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $path;
    }

    private function generatedServicesPath(): string
    {
        $path = config('api-admin.generated_services_path', storage_path('api-admin/generated/services.php'));

        return is_string($path) && $path !== '' ? $path : storage_path('api-admin/generated/services.php');
    }

    private function generatedPanelsPath(): string
    {
        $path = config('api-admin.generated_panels_path', storage_path('api-admin/generated/panels.php'));

        return is_string($path) && $path !== '' ? $path : storage_path('api-admin/generated/panels.php');
    }

    /**
     * @param array<mixed> $value
     */
    private function exportPhpArray(array $value, int $indent = 0): string
    {
        if ($value === []) {
            return '[]';
        }

        $pad = str_repeat('    ', $indent);
        $childPad = str_repeat('    ', $indent + 1);
        $isList = array_is_list($value);
        $lines = ['['];

        foreach ($value as $key => $item) {
            $prefix = $isList ? '' : var_export($key, true).' => ';
            $exported = is_array($item)
                ? $this->exportPhpArray($item, $indent + 1)
                : var_export($item, true);

            $lines[] = $childPad.$prefix.$exported.',';
        }

        $lines[] = $pad.']';

        return implode("\n", $lines);
    }

    private function currentTenantContext(): TenantContext
    {
        if (! app()->bound(TenantResolver::class)) {
            return TenantContext::local();
        }

        $resolver = app()->get(TenantResolver::class);
        $tenant = $resolver->resolve();

        if (! $tenant instanceof TenantContext) {
            throw new RuntimeException('Missing tenant context in hosted mode.');
        }

        return $tenant;
    }
}
