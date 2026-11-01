<?php

declare(strict_types=1);

namespace App\ApiAdmin\MCP;

use App\ApiAdmin\Tenancy\TenantContext;
use App\ApiAdmin\Tenancy\TenantResolver;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Read/write helpers for the generated ApiAdmin state:
 *
 *   - services registered in config/api-admin.php
 *   - panels registered in config/moonshine.php
 *   - generated Configs/, Layouts/, Resources/{Service}/{Resource}/ files
 *
 * This is the central point MCP tools go through to mutate admin state, so
 * list/remove/update tools stay consistent with how `register_service` and
 * `create_resource` originally wrote things.
 */
final class ServiceRegistry
{
    public const string SERVICE_KIND_REST = 'rest';

    public const string SERVICE_KIND_LOCAL = 'local';

    /**
     * List all registered services with paths + resource counts.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listServices(): array
    {
        $services = $this->visibleServices();
        $resourcesBase = $this->resourcesPath();
        $out = [];

        foreach ($services as $name => $config) {
            $serviceName = (string) $name;
            $serviceDir = $resourcesBase.'/'.Str::studly($serviceName);
            $kind = $this->serviceKind($config);
            $resources = $kind === self::SERVICE_KIND_LOCAL
                ? $this->listLocalResources($serviceName, $config)
                : $this->listResources($serviceName);

            $out[] = [
                'name' => $serviceName,
                'kind' => $kind,
                'base_url' => $config['base_url'] ?? null,
                'panel' => $config['panel'] ?? null,
                'tenant_id' => $this->serviceTenantId($config),
                'auth_type' => $config['auth_type'] ?? 'bearer',
                'response_mapping' => $config['response_mapping'] ?? null,
                'resources_count' => count($resources),
                'resources' => array_map(static fn (array $r): mixed => $r['name'] ?? null, $resources),
                'service_dir' => File::isDirectory($serviceDir) ? $serviceDir : null,
                'config_file' => $this->serviceConfigFilePath($serviceName),
                'layout_file' => $this->layoutFilePath($serviceName),
            ];
        }

        Log::debug('ServiceRegistry: listed services', [
            'tenant_id' => $this->logTenantId(),
            'count' => count($out),
            'kinds' => array_count_values(array_map(
                static fn (array $service): string => $service['kind'],
                $out,
            )),
        ]);

        return $out;
    }

    /**
     * List generated native ModelResource files for a local service.
     *
     * This intentionally stays separate from listResources(), which remains
     * REST-only because data tools resolve those entries as AbstractRestResource.
     *
     * @param array<string, mixed> $serviceConfig
     *
     * @return array<int, array<string, mixed>>
     */
    private function listLocalResources(string $service, array $serviceConfig): array
    {
        $resourcesBase = $this->localResourcesPath();
        if (! File::isDirectory($resourcesBase)) {
            return [];
        }

        $panel = $serviceConfig['panel'] ?? null;
        $panel = is_string($panel) && $panel !== '' ? $panel : null;
        $out = [];

        foreach ($this->subDirectories($resourcesBase) as $resourceDir) {
            $resourceBase = basename($resourceDir);
            $resourceFile = $resourceDir.'/'.$resourceBase.'Resource.php';

            if (! File::isFile($resourceFile)) {
                continue;
            }

            $resourcePanel = $this->parsePanel($resourceFile);
            if ($panel !== null && $resourcePanel !== null && $resourcePanel !== $panel) {
                continue;
            }

            $out[] = [
                'service' => $service,
                'name' => Str::snake($resourceBase),
                'base_name' => $resourceBase,
                'resource_file' => $resourceFile,
                'pages_dir' => $resourceDir.'/Pages',
                'resource_dir' => $resourceDir,
                'endpoint' => null,
                'kind' => self::SERVICE_KIND_LOCAL,
            ];
        }

        Log::debug('ServiceRegistry: listed local resources', [
            'tenant_id' => $this->logTenantId(),
            'service' => $service,
            'panel' => $panel,
            'count' => count($out),
        ]);

        return $out;
    }

    /**
     * List resources for a given service (or all services if null).
     *
     * @return array<int, array<string, mixed>>
     */
    public function listResources(?string $service = null): array
    {
        $resourcesBase = $this->resourcesPath();

        if (! File::isDirectory($resourcesBase)) {
            return [];
        }

        $out = [];
        if ($service !== null && ! $this->serviceVisibleToCurrentTenant($service)) {
            Log::warning('ServiceRegistry: hid cross-tenant resource listing', [
                'tenant_id' => $this->logTenantId(),
                'service' => $service,
            ]);

            return [];
        }

        if ($service !== null) {
            $serviceDirs = [$resourcesBase.'/'.Str::studly($service)];
        } elseif ($this->hostedMode()) {
            $serviceDirs = array_map(
                static fn (string $name): string => $resourcesBase.'/'.Str::studly($name),
                array_keys($this->visibleServices()),
            );
        } else {
            $serviceDirs = $this->subDirectories($resourcesBase);
        }

        foreach ($serviceDirs as $serviceDir) {
            if (! File::isDirectory($serviceDir)) {
                continue;
            }

            $serviceName = basename($serviceDir);

            foreach ($this->subDirectories($serviceDir) as $resourceDir) {
                $resourceBase = basename($resourceDir);
                $resourceFile = $resourceDir.'/'.$resourceBase.'Resource.php';

                if (! File::isFile($resourceFile)) {
                    continue;
                }

                $endpoint = $this->parseEndpoint($resourceFile);
                $out[] = [
                    'service' => Str::snake($serviceName),
                    'name' => Str::snake($resourceBase),
                    'base_name' => $resourceBase,
                    'resource_file' => $resourceFile,
                    'pages_dir' => $resourceDir.'/Pages',
                    'resource_dir' => $resourceDir,
                    'endpoint' => $endpoint,
                    // 'main' — top-level panel resource (MultiPanelRestResource + panel).
                    // 'relation' — used only through parent's RestHasOne/RestHasMany.
                    'kind' => $this->detectKind($resourceFile, $endpoint),
                ];
            }
        }

        Log::debug('ServiceRegistry: listed resources', [
            'tenant_id' => $this->logTenantId(),
            'service' => $service,
            'count' => count($out),
        ]);

        return $out;
    }

    /**
     * Directory and class base name of a generated resource.
     *
     * Agents use singular and plural names interchangeably, so an existing
     * resource wins over a new directory for the other form: "categories" and
     * "category" address the same resource whichever one created it. Without an
     * existing resource the name is used as given.
     */
    public function resourceBaseName(string $service, string $name): string
    {
        $serviceDir = $this->resourcesPath().'/'.Str::studly($service);
        $exact = Str::studly($name);

        foreach ([$exact, Str::studly(Str::singular($name)), Str::studly(Str::plural($name))] as $candidate) {
            if (File::isDirectory($serviceDir.'/'.$candidate)) {
                return $candidate;
            }
        }

        return $exact;
    }

    /**
     * Listing entry of one resource, resolved by {@see resourceBaseName()}.
     *
     * @return array<string, mixed>|null
     */
    public function findResource(string $service, string $name): ?array
    {
        $baseName = $this->resourceBaseName($service, $name);

        return array_find(
            $this->listResources($service),
            static fn (array $resource): bool => $resource['base_name'] === $baseName,
        );
    }

    /**
     * Remove a service completely:
     *   - Resources/{Service}/*
     *   - Configs/{Service}Config.php
     *   - Layouts/{Service}Layout.php
     *   - services[$name] from config/api-admin.php
     *   - panels[$panel] from config/moonshine.php
     *
     * @return array<string, mixed> removed paths + metadata
     */
    public function removeService(string $name): array
    {
        $this->assertServiceVisibleToCurrentTenant($name);

        $service = config("api-admin.services.$name");
        $panel = is_array($service) ? ($service['panel'] ?? null) : null;

        $removed = [
            'service' => $name,
            'panel' => $panel,
            'paths' => [],
        ];

        // Resources dir
        $serviceDir = $this->resourcesPath().'/'.Str::studly($name);
        if (File::isDirectory($serviceDir)) {
            AtomicFileWriter::deleteDirectory($serviceDir);
            $removed['paths'][] = $serviceDir;
        }

        // Config file
        $configFile = $this->serviceConfigFilePath($name);
        if (File::isFile($configFile)) {
            AtomicFileWriter::delete($configFile);
            $removed['paths'][] = $configFile;
        }

        // Layout file
        $layoutFile = $this->layoutFilePath($name);
        if (File::isFile($layoutFile)) {
            AtomicFileWriter::delete($layoutFile);
            $removed['paths'][] = $layoutFile;
        }

        // config/api-admin.php — drop the services entry
        $this->removeFromApiAdminConfig($name);

        // config/moonshine.php — drop the panel entry
        if (is_string($panel) && $panel !== '') {
            $this->removePanelFromMoonshineConfig($panel);
        }

        Log::info('ServiceRegistry: removed service', [
            'tenant_id' => $this->logTenantId(),
            'service' => $name,
            'panel' => $panel,
            'paths_count' => count($removed['paths']),
        ]);

        return $removed;
    }

    /**
     * Remove a resource inside a service: directory + pages; leaves service itself.
     *
     * @return array<string, mixed> removed paths
     */
    public function removeResource(string $service, string $name): array
    {
        $this->assertServiceVisibleToCurrentTenant($service);

        $resourceDir = $this->resourcesPath().'/'.Str::studly($service).'/'.$this->resourceBaseName($service, $name);

        $removed = [
            'service' => $service,
            'resource' => $name,
            'paths' => [],
        ];

        if (File::isDirectory($resourceDir)) {
            AtomicFileWriter::deleteDirectory($resourceDir);
            $removed['paths'][] = $resourceDir;
        }

        Log::info('ServiceRegistry: removed resource', [
            'tenant_id' => $this->logTenantId(),
            'service' => $service,
            'resource' => $name,
            'paths_count' => count($removed['paths']),
        ]);

        return $removed;
    }

    /**
     * Drop a service entry from config/api-admin.php preserving the rest.
     */
    private function removeFromApiAdminConfig(string $name): void
    {
        $configPath = $this->generatedServicesPath();

        if (! File::isFile($configPath)) {
            return;
        }

        $config = include $configPath;
        if (! is_array($config) || ! is_array($config['services'] ?? null) || ! isset($config['services'][$name])) {
            return;
        }

        unset($config['services'][$name]);

        $export = var_export($config, true);
        AtomicFileWriter::put($configPath, "<?php\n\ndeclare(strict_types=1);\n\nreturn {$export};\n");
        $services = config('api-admin.services', []);
        if (is_array($services)) {
            unset($services[$name]);
            config(['api-admin.services' => $services]);
        }
    }

    /**
     * Drop a panel block from config/moonshine.php by key, using bracket counting
     * so nested arrays inside the panel don't trip the matcher.
     */
    private function removePanelFromMoonshineConfig(string $panel): void
    {
        $generatedPath = $this->generatedPanelsPath();

        if (File::isFile($generatedPath)) {
            $loaded = include $generatedPath;
            if (is_array($loaded) && isset($loaded[$panel])) {
                unset($loaded[$panel]);
                $export = var_export($loaded, true);
                AtomicFileWriter::put($generatedPath, "<?php\n\ndeclare(strict_types=1);\n\nreturn {$export};\n");
                $panels = config('moonshine.panels', []);
                if (is_array($panels)) {
                    unset($panels[$panel]);
                    config(['moonshine.panels' => $panels]);
                }

                return;
            }
        }

        $configPath = config_path('moonshine.php');

        if (! File::isFile($configPath)) {
            return;
        }

        $content = File::get($configPath);

        // Locate `'<panel>' => [`
        if (! preg_match("/'".preg_quote($panel, '/')."'\s*=>\s*\[/", $content, $m, PREG_OFFSET_CAPTURE)) {
            return;
        }

        $start = $m[0][1];
        $bracketPos = $start + strlen($m[0][0]) - 1;
        $depth = 1;
        $i = $bracketPos + 1;
        $len = strlen($content);

        while ($i < $len) {
            $ch = $content[$i];
            if ($ch === '[') {
                $depth++;
            } elseif ($ch === ']') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
            }
            $i++;
        }

        if ($depth !== 0) {
            return;
        }

        // $i is at the closing `]` of the panel block.
        // Swallow a trailing `,` and the trailing newline/whitespace up to the next line.
        $end = $i + 1;
        if ($end < $len && $content[$end] === ',') {
            $end++;
        }
        while ($end < $len && ($content[$end] === ' ' || $content[$end] === "\t")) {
            $end++;
        }
        if ($end < $len && $content[$end] === "\n") {
            $end++;
        }

        // Walk backward over the leading indentation of the panel line.
        while ($start > 0 && ($content[$start - 1] === ' ' || $content[$start - 1] === "\t")) {
            $start--;
        }

        $content = substr($content, 0, $start).substr($content, $end);

        AtomicFileWriter::put($configPath, $content);
    }

    public function resourcesPath(): string
    {
        $path = config('api-admin.resources_path', app_path('MoonShine/ApiAdmin/Resources'));

        return $this->tenantScopedPath(is_string($path) ? $path : app_path('MoonShine/ApiAdmin/Resources'));
    }

    public function localResourcesPath(): string
    {
        $path = config('api-admin.local_resources_path', app_path('MoonShine/Resources'));

        return $this->tenantScopedPath(is_string($path) ? $path : app_path('MoonShine/Resources'));
    }

    public function configsPath(): string
    {
        $path = config('api-admin.configs_path', app_path('MoonShine/ApiAdmin/Configs'));

        return $this->tenantScopedPath(is_string($path) ? $path : app_path('MoonShine/ApiAdmin/Configs'));
    }

    public function layoutsPath(): string
    {
        $path = config('api-admin.layouts_path', app_path('MoonShine/ApiAdmin/Layouts'));

        return $this->tenantScopedPath(is_string($path) ? $path : app_path('MoonShine/ApiAdmin/Layouts'));
    }

    public function serviceConfigFilePath(string $name): string
    {
        return $this->configsPath().'/'.Str::studly($name).'Config.php';
    }

    public function layoutFilePath(string $name): string
    {
        return $this->layoutsPath().'/'.Str::studly($name).'Layout.php';
    }

    /**
     * @return array<int, string>
     */
    private function subDirectories(string $dir): array
    {
        if (! File::isDirectory($dir)) {
            return [];
        }

        /** @var list<string> $dirs */
        $dirs = File::directories($dir);

        return array_filter(
            $dirs,
            static fn (string $p): bool => basename($p) !== 'Pages',
        )
            |> array_values(...);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function visibleServices(): array
    {
        $servicesConfig = config('api-admin.services', []);
        /** @var array<string, array<string, mixed>> $services */
        $services = is_array($servicesConfig) ? $servicesConfig : [];

        if (! $this->hostedMode()) {
            return $services;
        }

        $tenant = $this->currentTenantContext();

        return array_filter(
            $services,
            fn (array $config): bool => $this->serviceTenantId($config) === $tenant->id,
        );
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

    private function serviceVisibleToCurrentTenant(string $service): bool
    {
        if (! $this->hostedMode()) {
            return true;
        }

        return array_key_exists($service, $this->visibleServices());
    }

    private function assertServiceVisibleToCurrentTenant(string $service): void
    {
        if ($this->serviceVisibleToCurrentTenant($service)) {
            return;
        }

        $tenant = $this->currentTenantContext();

        Log::warning('ServiceRegistry: rejected cross-tenant service access', [
            'tenant_id' => $tenant->id,
            'service' => $service,
        ]);

        throw new RuntimeException("Service '{$service}' is not available for tenant '{$tenant->id}'.");
    }

    /**
     * @param array<string, mixed> $config
     */
    private function serviceTenantId(array $config): string
    {
        $tenantId = $config['tenant_id'] ?? null;

        return is_string($tenantId) && $tenantId !== '' ? $tenantId : TenantContext::LOCAL_ID;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function serviceKind(array $config): string
    {
        $kind = $config['kind'] ?? $config['driver'] ?? self::SERVICE_KIND_REST;

        if (! is_string($kind)) {
            return self::SERVICE_KIND_REST;
        }

        return match (strtolower($kind)) {
            self::SERVICE_KIND_LOCAL, 'eloquent' => self::SERVICE_KIND_LOCAL,
            default => self::SERVICE_KIND_REST,
        };
    }

    private function tenantScopedPath(string $basePath): string
    {
        if (! $this->hostedMode()) {
            return $basePath;
        }

        $tenant = $this->currentTenantContext();
        $segment = config('api-admin.hosted.tenant_path_segment', 'Tenants');
        $pathSegment = is_string($segment) && $segment !== '' ? $segment : 'Tenants';

        $path = $basePath.'/'.$pathSegment.'/'.Str::studly($tenant->slug);

        Log::debug('ServiceRegistry: resolved tenant-scoped generated path', [
            'tenant_id' => $tenant->id,
            'base_path' => $basePath,
            'path' => $path,
        ]);

        return $path;
    }

    private function hostedMode(): bool
    {
        return config('api-admin.hosted.mode', 'local') === 'hosted';
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

    private function logTenantId(): string
    {
        $tenant = $this->currentTenantContext();

        return $tenant->id;
    }

    /**
     * Classify a resource from its source file:
     *   - extends MultiPanelRestResource → main (registered in a panel menu)
     *   - extends AbstractRestResource   → relation (only through parent's has_one/has_many)
     *   - endpoint contains `{...}` placeholder → relation (safety net)
     */
    private function detectKind(string $file, ?string $endpoint): string
    {
        $contents = (string) @file_get_contents($file);

        if (str_contains($contents, 'extends MultiPanelRestResource')) {
            return 'main';
        }

        if (str_contains($contents, 'extends AbstractRestResource')) {
            return 'relation';
        }

        return ($endpoint !== null && str_contains($endpoint, '{')) ? 'relation' : 'main';
    }

    private function parseEndpoint(string $file): ?string
    {
        $contents = (string) @file_get_contents($file);

        if ($contents === '') {
            return null;
        }

        // Target the endpoint() method specifically. A naive
        // `return '...'` regex picks up the FIRST return in the file,
        // which on main resources is `getGroup()` returning the group name
        // (e.g. "Blog"). That string then silently leaked into endpoint()
        // on the next `update_resource` that didn't pass an explicit
        // endpoint — rewriting the file with `return 'Blog';` and breaking
        // every subsequent HTTP call.
        if (preg_match(
            '/function\s+endpoint\s*\([^)]*\)\s*:\s*string\s*\{\s*return\s+[\'\"]([^\'\"]+)[\'\"]\s*;/s',
            $contents,
            $m,
        )) {
            return $m[1];
        }

        return null;
    }

    private function parsePanel(string $file): ?string
    {
        $contents = (string) @file_get_contents($file);

        if ($contents === '') {
            return null;
        }

        if (preg_match('/protected\s+string\s+\$panel\s*=\s*[\'"]([^\'"]+)[\'"]\s*;/s', $contents, $m)) {
            return $m[1];
        }

        return null;
    }
}
