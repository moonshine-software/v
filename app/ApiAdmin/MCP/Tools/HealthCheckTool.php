<?php

declare(strict_types=1);

namespace App\ApiAdmin\MCP\Tools;

use App\ApiAdmin\MCP\ServiceRegistry;
use App\ApiAdmin\Providers\ApiAdminServiceProvider;
use App\ApiAdmin\ServiceConfig\ServiceConfig;
use App\ApiAdmin\ServiceConfig\ServiceHealthChecker;
use App\ApiAdmin\Tenancy\TenantContext;
use App\ApiAdmin\Tenancy\TenantResolver;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * MCP Tool: health_check
 *
 * Pings the health endpoint of one or all registered services and returns
 * `{status, code, url, latency_ms}` per service. Thin wrapper over
 * `ServiceHealthChecker` so the AI agent has one call to verify that a
 * service is alive without opening the browser.
 */
final class HealthCheckTool implements ToolContract
{
    /**
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return [
            'name' => 'health_check',
            'description' => 'Ping health endpoint of all registered services (or one, via `service`). Returns status, HTTP code, URL, and round-trip latency. Read-only.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'service' => [
                        'type' => 'string',
                        'description' => 'Optional — restrict to a single registered service by name',
                    ],
                ],
            ],
        ];
    }

    /**
     * @param array{service?: string} $params
     *
     * @return array<string, mixed>
     */
    public function execute(array $params): array
    {
        $service = ($params['service'] ?? '') !== '' ? $params['service'] : null;
        $tenant = $this->currentTenantContext();

        Log::info('HealthCheckTool: executing', [
            'tenant_id' => $tenant->id,
            'service' => $service,
        ]);

        $checker = new ServiceHealthChecker;
        $registry = new ServiceRegistry;
        $visibleServices = [];

        foreach ($registry->listServices() as $entry) {
            $name = $entry['name'] ?? null;
            if (is_string($name) && $name !== '') {
                $visibleServices[$name] = $entry;
            }
        }

        Log::debug('HealthCheckTool: resolved tenant-visible services', [
            'tenant_id' => $tenant->id,
            'count' => count($visibleServices),
        ]);

        if ($service !== null) {
            if (! isset($visibleServices[$service])) {
                Log::warning('HealthCheckTool: service is not visible to tenant', [
                    'tenant_id' => $tenant->id,
                    'service' => $service,
                ]);

                return [
                    'success' => false,
                    'error' => "Service '{$service}' is not registered",
                    'code' => 'SERVICE_NOT_REGISTERED',
                    'hint' => 'Call list_services to see what is registered.',
                ];
            }

            $visibleServices = [$service => $visibleServices[$service]];
        }

        $results = [];

        foreach (array_keys($visibleServices) as $name) {
            $nameStr = (string) $name;

            try {
                $config = ApiAdminServiceProvider::resolveService($nameStr, $tenant);
            } catch (Throwable $e) {
                Log::warning('HealthCheckTool: provider service binding unavailable, falling back to visible config', [
                    'tenant_id' => $tenant->id,
                    'service' => $nameStr,
                    'error' => $e->getMessage(),
                ]);

                $config = $this->serviceConfigFromVisibleEntry($nameStr, $tenant);
                if (! $config instanceof ServiceConfig) {
                    Log::error('HealthCheckTool: failed to resolve service for health check', [
                        'tenant_id' => $tenant->id,
                        'service' => $nameStr,
                        'error' => $e->getMessage(),
                    ]);

                    $results[] = [
                        'service' => $nameStr,
                        'tenant_id' => $tenant->id,
                        'status' => ServiceHealthChecker::STATUS_DOWN,
                        'code' => null,
                        'url' => null,
                        'latency_ms' => null,
                        'error' => $e->getMessage(),
                    ];

                    continue;
                }
            }

            $startedAt = microtime(true);
            $check = $checker->check($config);
            $latency = (int) round((microtime(true) - $startedAt) * 1000);

            $results[] = [
                'service' => $nameStr,
                'tenant_id' => $config->tenantId,
                'status' => $check['status'],
                'code' => $check['code'],
                'url' => $check['url'],
                'latency_ms' => $latency,
            ];
        }

        return [
            'success' => true,
            'count' => count($results),
            'services' => $results,
            'diagnostics' => $this->diagnostics($tenant),
        ];
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

    private function serviceConfigFromVisibleEntry(string $name, TenantContext $tenant): ?ServiceConfig
    {
        $raw = config("api-admin.services.{$name}");
        if (! is_array($raw)) {
            return null;
        }

        /** @var array<string, mixed> $service */
        $service = $raw;
        $service['name'] = $name;
        $tenantId = $service['tenant_id'] ?? null;
        $service['tenant_id'] = is_string($tenantId) && $tenantId !== '' ? $tenantId : TenantContext::LOCAL_ID;

        if (! $tenant->isLocal && $service['tenant_id'] !== $tenant->id) {
            return null;
        }

        return ServiceConfig::fromArray($service);
    }

    /**
     * @return array{mode: string, tenant_id: string, cache_store: string, session_driver: string, queue_connection: string, broadcast_connection: string, warnings: list<string>}
     */
    private function diagnostics(TenantContext $tenant): array
    {
        $cacheStore = $this->stringConfig('cache.default', 'array');
        $sessionDriver = $this->stringConfig('session.driver', 'file');
        $queueConnection = $this->stringConfig('queue.default', 'sync');
        $broadcastConnection = $this->stringConfig('broadcasting.default', 'log');
        $warnings = [];

        if (! $tenant->isLocal) {
            if (in_array($cacheStore, ['array', 'file'], true)) {
                $warnings[] = "Hosted mode should use a shared cache store; current cache.default is '{$cacheStore}'.";
            }

            if (in_array($sessionDriver, ['array', 'file', 'cookie'], true)) {
                $warnings[] = "Hosted mode should use a shared server-side session driver; current session.driver is '{$sessionDriver}'.";
            }

            if ($queueConnection === 'sync') {
                $warnings[] = 'Hosted mode should use an async queue connection for provisioning and reload work.';
            }

            if (in_array($broadcastConnection, ['log', 'null'], true)) {
                $warnings[] = "Hosted realtime refresh needs a real broadcaster; current broadcasting.default is '{$broadcastConnection}'.";
            }
        }

        foreach ($warnings as $warning) {
            Log::warning('HealthCheckTool: degraded hosted dependency', [
                'tenant_id' => $tenant->id,
                'warning' => $warning,
            ]);
        }

        Log::debug('HealthCheckTool: operational diagnostics completed', [
            'tenant_id' => $tenant->id,
            'mode' => $tenant->mode,
            'warnings_count' => count($warnings),
        ]);

        return [
            'mode' => $tenant->mode,
            'tenant_id' => $tenant->id,
            'cache_store' => $cacheStore,
            'session_driver' => $sessionDriver,
            'queue_connection' => $queueConnection,
            'broadcast_connection' => $broadcastConnection,
            'warnings' => $warnings,
        ];
    }

    private function stringConfig(string $key, string $default): string
    {
        $value = config($key, $default);

        return is_string($value) && $value !== '' ? $value : $default;
    }
}
