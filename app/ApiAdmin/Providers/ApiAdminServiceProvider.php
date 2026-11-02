<?php

declare(strict_types=1);

namespace App\ApiAdmin\Providers;

use App\ApiAdmin\Auth\UpstreamUserProvider;
use App\ApiAdmin\Console\McpServerCommand;
use App\ApiAdmin\Console\McpTokenCommand;
use App\ApiAdmin\Console\MigrateTenantSecretsCommand;
use App\ApiAdmin\Console\ReloadCommand;
use App\ApiAdmin\Console\TenantCommand;
use App\ApiAdmin\Console\TenantMembershipCommand;
use App\ApiAdmin\Http\Controllers\InstructorController;
use App\ApiAdmin\Http\Controllers\OAuthAuthorizationController;
use App\ApiAdmin\Http\Controllers\RestAsyncSearchController;
use App\ApiAdmin\Http\Controllers\RestHasManyController;
use App\ApiAdmin\Http\Controllers\RestHasOneController;
use App\ApiAdmin\Http\Middleware\EnsureHostedMcpEnabled;
use App\ApiAdmin\MCP\HostedMcpController;
use App\ApiAdmin\MCP\ServiceRegistry;
use App\ApiAdmin\Realtime\BroadcastAuthController;
use App\ApiAdmin\Realtime\WebhookController;
use App\ApiAdmin\Resources\AbstractRestResource;
use App\ApiAdmin\ServiceConfig\ServiceConfig;
use App\ApiAdmin\Tenancy\HttpTenantResolver;
use App\ApiAdmin\Tenancy\NullTenantResolver;
use App\ApiAdmin\Tenancy\TenantContext;
use App\ApiAdmin\Tenancy\TenantResolver;
use App\ApiAdmin\Tenancy\TenantRoutes;
use App\ApiAdmin\Tenancy\TenantSecretStore;
use App\MoonShine\ApiAdmin\Pages\InstructorPage;
use FilesystemIterator;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use MoonShine\Contracts\Core\DependencyInjection\CoreContract;
use MoonShine\Contracts\Core\ResourceContract;
use MoonShine\Laravel\Resources\ModelResource;
use Override;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

class ApiAdminServiceProvider extends ServiceProvider
{
    /**
     * Idempotency guards: the package ships with composer auto-discovery
     * (`extra.laravel.providers`) and can also be registered manually in
     * `bootstrap/providers.php`. If both are present — a common setup
     * during local testing — Laravel boots the provider twice and our
     * `registerGeneratedResources()` would append the same 10 resource
     * classes into MoonShine's core twice, so every menu entry shows up
     * duplicated. The boolean flags make the side-effecting bits run at
     * most once per process regardless of how many times Laravel invokes
     * the lifecycle hooks.
     */
    private static bool $servicesRegistered = false;

    private static bool $resourcesRegistered = false;

    private static bool $upstreamAuthProviderRegistered = false;

    #[Override]
    public function register(): void
    {
        if (self::$servicesRegistered) {
            return;
        }
        self::$servicesRegistered = true;

        $this->registerUpstreamAuthProvider();
        $this->registerTenantResolver();
        $this->registerServiceConfigs();
    }

    public function boot(): void
    {
        Route::matched(static function (RouteMatched $event): void {
            if (TenantRoutes::hostedMode()) {
                $tenant = $event->route->parameter('tenant');
                URL::defaults(['tenant' => is_string($tenant) ? $tenant : null]);
            }
        });
        RateLimiter::for('api-admin-mcp', static fn (Request $request): Limit => Limit::perMinute(60)->by((string) $request->ip()));
        RateLimiter::for('api-admin-browser', static fn (Request $request): Limit => Limit::perMinute(120)->by((string) $request->ip()));
        $this->overridePanelNameMacro();
        $this->registerRoutes();

        if (! self::$resourcesRegistered) {
            self::$resourcesRegistered = true;
            $this->registerGeneratedResources();
        }

        $this->registerApiAdminPages();

        $this->loadViewsFrom(resource_path('views/api-admin'), 'api-admin');

        if ($this->app->runningInConsole()) {
            $this->commands([
                ReloadCommand::class,
                TenantCommand::class,
                McpTokenCommand::class,
                TenantMembershipCommand::class,
                MigrateTenantSecretsCommand::class,
                McpServerCommand::class,
            ]);
        }
    }

    /**
     * Scan generated REST and local resource paths and register each
     * discovered class with MoonShine. We can't rely on the vendor
     * autoloader scan (`$core->autoload()`) alone because it walks
     * Composer's classmap — classes generated AFTER the last
     * `composer dump-autoload` aren't present there, so newly added
     * resources never show up in the panel menu without a blocking
     * classmap rebuild. Scanning the directory here closes that gap
     * without the 6s dump-autoload stall that made the MCP server miss
     * pings and flip to "failed".
     */
    protected function registerGeneratedResources(): void
    {
        /** @var CoreContract $core */
        $core = $this->app->make(CoreContract::class);
        $existing = $this->registeredResourceClasses($core);

        $restClasses = $this->scanGeneratedResourceClasses(
            root: $this->stringConfig('api-admin.resources_path', app_path('MoonShine/ApiAdmin/Resources')),
            namespace: 'App\\MoonShine\\ApiAdmin\\Resources',
            expectedParent: AbstractRestResource::class,
            label: 'rest',
        );
        $this->registerScannedResourceClasses($core, $restClasses, $existing, 'rest');

        $localClasses = $this->scanGeneratedResourceClasses(
            root: $this->stringConfig('api-admin.local_resources_path', app_path('MoonShine/Resources')),
            namespace: $this->stringConfig('api-admin.local_resources_namespace', 'App\\MoonShine\\Resources'),
            expectedParent: ModelResource::class,
            label: 'local',
        );
        $this->registerScannedResourceClasses($core, $localClasses, $existing, 'local');
    }

    /**
     * @param class-string $expectedParent
     *
     * @return list<class-string<ResourceContract>>
     */
    private function scanGeneratedResourceClasses(
        string $root,
        string $namespace,
        string $expectedParent,
        string $label,
    ): array {
        if (! is_dir($root)) {
            Log::debug('ApiAdminServiceProvider: generated resource path missing', [
                'kind' => $label,
                'path' => $root,
            ]);

            return [];
        }

        $classes = [];
        $scanned = 0;
        $skipped = 0;

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo) {
                continue;
            }
            if (! $file->isFile()) {
                continue;
            }
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $name = $file->getBasename('.php');
            if (! str_ends_with($name, 'Resource')) {
                continue;
            }

            $scanned++;
            $relative = substr($file->getPathname(), strlen($root) + 1, -4);
            $fqcn = trim($namespace, '\\').'\\'.str_replace(['/', '\\'], '\\', $relative);

            if (! class_exists($fqcn)) {
                $skipped++;
                Log::warning('ApiAdminServiceProvider: generated resource class could not be autoloaded', [
                    'kind' => $label,
                    'file' => $file->getPathname(),
                    'class' => $fqcn,
                ]);

                continue;
            }

            if (! is_subclass_of($fqcn, $expectedParent)) {
                $skipped++;
                Log::warning('ApiAdminServiceProvider: generated resource class has unexpected base class', [
                    'kind' => $label,
                    'file' => $file->getPathname(),
                    'class' => $fqcn,
                    'expected_parent' => $expectedParent,
                ]);

                continue;
            }

            /** @var class-string<ResourceContract> $fqcn */
            $classes[] = $fqcn;
        }

        Log::debug('ApiAdminServiceProvider: scanned generated resources', [
            'kind' => $label,
            'path' => $root,
            'scanned' => $scanned,
            'accepted' => count($classes),
            'skipped' => $skipped,
        ]);

        return $classes;
    }

    /**
     * @param list<class-string<ResourceContract>> $classes
     * @param array<string, true> $existing
     */
    private function registerScannedResourceClasses(
        CoreContract $core,
        array $classes,
        array &$existing,
        string $label,
    ): void {
        if ($classes === []) {
            return;
        }

        $newClasses = array_filter(
            $classes,
            static fn (string $fqcn): bool => ! isset($existing[$fqcn]),
        )
            |> array_values(...);

        if ($newClasses === []) {
            Log::debug('ApiAdminServiceProvider: generated resources already registered via autoload', [
                'kind' => $label,
                'count' => count($classes),
            ]);

            return;
        }

        $core->resources($newClasses);
        foreach ($newClasses as $fqcn) {
            $existing[$fqcn] = true;
        }

        $context = [
            'kind' => $label,
            'count' => count($newClasses),
            'skipped_already_present' => count($classes) - count($newClasses),
        ];

        if ($label === 'local') {
            Log::info('ApiAdminServiceProvider: registered generated local resources', $context);

            return;
        }

        Log::debug('ApiAdminServiceProvider: registered generated resources', $context);
    }

    /**
     * @return array<string, true>
     */
    private function registeredResourceClasses(CoreContract $core): array
    {
        $existing = [];
        foreach ($core->getResources()->toArray() as $resource) {
            if (\is_object($resource)) {
                $existing[$resource::class] = true;
            } elseif (is_string($resource)) {
                $existing[$resource] = true;
            }
        }

        return $existing;
    }

    private function stringConfig(string $key, string $default): string
    {
        $value = config($key, $default);

        return is_string($value) && $value !== '' ? $value : $default;
    }

    protected function registerApiAdminPages(): void
    {
        /** @var CoreContract $core */
        $core = $this->app->make(CoreContract::class);

        $existing = [];
        foreach ($core->getPages()->toArray() as $page) {
            if (\is_object($page)) {
                $existing[$page::class] = true;
            } elseif (is_string($page)) {
                $existing[$page] = true;
            }
        }

        if (isset($existing[InstructorPage::class])) {
            Log::debug('ApiAdminServiceProvider: api-admin pages already registered', [
                'pages' => [InstructorPage::class],
            ]);

            return;
        }

        $core->pages([InstructorPage::class]);

        Log::debug('ApiAdminServiceProvider: registered api-admin pages', [
            'pages' => [InstructorPage::class],
        ]);
    }

    /**
     * Replace MultiPanels' getPanelName macro with a longest-prefix matcher.
     *
     * Why: the vendor implementation only compares the first path segment,
     * which breaks nested prefixes like `admin/blog` (needed for a single
     * `/admin` entry point with per-service sub-panels).
     */
    protected function overridePanelNameMacro(): void
    {
        Request::macro('getPanelName', function (): string {
            $panelsRaw = moonshineConfig()->get('panels', []);
            /** @var array<string, array<string, mixed>> $panels */
            $panels = is_array($panelsRaw) ? $panelsRaw : [];
            $path = trim((string) request()->path(), '/');

            $panelKey = Collection::make($panels)
                ->mapWithKeys(function (array $panel, string $key): array {
                    $rawPrefix = $panel['prefix'] ?? $key;
                    $prefix = is_string($rawPrefix) ? trim($rawPrefix, '/') : $key;

                    return [$key => ['panel' => $panel, 'prefix' => $prefix]];
                })
                ->filter(fn (array $entry): bool => $entry['prefix'] !== ''
                    && TenantRoutes::pathMatchesPrefix($entry['prefix'], $path))
                ->sortByDesc(fn (array $entry): int => strlen($entry['prefix']))
                ->keys()
                ->first();

            if (is_string($panelKey)) {
                return $panelKey;
            }

            return (string) Str::of(request()->route()?->getName() ?? '')
                ->betweenFirst('moonshine.', '.');
        });
    }

    /**
     * Register the async search route for REST fields.
     */
    protected function registerRoutes(): void
    {
        Route::middleware(['web', 'throttle:api-admin-browser'])
            ->withoutMiddleware(PreventRequestForgery::class)
            ->post('broadcasting/auth', BroadcastAuthController::class)
            ->name('api-admin.broadcasting.auth');

        Route::middleware('web')
            ->withoutMiddleware(PreventRequestForgery::class)
            ->post($this->apiAdminRoutePrefix('api-admin/webhooks/{service}'), WebhookController::class)
            ->name('api-admin.webhooks.ingest');

        Route::middleware(['web', 'throttle:api-admin-browser'])
            ->get($this->apiAdminRoutePrefix('api-admin/async-search'), RestAsyncSearchController::class)
            ->name('api-admin.async-search');

        Route::middleware([EnsureHostedMcpEnabled::class, 'web', 'throttle:api-admin-mcp'])
            ->withoutMiddleware(PreventRequestForgery::class)
            ->post($this->apiAdminRoutePrefix($this->hostedMcpRoute()), HostedMcpController::class)
            ->name('api-admin.mcp.rpc');

        Route::middleware('web')
            ->prefix($this->apiAdminRoutePrefix('{panel}/oauth'))
            ->as('api-admin.oauth.')
            ->controller(OAuthAuthorizationController::class)
            ->group(function (): void {
                Route::get('/redirect', 'redirect')->name('redirect');
                Route::get('/callback', 'callback')->name('callback');
            });

        Route::middleware(['web', 'throttle:api-admin-browser'])
            ->prefix($this->apiAdminRoutePrefix('api-admin/instructor'))
            ->as('api-admin.instructor.')
            ->controller(InstructorController::class)
            ->group(function (): void {
                Route::get('/', 'show')->name('show');
                Route::post('/start', 'start')->name('start');
                Route::post('/confirm', 'confirm')->name('confirm');
                Route::post('/reset', 'reset')->name('reset');
            });

        // REST HasMany CRUD routes (form, list, store, update, delete)
        // Use 'web' middleware only — moonshine middleware includes MultiPanelMiddleware
        // which would try to resolve 'rest-has-many' as a panel name.
        Route::middleware(['web', 'throttle:api-admin-browser'])
            ->prefix($this->apiAdminRoutePrefix('rest-has-many'))
            ->as('moonshine.rest-has-many.')
            ->controller(RestHasManyController::class)
            ->group(function (): void {
                Route::get('form', 'formComponent')->name('form');
                Route::get('list', 'listComponent')->name('list');
                Route::post('store', 'store')->name('store');
                Route::put('update', 'update')->name('update');
                Route::delete('destroy', 'destroy')->name('destroy');
            });

        // REST HasOne CRUD routes (store, update, delete)
        Route::middleware(['web', 'throttle:api-admin-browser'])
            ->prefix($this->apiAdminRoutePrefix('rest-has-one'))
            ->as('moonshine.rest-has-one.')
            ->controller(RestHasOneController::class)
            ->group(function (): void {
                Route::post('store', 'store')->name('store');
                Route::put('update', 'update')->name('update');
                Route::delete('destroy', 'destroy')->name('destroy');
            });
    }

    private function apiAdminRoutePrefix(string $path): string
    {
        return TenantRoutes::scopedPrefix($path);
    }

    private function hostedMcpRoute(): string
    {
        $route = config('api-admin.hosted.mcp.route', 'api-admin/mcp');

        return is_string($route) && $route !== '' ? $route : 'api-admin/mcp';
    }

    /**
     * Register all ServiceConfig instances as singletons in the container.
     * Local services keep the legacy key: app('api-admin.service.{name}').
     * Hosted services are also bound as: app('api-admin.tenant.{tenant}.service.{name}').
     */
    protected function registerServiceConfigs(): void
    {
        $services = self::configuredServices();
        config(['api-admin.services' => $services]);

        foreach ($services as $name => $config) {
            $config = self::serviceTenantId($config) === TenantContext::LOCAL_ID
                ? self::applyEnvOverrides((string) $name, $config)
                : $config;
            $config['name'] ??= $name;
            $tenantId = self::serviceTenantId($config);
            $config['tenant_id'] = $tenantId;
            $kind = $this->serviceKind($config);

            if ($kind !== ServiceRegistry::SERVICE_KIND_REST) {
                Log::debug('ApiAdminServiceProvider: skipped non-REST service binding', [
                    'service' => (string) $name,
                    'tenant_id' => $tenantId,
                    'kind' => $kind,
                    'panel' => $config['panel'] ?? 'default',
                ]);

                continue;
            }

            $this->registerUpstreamGuard((string) $name, $config);
            $this->bindServiceConfig((string) $name, $tenantId, $config);

            $realtime = is_array($config['realtime'] ?? null) ? $config['realtime'] : [];

            Log::info("ApiAdminServiceProvider: registered service '{$name}'", [
                'tenant_id' => $tenantId,
                'kind' => $kind,
                'base_url' => $config['base_url'] ?? 'not set',
                'panel' => $config['panel'] ?? 'default',
                'realtime_enabled' => ($realtime['enabled'] ?? false) === true,
            ]);
        }
    }

    protected function registerUpstreamAuthProvider(): void
    {
        if (self::$upstreamAuthProviderRegistered) {
            return;
        }
        self::$upstreamAuthProviderRegistered = true;

        Auth::provider('api_admin_upstream', function ($app, array $config): UpstreamUserProvider {
            $string = static fn (string $key): string => is_string($config[$key] ?? null)
                ? $config[$key]
                : '';

            return new UpstreamUserProvider(
                serviceName: $string('service'),
                baseUrl: $string('base_url'),
                validateEndpoint: $string('validate_endpoint'),
                healthPath: $string('health_path'),
                mode: $string('mode') !== '' ? $string('mode') : 'delegated_token',
                tenantId: $string('tenant_id') !== '' ? $string('tenant_id') : TenantContext::LOCAL_ID,
            );
        });
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerUpstreamGuard(string $name, array $config): void
    {
        $adminAuth = is_array($config['admin_auth'] ?? null) ? $config['admin_auth'] : [];
        $mode = is_string($adminAuth['mode'] ?? null) ? $adminAuth['mode'] : 'moonshine';

        $usesUpstreamUsers = in_array($mode, ['delegated_token', 'oauth2_authorization_code'], true);

        // admin_login panels sign in against the upstream too, so they need their
        // own guard: sharing `moonshine` would tie them to the hub session.
        if (! $usesUpstreamUsers && ($config['admin_login'] ?? false) !== true) {
            return;
        }

        $panel = is_string($config['panel'] ?? null) ? $config['panel'] : $name.'-admin';
        $guard = UpstreamUserProvider::guardName($panel, self::serviceTenantId($config));
        $provider = $usesUpstreamUsers ? $guard : 'moonshine';

        if ($usesUpstreamUsers) {
            config([
                "auth.providers.{$provider}" => [
                    'driver' => 'api_admin_upstream',
                    'tenant_id' => self::serviceTenantId($config),
                    'service' => $name,
                    'base_url' => is_string($config['base_url'] ?? null) ? $config['base_url'] : '',
                    'validate_endpoint' => is_string($adminAuth['validate_endpoint'] ?? null) ? $adminAuth['validate_endpoint'] : '',
                    'health_path' => is_string($config['health_path'] ?? null) ? $config['health_path'] : '',
                    'mode' => $mode,
                ],
            ]);
        }

        config([
            "auth.guards.{$guard}" => [
                'driver' => 'session',
                'provider' => $provider,
            ],
        ]);

        $panels = config('moonshine.panels', []);
        if (! is_array($panels) || ! isset($panels[$panel]) || ! is_array($panels[$panel])) {
            Log::warning('ApiAdminServiceProvider: upstream guard registered without matching MoonShine panel', [
                'service' => $name,
                'panel' => $panel,
                'guard' => $guard,
            ]);

            return;
        }

        $baseAuth = config('moonshine.auth', []);
        $panelAuth = is_array($panels[$panel]['auth'] ?? null) ? $panels[$panel]['auth'] : [];
        $panels[$panel]['auth'] = [
            ...(is_array($baseAuth) ? $baseAuth : []),
            ...$panelAuth,
            'guard' => $guard,
        ];

        config(['moonshine.panels' => $panels]);
        if (function_exists('moonshineConfig')) {
            moonshineConfig()->set('panels', $panels);
        }

        Log::debug('ApiAdminServiceProvider: registered upstream panel guard', [
            'service' => $name,
            'panel' => $panel,
            'guard' => $guard,
            'mode' => $mode,
        ]);
    }

    protected function registerTenantResolver(): void
    {
        if ($this->app->bound(TenantResolver::class)) {
            return;
        }

        $this->app->singleton(TenantResolver::class, function (): TenantResolver {
            $mode = config('api-admin.hosted.mode', 'local');

            if ($mode === 'hosted') {
                /** @var Request $request */
                $request = app(Request::class);

                return new HttpTenantResolver($request);
            }

            return new NullTenantResolver;
        });
    }

    /**
     * @param array<string, mixed> $config
     */
    private function bindServiceConfig(string $name, string $tenantId, array $config): void
    {
        $this->app->singleton(
            self::tenantServiceBindingKey($name, $tenantId),
            fn (): ServiceConfig => ServiceConfig::fromArray(self::applyEnvOverrides($name, $config)),
        );

        Log::debug('ApiAdminServiceProvider: bound tenant service config', [
            'service' => $name,
            'tenant_id' => $tenantId,
            'binding' => self::tenantServiceBindingKey($name, $tenantId),
        ]);

        if ($tenantId === TenantContext::LOCAL_ID) {
            $this->app->singleton(
                self::legacyServiceBindingKey($name),
                fn (): ServiceConfig => ServiceConfig::fromArray(self::applyEnvOverrides($name, $config)),
            );
        }
    }

    private static function readEnv(string $key, mixed $default): mixed
    {
        $value = getenv($key);

        return $value === false ? $default : $value;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function configuredServices(): array
    {
        $servicesConfig = config('api-admin.services', []);
        /** @var array<string, array<string, mixed>> $services */
        $services = is_array($servicesConfig) ? $servicesConfig : [];

        return $services + self::generatedServices();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function generatedServices(): array
    {
        $path = config('api-admin.generated_services_path', storage_path('api-admin/generated/services.php'));

        if (! is_string($path) || ! is_file($path)) {
            return [];
        }

        $loaded = include $path;

        if (! is_array($loaded) || ! is_array($loaded['services'] ?? null)) {
            return [];
        }

        /** @var array<string, array<string, mixed>> $services */
        $services = array_filter($loaded['services'], is_array(...));

        return $services;
    }

    /**
     * Pull runtime values (api_key, base_url, health_path) from the
     * environment, falling back to whatever's literally in the aggregated
     * `config/api-admin.php`. This keeps secrets out of the committed
     * config file — .env is the source of truth for tokens — while still
     * letting ops override URLs per environment without regeneration.
     *
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private static function applyEnvOverrides(string $name, array $config): array
    {
        $envPrefix = str_replace('-', '_', $name)
            |> strtoupper(...);
        $tenantId = self::serviceTenantId($config);

        if ($tenantId !== TenantContext::LOCAL_ID) {
            if (! app()->bound('cache')) {
                return $config;
            }

            $secrets = new TenantSecretStore;

            $config['api_key'] = $secrets->get($tenantId, $name, 'api_key', '');
            $config['oauth_client_secret'] = $secrets->get($tenantId, $name, 'oauth_client_secret', '');
            $config['oauth_password'] = ($config['admin_login'] ?? false) === true
                ? ''
                : $secrets->get($tenantId, $name, 'oauth_password', '');
            $config['oauth_refresh_token'] = $secrets->get($tenantId, $name, 'oauth_refresh_token', '');
            $config['session_password'] = ($config['admin_login'] ?? false) === true
                ? ''
                : $secrets->get($tenantId, $name, 'session_password', '');

            $realtime = $config['realtime'] ?? null;
            if (is_array($realtime)) {
                $realtime['webhook_secret'] = $secrets->get($tenantId, $name, 'webhook_secret', '');
                $config['realtime'] = $realtime;
            }

            return $config;
        }

        // getenv() bypasses Laravel's cached-config gate that larastan flags
        // on env(): this path runs both at boot and on the rebindService()
        // hot-reload, where the Config repo is stale by design.
        $config['api_key'] = self::readEnv("{$envPrefix}_API_KEY", $config['api_key'] ?? '');
        $config['base_url'] = self::readEnv("{$envPrefix}_API_URL", $config['base_url'] ?? '');
        $config['health_path'] = self::readEnv("{$envPrefix}_HEALTH_PATH", $config['health_path'] ?? '');

        // Sensitive secrets stored only in .env, not in the committed config
        // file. Authoritative source for runtime; config holds an empty stub
        // so updateConfig stays deterministic across re-registrations.
        if (($config['admin_login'] ?? false) === true) {
            $config['oauth_password'] = '';
            $config['session_password'] = '';
        } else {
            $config['oauth_password'] = self::readEnv("{$envPrefix}_OAUTH_PASSWORD", $config['oauth_password'] ?? '');
            $config['session_password'] = self::readEnv("{$envPrefix}_SESSION_PASSWORD", $config['session_password'] ?? '');
        }

        $config['oauth_refresh_token'] = self::readEnv("{$envPrefix}_OAUTH_REFRESH_TOKEN", $config['oauth_refresh_token'] ?? '');

        $realtime = $config['realtime'] ?? null;
        if (is_array($realtime)) {
            $realtime['webhook_secret'] = self::readEnv(
                "{$envPrefix}_WEBHOOK_SECRET",
                $realtime['webhook_secret'] ?? '',
            );
            $config['realtime'] = $realtime;
        }

        return $config;
    }

    /**
     * Resolve a ServiceConfig by service name.
     */
    public static function resolveService(string $name, ?TenantContext $tenant = null): ServiceConfig
    {
        $tenant ??= self::currentTenantContext();

        $key = self::tenantServiceBindingKey($name, $tenant->id);

        if (app()->bound($key)) {
            $instance = app($key);
            if ($instance instanceof ServiceConfig) {
                Log::debug('ApiAdminServiceProvider: resolved tenant service', [
                    'service' => $name,
                    'tenant_id' => $tenant->id,
                ]);

                return $instance;
            }
        }

        if ($tenant->isLocal && app()->bound(self::legacyServiceBindingKey($name))) {
            $instance = app(self::legacyServiceBindingKey($name));
            if ($instance instanceof ServiceConfig) {
                return $instance;
            }
        }

        throw new RuntimeException("Service '{$name}' is not bound for tenant '{$tenant->id}'. Was register_service called?");
    }

    /**
     * Rebind a single service's ServiceConfig singleton from fresh config.
     * Called by register_service / remove_service AFTER writing .env + the
     * api-admin.php config so the running MCP worker picks up new values
     * without a container restart.
     *
     * Reads the config file directly (not via config() facade) because the
     * Config repository caches values loaded at boot — an in-process write
     * to config/api-admin.php isn't visible until we explicitly reload.
     */
    public static function rebindService(string $name): void
    {
        $services = self::configuredServices();

        // Keep the live Config repository in sync so config()/env()
        // consumers throughout the request see the new values too.
        config(['api-admin.services' => $services]);

        /** @var array<string, mixed>|null $config */
        $config = is_array($services[$name] ?? null) ? $services[$name] : null;

        if ($config === null) {
            // Service was removed — drop the binding so future resolve()
            // calls fail fast instead of returning stale data.
            app()->forgetInstance(self::legacyServiceBindingKey($name));
            app()->forgetInstance(self::tenantServiceBindingKey($name, TenantContext::LOCAL_ID));

            return;
        }

        $config = self::serviceTenantId($config) === TenantContext::LOCAL_ID
            ? self::applyEnvOverrides($name, $config)
            : $config;
        $config['name'] ??= $name;
        $tenantId = self::serviceTenantId($config);
        $config['tenant_id'] = $tenantId;

        app()->forgetInstance(self::legacyServiceBindingKey($name));
        app()->forgetInstance(self::tenantServiceBindingKey($name, $tenantId));
        app()->singleton(
            self::tenantServiceBindingKey($name, $tenantId),
            fn (): ServiceConfig => ServiceConfig::fromArray(self::applyEnvOverrides($name, $config)),
        );

        if ($tenantId === TenantContext::LOCAL_ID) {
            app()->singleton(
                self::legacyServiceBindingKey($name),
                fn (): ServiceConfig => ServiceConfig::fromArray(self::applyEnvOverrides($name, $config)),
            );
        }

        Log::debug('ApiAdminServiceProvider: rebound tenant service config', [
            'service' => $name,
            'tenant_id' => $tenantId,
            'binding' => self::tenantServiceBindingKey($name, $tenantId),
        ]);
    }

    private static function currentTenantContext(): TenantContext
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

    /**
     * @param array<string, mixed> $config
     */
    private static function serviceTenantId(array $config): string
    {
        $tenantId = $config['tenant_id'] ?? null;

        return is_string($tenantId) && $tenantId !== '' ? $tenantId : TenantContext::LOCAL_ID;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function serviceKind(array $config): string
    {
        $kind = $config['kind'] ?? $config['driver'] ?? ServiceRegistry::SERVICE_KIND_REST;

        if (! is_string($kind)) {
            return ServiceRegistry::SERVICE_KIND_REST;
        }

        return match (strtolower($kind)) {
            ServiceRegistry::SERVICE_KIND_LOCAL, 'eloquent' => ServiceRegistry::SERVICE_KIND_LOCAL,
            default => ServiceRegistry::SERVICE_KIND_REST,
        };
    }

    private static function legacyServiceBindingKey(string $name): string
    {
        return "api-admin.service.{$name}";
    }

    private static function tenantServiceBindingKey(string $name, string $tenantId): string
    {
        return "api-admin.tenant.{$tenantId}.service.{$name}";
    }
}
