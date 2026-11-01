<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\ApiAdmin\Auth\UpstreamUserProvider;
use App\ApiAdmin\Realtime\Events\UpstreamResourceMutated;
use App\ApiAdmin\Resources\AbstractRestResource;
use App\ApiAdmin\ServiceConfig\ServiceConfig;
use App\ApiAdmin\Tenancy\HttpTenantResolver;
use App\ApiAdmin\Tenancy\TenantKeyBuilder;
use App\ApiAdmin\Tenancy\TenantResolver;
use App\Models\ApiAdminTenant;
use App\Models\MoonshineUserUpstreamIdentity;
use Illuminate\Auth\SessionGuard;
use Illuminate\Cache\ArrayStore;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use MoonShine\Contracts\Core\DependencyInjection\ConfiguratorContract;
use MoonShine\Laravel\Models\MoonshineUser;
use MoonShine\Laravel\Models\MoonshineUserRole;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\ApiAdmin\Fixtures\Resources\Tenants\Acme\Blog\Post\PostResource as TenantBlogPost;
use Tests\ApiAdmin\Fixtures\Resources\Tenants\Acme\Shop\Post\PostResource as TenantShopPost;
use Tests\TestCase;

final class HostedBrowserSecurityTest extends TestCase
{
    use RefreshDatabase;

    private MoonshineUser $member;

    private MoonshineUser $owner;

    public function createApplication(): Application
    {
        $app = require Application::inferBasePath().'/bootstrap/app.php';
        self::assertInstanceOf(Application::class, $app);
        $app->afterBootstrapping(LoadConfiguration::class, static function (Application $app): void {
            $app['config']->set('api-admin.hosted.mode', 'hosted');
            $app['config']->set('api-admin.services', []);
            $app['config']->set('api-admin.generated_services_path', '/nonexistent/hosted-browser-services.php');
            $app['config']->set('api-admin.generated_panels_path', '/nonexistent/hosted-browser-panels.php');
            $panels = $app['config']->get('moonshine.panels');
            self::assertIsArray($panels);
            self::assertIsArray($panels['admin']);
            $panels['admin']['prefix'] = 't/{tenant}/admin';
            $panels['secure-admin'] = [...$panels['admin'], 'prefix' => 't/{tenant}/secure-admin', 'title' => 'Secure'];
            $app['config']->set('moonshine.panels', $panels);
        });
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = ApiAdminTenant::query()->create(['slug' => 'acme', 'name' => 'Acme', 'active' => true]);
        $ownerRole = MoonshineUserRole::query()->firstOrCreate(['id' => 1], ['name' => 'Admin']);
        $memberRole = MoonshineUserRole::query()->firstOrCreate(['id' => 2], ['name' => 'Tenant Member']);
        $this->owner = MoonshineUser::query()->create([
            'email' => 'owner@example.test', 'name' => 'Platform Owner',
            'moonshine_user_role_id' => $ownerRole->getKey(), 'password' => bcrypt('owner-original-password'),
        ]);
        $this->member = MoonshineUser::query()->create([
            'email' => 'member@example.test', 'name' => 'Tenant Member',
            'moonshine_user_role_id' => $memberRole->getKey(), 'password' => bcrypt('member-password'),
        ]);
        DB::table('api_admin_tenant_memberships')->insert([
            'tenant_id' => $tenant->getKey(), 'moonshine_user_id' => $this->member->getKey(),
        ]);
        app()->bind(TenantResolver::class, static fn (): HttpTenantResolver => new HttpTenantResolver(request()));
        $this->bindService();
        moonshine()->resources([HostedBrowserRestResource::class]);
        Http::preventStrayRequests();
    }

    /** @return iterable<string, array{string, string}> */
    public static function platformEndpoints(): iterable
    {
        foreach (['user' => 'moon-shine-user', 'role' => 'moon-shine-user-role'] as $name => $uri) {
            $resource = $uri.'-resource';
            $index = $uri.'-index-page';
            $form = $uri.'-form-page';
            foreach (['GET', 'POST', 'PUT', 'DELETE'] as $method) {
                $suffix = in_array($method, ['PUT', 'DELETE'], true) ? '/1' : '';
                yield $name.' '.$method => [$method, "resource/{$resource}/crud{$suffix}"];
            }
            yield $name.' page' => ['GET', "resource/{$resource}/{$index}"];
            yield $name.' component' => ['GET', "component/{$index}/{$resource}"];
            yield $name.' method' => ['POST', "method/{$index}/{$resource}"];
            yield $name.' reactive' => ['POST', "reactive/{$form}/{$resource}/1"];
            yield $name.' search' => ['GET', "async-search/{$form}/{$resource}"];
            yield $name.' update field' => ['PUT', "update-field/column/{$resource}/1"];
            yield $name.' unattached page' => ['GET', "page/{$index}"];
            yield $name.' unattached component' => ['GET', "component/{$index}"];
            yield $name.' unattached method' => ['POST', "method/{$index}"];
            yield $name.' unknown resource' => ['GET', "component/{$index}/missing-resource"];
        }
    }

    #[DataProvider('platformEndpoints')]
    public function test_members_cannot_access_platform_resources_or_unattached_crud_pages(string $method, string $path): void
    {
        Http::fake();
        $this->actingAs($this->member, 'moonshine')
            ->json($method, '/t/acme/admin/'.$path, [
                'name' => 'Changed', 'email' => $this->owner->email, 'moonshine_user_role_id' => 1,
                'password' => 'changed-password', 'password_confirmation' => 'changed-password',
                '_without-redirect' => true,
            ])->assertForbidden();

        $this->assertSame('Platform Owner', $this->owner->fresh()?->name);
        $this->assertTrue(Hash::check('owner-original-password', (string) $this->owner->fresh()->password));
        $this->assertDatabaseCount('moonshine_users', 2);
        $this->assertDatabaseCount('moonshine_user_roles', 2);
        Http::assertNothingSent();
    }

    public function test_hub_login_profile_and_rest_reads_keep_the_tenant_in_urls(): void
    {
        $this->get('/t/acme/admin')->assertRedirect('/t/acme/admin/login');
        $this->get('/t/acme/admin/login')->assertOk()->assertSee('/t/acme/admin/authenticate', false);
        $this->post('/t/acme/admin/authenticate', ['username' => ' MEMBER@EXAMPLE.TEST ', 'password' => 'member-password'])
            ->assertRedirect('/t/acme/admin');
        $this->get('/t/acme/admin')->assertOk();
        $this->get('/t/acme/admin/page/profile-page')->assertOk();
        Http::fake(['https://auth.example.test/tasks*' => Http::response(['data' => [['id' => 7, 'title' => 'Visible task']]])]);
        $this->getJson('/t/acme/admin/resource/hosted-browser-rest-resource/crud')
            ->assertOk()->assertSee('Visible task');
    }

    public function test_native_resources_remain_available_in_trusted_local_mode(): void
    {
        config(['api-admin.hosted.mode' => 'local']);
        $this->actingAs($this->member, 'moonshine')
            ->getJson('/t/acme/admin/resource/moon-shine-user-resource/crud')
            ->assertOk()->assertSee('owner@example.test');
    }

    public function test_hosted_resources_with_the_same_name_route_to_their_own_service(): void
    {
        moonshine()->resources([TenantBlogPost::class, TenantShopPost::class], newCollection: true);
        Http::fake([
            'https://blog.example.test/posts*' => Http::response(['data' => [['id' => 1, 'title' => 'Blog post']]]),
            'https://shop.example.test/posts*' => Http::response(['data' => [['id' => 1, 'title' => 'Shop post']]]),
        ]);
        $this->actingAs($this->member, 'moonshine');

        $this->getJson('/t/acme/admin/resource/blog-post-resource/crud')->assertOk()->assertSee('Blog post');
        $this->getJson('/t/acme/admin/resource/shop-post-resource/crud')->assertOk()->assertSee('Shop post');

        Http::assertSentCount(2);
    }

    public function test_native_resources_cannot_be_selected_through_rest_relation_endpoints(): void
    {
        Http::fake();
        $this->actingAs($this->member, 'moonshine');
        $parameters = ['_resource' => 'moon-shine-user-resource', '_relation' => 'users', '_parent_id' => '1', '_key' => '1'];
        $this->getJson('/t/acme/api-admin/async-search?'.http_build_query($parameters))->assertNotFound();
        $this->postJson('/t/acme/rest-has-one/store', $parameters)->assertNotFound();
        $this->postJson('/t/acme/rest-has-many/store', $parameters)->assertNotFound();
        $this->assertDatabaseCount('moonshine_users', 2);
        Http::assertNothingSent();
    }

    public function test_hosted_webhook_resolves_the_service_after_the_tenant_route_parameter(): void
    {
        Event::fake([UpstreamResourceMutated::class]);
        $service = app('api-admin.tenant.1.service.secure');
        $this->assertInstanceOf(ServiceConfig::class, $service);
        $config = $service->toArray();
        $config['realtime'] = ['enabled' => true, 'webhook_secret' => 'hosted-webhook-secret'];
        app()->instance('api-admin.tenant.1.service.secure', ServiceConfig::fromArray($config));
        $body = '{"event":"updated","resource":"tasks","id":7}';

        $this->call('POST', '/t/acme/api-admin/webhooks/secure', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_APIADMIN_SIGNATURE' => hash_hmac('sha256', $body, 'hosted-webhook-secret'),
        ], content: $body)->assertOk()->assertJsonPath('mutation.service', 'secure');

        Event::assertDispatched(UpstreamResourceMutated::class, static fn (UpstreamResourceMutated $event): bool => $event->mutation->tenantId === '1');
    }

    public function test_delegated_login_rejects_a_foreign_origin_before_any_request(): void
    {
        $this->bindService(endpoint: 'http://127.0.0.1:9555/internal');
        config(['api-admin.http.allow_private_addresses' => false]);
        Http::fake();

        $this->postJson('/t/acme/secure-admin/authenticate', ['username' => 'synthetic-token', 'password' => 'unused'])
            ->assertUnprocessable()->assertJsonValidationErrors('username');

        Http::assertNothingSent();
        $this->assertGuest($this->panelGuard());
    }

    public function test_delegated_login_rejects_a_private_service_before_any_request(): void
    {
        $this->bindService(baseUrl: 'http://127.0.0.1:9555');
        config(['api-admin.http.allow_private_addresses' => false]);
        Http::fake();

        $this->postJson('/t/acme/secure-admin/authenticate', ['username' => 'synthetic-token', 'password' => 'unused'])
            ->assertUnprocessable()->assertJsonValidationErrors('username');

        Http::assertNothingSent();
    }

    public function test_delegated_login_succeeds_for_a_valid_tenant_identity(): void
    {
        $this->linkIdentity();
        Http::fake(['https://auth.example.test/me' => static fn (ClientRequest $request) => $request->hasHeader('Authorization', 'Bearer Case-Sensitive-PAT_AbC')
            ? Http::response(['id' => 'upstream-1', 'name' => 'Upstream Member'])
            : Http::response([], 401)]);

        $this->post('/t/acme/secure-admin/authenticate', ['username' => 'Case-Sensitive-PAT_AbC'])
            ->assertRedirect('/t/acme/secure-admin');

        $this->assertAuthenticatedAs($this->member, $this->panelGuard());
        $this->assertGuest('moonshine');
        Auth::forgetGuards();
        $this->get('/t/acme/secure-admin')->assertOk();
    }

    public function test_failed_pat_login_does_not_flash_or_cache_the_raw_token(): void
    {
        Http::fake(['https://auth.example.test/me' => Http::response([], 401)]);
        $token = 'Private-PAT-AbCd';

        $this->from('/t/acme/secure-admin/login')->post('/t/acme/secure-admin/authenticate', ['username' => $token])
            ->assertRedirect('/t/acme/secure-admin/login')->assertSessionMissing('_old_input.username');

        $store = Cache::getStore();
        $this->assertInstanceOf(ArrayStore::class, $store);
        $keys = array_keys($store->all());
        $this->assertNotEmpty($keys);
        foreach ($keys as $key) {
            $this->assertStringNotContainsString(strtolower($token), strtolower($key));
        }
        $this->get('/t/acme/secure-admin/login')->assertOk()->assertDontSee($token);
    }

    public function test_expired_delegated_state_cannot_restore_a_panel_session(): void
    {
        $this->linkIdentity();
        Http::fake();
        $this->get('/t/acme/secure-admin/login')->assertOk();
        $guard = Auth::guard($this->panelGuard());
        $this->assertInstanceOf(SessionGuard::class, $guard);

        $this->withSession([
            $guard->getName() => $this->member->getKey(),
            TenantKeyBuilder::sessionKey('delegated-auth', 'secure', 'token') => [
                'access_token' => 'expired-token', 'expires_at' => time() - 1,
            ],
        ])->get('/t/acme/secure-admin')->assertRedirect('/t/acme/secure-admin/login');

        Http::assertNothingSent();
    }

    public function test_revoked_pat_without_local_expiration_cannot_restore_a_panel_session(): void
    {
        $this->linkIdentity();
        Http::fake(['https://auth.example.test/me' => Http::response([], 401)]);
        $this->get('/t/acme/secure-admin/login')->assertOk();
        $guard = Auth::guard($this->panelGuard());
        $this->assertInstanceOf(SessionGuard::class, $guard);

        $this->withSession([
            $guard->getName() => $this->member->getKey(),
            TenantKeyBuilder::sessionKey('delegated-auth', 'secure', 'token') => [
                'access_token' => 'revoked-token', 'expires_at' => 0,
            ],
        ])->get('/t/acme/secure-admin')->assertRedirect('/t/acme/secure-admin/login');

        Http::assertSentCount(1);
        $this->assertGuest($this->panelGuard());
    }

    public function test_oauth_callback_logs_in_the_tenant_identity_and_restores_its_session(): void
    {
        $this->bindService(mode: 'oauth2_authorization_code');
        $this->linkIdentity();
        Http::fake([
            'https://auth.example.test/token' => Http::response(['access_token' => 'oauth-access', 'expires_in' => 600]),
            'https://auth.example.test/me' => Http::response(['id' => 'upstream-1', 'name' => 'OAuth Member']),
        ]);
        $redirect = $this->get('/t/acme/secure-admin/oauth/redirect')->assertRedirect();
        parse_str((string) parse_url((string) $redirect->headers->get('Location'), PHP_URL_QUERY), $query);

        $this->get('/t/acme/secure-admin/oauth/callback?'.http_build_query(['state' => $query['state'], 'code' => 'valid-code']))
            ->assertRedirect('/t/acme/secure-admin');

        $this->assertAuthenticatedAs($this->member, $this->panelGuard());
        $this->assertGuest('moonshine');
        Auth::forgetGuards();
        $this->get('/t/acme/secure-admin')->assertOk();
        $this->assertDatabaseHas('moonshine_user_upstream_identities', [
            'tenant_id' => '1', 'provider_service' => 'secure', 'subject_id' => 'upstream-1',
            'moonshine_user_id' => $this->member->getKey(),
        ]);
    }

    public function test_oauth_rejects_wrong_state_without_exchanging_a_code(): void
    {
        $this->bindService(mode: 'oauth2_authorization_code');
        Http::fake();
        $this->get('/t/acme/secure-admin/oauth/redirect')->assertRedirect();
        $this->get('/t/acme/secure-admin/oauth/callback?state=wrong&code=valid-code')->assertBadRequest();
        Http::assertNothingSent();
    }

    private function bindService(string $mode = 'delegated_token', string $endpoint = '/me', string $baseUrl = 'https://auth.example.test'): void
    {
        $config = [
            'name' => 'secure', 'tenant_id' => '1', 'panel' => 'secure-admin', 'base_url' => $baseUrl,
            'auth_type' => 'none', 'oauth_client_id' => 'synthetic-client',
            'admin_auth' => [
                'mode' => $mode, 'validate_endpoint' => $endpoint,
                'authorize_url' => 'https://auth.example.test/authorize', 'token_url' => 'https://auth.example.test/token',
            ],
        ];
        $auth = config('moonshine.auth');
        self::assertIsArray($auth);
        config([
            'api-admin.services' => ['secure' => $config],
            'auth.providers.hosted-browser' => ['driver' => 'hosted-browser'],
            'auth.guards.'.$this->panelGuard() => ['driver' => 'session', 'provider' => 'hosted-browser'],
            'moonshine.panels.secure-admin.auth' => [...$auth, 'guard' => $this->panelGuard()],
        ]);
        Auth::provider('hosted-browser', static fn (): UpstreamUserProvider => new UpstreamUserProvider(
            serviceName: 'secure', baseUrl: $baseUrl, validateEndpoint: $endpoint, mode: $mode, tenantId: '1',
        ));
        Auth::forgetGuards();
        app()->instance('api-admin.tenant.1.service.secure', ServiceConfig::fromArray($config));
        $configurator = moonshineConfig();
        $configurator->set('panels', config('moonshine.panels'));
        app()->instance(ConfiguratorContract::class, $configurator);
    }

    private function panelGuard(): string
    {
        return UpstreamUserProvider::guardName('secure-admin', '1');
    }

    private function linkIdentity(): void
    {
        MoonshineUserUpstreamIdentity::query()->create([
            'tenant_id' => '1', 'provider_service' => 'secure', 'subject_id' => 'upstream-1',
            'moonshine_user_id' => $this->member->getKey(),
        ]);
    }
}

class HostedBrowserRestResource extends AbstractRestResource
{
    protected function resolveServiceConfig(): ServiceConfig
    {
        return ServiceConfig::fromArray([
            'name' => 'tasks', 'tenant_id' => '1', 'base_url' => 'https://auth.example.test', 'auth_type' => 'none', 'panel' => 'admin',
        ]);
    }

    protected function endpoint(): string
    {
        return '/tasks';
    }
}
