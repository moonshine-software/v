<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\ApiAdmin\Auth\EnsureDelegatedPanelState;
use App\ApiAdmin\Auth\SessionState;
use App\ApiAdmin\Auth\UpstreamUserProvider;
use App\ApiAdmin\Providers\ApiAdminServiceProvider;
use App\ApiAdmin\Tenancy\TenantKeyBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use MoonShine\Contracts\Core\DependencyInjection\ConfiguratorContract;
use MoonShine\Laravel\Http\Middleware\Authenticate;
use MoonShine\Laravel\Models\MoonshineUser;
use MoonShine\MultiPanels\Http\Middleware\MultiPanelMiddleware;
use ReflectionMethod;
use Tests\TestCase;

class DelegatedPanelStateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['api-admin.services.spa' => [
            'name' => 'spa',
            'kind' => 'rest',
            'base_url' => 'https://spa.test',
            'api_key' => '',
            'panel' => 'spa-admin',
            'auth_type' => 'session',
            'session_login_endpoint' => '/login',
            'admin_login' => true,
        ]]);
        config(['auth.guards.'.UpstreamUserProvider::guardName('spa-admin') => [
            'driver' => 'session',
            'provider' => 'moonshine',
        ]]);

        MoonshineUser::query()->create([
            'email' => 'admin',
            'password' => bcrypt('12345'),
            'name' => 'admin',
        ]);
    }

    public function test_delegated_panel_requires_its_own_session_state(): void
    {
        $panelGuard = UpstreamUserProvider::guardName('spa-admin');
        $this->actingAs($this->user(), 'moonshine');
        $this->actingAs($this->user(), $panelGuard);

        $response = app(EnsureDelegatedPanelState::class)->handle(
            $this->requestForSpaResource([
                TenantKeyBuilder::sessionKey('delegated-auth', 'oauth', 'oauth') => [
                    'access_token' => 'oauth-token',
                    'refresh_token' => 'oauth-refresh',
                    'expires_at' => time() + 3600,
                ],
            ]),
            static fn (): Response => new Response('ok'),
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringEndsWith('/spa-admin/login', (string) $response->headers->get('Location'));

        $this->assertFalse(Auth::guard($panelGuard)->check());
        $this->assertTrue(Auth::guard('moonshine')->check(), 'Entering a delegated panel must keep the hub session.');
    }

    public function test_panel_login_alone_passes_authentication(): void
    {
        $panelGuard = UpstreamUserProvider::guardName('spa-admin');
        config(['moonshine.panels.spa-admin' => [
            'prefix' => 'spa-admin',
            'auth' => ['enabled' => true, 'guard' => $panelGuard],
        ]]);
        // Tests resolve a fresh configurator per call; requests share one.
        $configurator = moonshineConfig();
        $configurator->set('panels', config('moonshine.panels'));
        app()->instance(ConfiguratorContract::class, $configurator);
        $this->actingAs($this->user(), $panelGuard);

        $request = Request::create('/spa-admin');
        app()->instance('request', $request);

        $response = app(Pipeline::class)
            ->send($request)
            ->through([MultiPanelMiddleware::class, Authenticate::class])
            ->then(static fn (): Response => new Response('ok'));

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame('ok', $response->getContent());
        $this->assertFalse(Auth::guard('moonshine')->check());
    }

    public function test_panel_configuration_is_applied_before_authentication(): void
    {
        $router = app(Router::class);
        $route = $router->getRoutes()->getByName('moonshine.admin.index');
        $this->assertInstanceOf(Route::class, $route);

        $middleware = $router->gatherRouteMiddleware($route);
        $panel = array_search(MultiPanelMiddleware::class, $middleware, true);
        $authentication = array_search(Authenticate::class, $middleware, true);

        $this->assertIsInt($panel);
        $this->assertIsInt($authentication);
        $this->assertLessThan($authentication, $panel);
    }

    public function test_upstream_subject_gets_its_own_account_with_unknown_password(): void
    {
        $provider = new UpstreamUserProvider(serviceName: 'spa', baseUrl: 'https://spa.test');

        $linked = $provider->linkAuthenticatedSubject('admin');

        $this->assertNotSame($this->user()->getKey(), $linked->getKey());
        $this->assertSame('spa-admin@api-admin.local', $linked->email);
        $this->assertFalse(Hash::check('12345', $linked->password));
        $this->assertSame($linked->getKey(), $provider->linkAuthenticatedSubject('admin')->getKey());
    }

    public function test_admin_login_service_gets_its_own_panel_guard(): void
    {
        config(['moonshine.panels.spa-admin' => ['prefix' => 'spa-admin', 'title' => 'Spa']]);
        $register = new ReflectionMethod(ApiAdminServiceProvider::class, 'registerUpstreamGuard');

        $register->invoke(new ApiAdminServiceProvider(app()), 'spa', ['panel' => 'spa-admin', 'admin_login' => true]);

        $guard = UpstreamUserProvider::guardName('spa-admin');
        $this->assertSame($guard, config('moonshine.panels.spa-admin.auth.guard'));
        $this->assertSame('moonshine', config("auth.guards.{$guard}.provider"));
    }

    public function test_delegated_panel_allows_matching_session_state(): void
    {
        $this->actingAs($this->user(), 'moonshine');

        $response = app(EnsureDelegatedPanelState::class)->handle(
            $this->requestForSpaResource([
                TenantKeyBuilder::sessionKey('delegated-auth', 'spa', 'session') => new SessionState(
                    cookies: ['session' => 'session-cookie'],
                    csrfToken: 'csrf-token',
                    expiresAt: time() + 3600,
                )->toArray(),
            ]),
            static fn (): Response => new Response('ok'),
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('ok', $response->getContent());
    }

    private function user(): MoonshineUser
    {
        return MoonshineUser::query()->where('email', 'admin')->firstOrFail();
    }

    /**
     * @param array<string, mixed> $sessionData
     */
    private function requestForSpaResource(array $sessionData = []): Request
    {
        $request = Request::create('/spa-admin/resource/spa-post-resource/post-index-page');
        $session = new Store('delegated-panel-state-test', new ArraySessionHandler(120));
        $session->start();

        foreach ($sessionData as $key => $value) {
            $session->put($key, $value);
        }

        $request->setLaravelSession($session);

        $route = new Route(['GET'], 'spa-admin/resource/{resourceUri}/{pageUri}/{resourceItem?}', []);
        $route->name('moonshine.spa-admin.resource.page');
        $request->setRouteResolver(static fn (): Route => $route);

        return $request;
    }
}
