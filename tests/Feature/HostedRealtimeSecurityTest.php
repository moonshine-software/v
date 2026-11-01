<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\ApiAdmin\Auth\UpstreamUserProvider;
use App\ApiAdmin\MCP\Tools\RegisterServiceTool;
use App\ApiAdmin\Realtime\Events\UpstreamResourceMutated;
use App\ApiAdmin\Realtime\UpstreamMutation;
use App\ApiAdmin\ServiceConfig\ServiceConfig;
use App\ApiAdmin\Tenancy\TenantContext;
use App\ApiAdmin\Tenancy\TenantKeyBuilder;
use App\ApiAdmin\Tenancy\TenantResolver;
use App\ApiAdmin\Tenancy\TenantSecretStore;
use App\Models\ApiAdminTenant;
use App\Models\MoonshineUserUpstreamIdentity;
use Illuminate\Auth\SessionGuard;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use MoonShine\Laravel\Models\MoonshineUser;
use Tests\TestCase;

final class HostedRealtimeSecurityTest extends TestCase
{
    use RefreshDatabase;

    private ApiAdminTenant $tenant;

    private MoonshineUser $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = ApiAdminTenant::query()->create(['slug' => 'acme', 'name' => 'Acme', 'active' => true]);
        $this->user = MoonshineUser::query()->create(['email' => 'realtime@example.test', 'name' => 'Realtime', 'password' => 'unknown']);
        config([
            'api-admin.hosted.mode' => 'hosted',
            'api-admin.services' => ['blog' => [
                'name' => 'blog', 'tenant_id' => $this->tenant->keyAsString(),
                'base_url' => 'https://example.test', 'panel' => 'blog-admin',
                'realtime' => ['enabled' => true, 'webhook_secret' => 'webhook-secret'],
            ]],
            'moonshine.panels.blog-admin.auth.guard' => 'moonshine',
            'broadcasting.default' => 'redis',
            'broadcasting.connections.redis' => ['driver' => 'redis'],
        ]);
        app()->instance('api-admin.tenant.'.$this->tenant->keyAsString().'.service.blog', $this->service('webhook-secret'));
        Broadcast::purge();
    }

    public function test_hosted_events_use_private_channels(): void
    {
        $event = new UpstreamResourceMutated(new UpstreamMutation(
            service: 'blog', resource: 'posts', action: 'updated', id: '42', tenantId: $this->tenant->keyAsString(),
        ));

        $this->assertInstanceOf(PrivateChannel::class, $event->broadcastOn()[0]);
        $this->assertSame($this->channel(), (string) $event->broadcastOn()[0]);
    }

    public function test_private_subscription_requires_membership_and_rejects_unknown_channels(): void
    {
        app()->instance('env', 'production');
        $this->postJson('/broadcasting/auth', $this->subscription())->assertUnauthorized();
        $this->actingAs($this->user, 'moonshine')->postJson('/broadcasting/auth', $this->subscription())->assertForbidden();
        $this->grantMembership();
        $this->postJson('/broadcasting/auth', $this->subscription())->assertOk()->assertContent('true');
        $this->postJson('/broadcasting/auth', $this->subscription('private-api-admin.tenant.unknown.blog.posts'))->assertForbidden();
        $this->postJson('/broadcasting/auth', $this->subscription('private-api-admin.tenant.'.TenantKeyBuilder::fingerprint($this->tenant->keyAsString()).'.other.posts'))->assertForbidden();
        $this->postJson('/broadcasting/auth', $this->subscription('api-admin.blog.posts'))->assertForbidden();
        $this->postJson('/broadcasting/auth', $this->subscription(str_replace('.posts', '.{resource}', $this->channel())))->assertForbidden();
        $this->postJson('/broadcasting/auth', $this->subscription(str_replace('.posts', '.*', $this->channel())))->assertForbidden();
        DB::table('api_admin_tenant_memberships')->where('tenant_id', $this->tenant->getKey())->delete();
        $this->postJson('/broadcasting/auth', $this->subscription())->assertForbidden();
    }

    public function test_other_tenant_membership_and_inactive_tenant_do_not_authorize_subscription(): void
    {
        $other = ApiAdminTenant::query()->create(['slug' => 'other', 'name' => 'Other', 'active' => true]);
        DB::table('api_admin_tenant_memberships')->insert(['tenant_id' => $other->getKey(), 'moonshine_user_id' => $this->user->getKey()]);
        $this->actingAs($this->user, 'moonshine')->postJson('/broadcasting/auth', $this->subscription())->assertForbidden();
        $this->grantMembership();
        $this->tenant->update(['active' => false]);
        $this->postJson('/broadcasting/auth', $this->subscription())->assertForbidden();
    }

    public function test_subscription_uses_panel_guard_without_hub_login(): void
    {
        $this->grantMembership();
        config([
            'auth.guards.realtime-panel' => ['driver' => 'session', 'provider' => 'moonshine'],
            'moonshine.panels.blog-admin.auth.guard' => 'realtime-panel',
        ]);

        $this->actingAs($this->user, 'realtime-panel')->postJson('/broadcasting/auth', $this->subscription())->assertOk();
        $this->assertGuest('moonshine');
    }

    public function test_delegated_guard_reads_the_channel_tenant_session_before_authentication(): void
    {
        app()->instance('env', 'production');
        $this->grantMembership();
        $tenantId = $this->tenant->keyAsString();
        $guard = UpstreamUserProvider::guardName('blog-admin', $tenantId);
        Auth::provider('realtime-upstream', static fn (): UpstreamUserProvider => new UpstreamUserProvider('blog', 'https://example.test', '/me', tenantId: $tenantId));
        config([
            "auth.providers.{$guard}" => ['driver' => 'realtime-upstream'],
            "auth.guards.{$guard}" => ['driver' => 'session', 'provider' => $guard],
            'moonshine.panels.blog-admin.auth.guard' => $guard,
        ]);
        app()->instance('api-admin.tenant.'.$tenantId.'.service.blog', $this->service('webhook-secret', [
            'admin_auth' => ['mode' => 'delegated_token', 'validate_endpoint' => '/me'],
        ]));
        MoonshineUserUpstreamIdentity::query()->create([
            'moonshine_user_id' => $this->user->getKey(), 'tenant_id' => $tenantId,
            'provider_service' => 'blog', 'subject_id' => 'upstream-42',
        ]);
        Http::fake(['https://example.test/me' => Http::response(['id' => 'upstream-42', 'email' => $this->user->email, 'name' => 'Realtime'])]);

        $sessionGuard = Auth::guard($guard);
        $this->assertInstanceOf(SessionGuard::class, $sessionGuard);
        $this->withSession([
            $sessionGuard->getName() => $this->user->getKey(),
            'api-admin.delegated-auth.tenant.'.TenantKeyBuilder::fingerprint($tenantId).'.blog.token' => ['access_token' => 'upstream-token'],
        ])->postJson('/broadcasting/auth', $this->subscription())->assertOk();

        Http::assertSentCount(1);
        $this->assertGuest('moonshine');
    }

    public function test_legacy_panel_without_delegated_state_cannot_subscribe(): void
    {
        app()->instance('env', 'production');
        $this->grantMembership();
        config([
            'auth.guards.realtime-panel' => ['driver' => 'session', 'provider' => 'moonshine'],
            'moonshine.panels.blog-admin.auth.guard' => 'realtime-panel',
        ]);
        app()->instance('api-admin.tenant.'.$this->tenant->keyAsString().'.service.blog', $this->service('webhook-secret', [
            'auth_type' => 'session', 'admin_login' => true,
        ]));

        $this->actingAs($this->user, 'realtime-panel')->postJson('/broadcasting/auth', $this->subscription())->assertUnauthorized();
    }

    public function test_hosted_webhook_rejects_missing_secret_and_invalid_signature_before_dispatch(): void
    {
        Event::fake([UpstreamResourceMutated::class]);
        $this->bindTenant();
        foreach (['', 'webhook-secret'] as $secret) {
            app()->instance('api-admin.tenant.'.$this->tenant->keyAsString().'.service.blog', $this->service($secret));
            $this->postJson('/api-admin/webhooks/blog', ['event' => 'updated', 'resource' => 'posts', 'id' => 42])->assertForbidden();
        }
        Event::assertNotDispatched(UpstreamResourceMutated::class);
    }

    public function test_hosted_signed_webhook_dispatches_a_private_tenant_event(): void
    {
        app()->instance('env', 'production');
        Event::fake([UpstreamResourceMutated::class]);
        $this->bindTenant();
        app()->instance('api-admin.tenant.'.$this->tenant->keyAsString().'.service.blog', $this->service('webhook-secret'));
        $body = '{"event":"updated","resource":"posts","id":42}';

        $this->call('POST', '/api-admin/webhooks/blog', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_APIADMIN_SIGNATURE' => 'sha256='.hash_hmac('sha256', $body, 'webhook-secret'),
        ], content: $body)->assertOk();

        Event::assertDispatched(UpstreamResourceMutated::class, fn (UpstreamResourceMutated $event): bool => (string) $event->broadcastOn()[0] === $this->channel());
    }

    public function test_hosted_registration_requires_and_preserves_webhook_secret(): void
    {
        $this->bindTenant();
        $directory = sys_get_temp_dir().'/moonshine-realtime-'.bin2hex(random_bytes(6));
        $configPath = app()->configPath();
        File::ensureDirectoryExists($directory.'/config');
        app()->useConfigPath($directory.'/config');
        File::put(config_path('api-admin.php'), '<?php return ["services" => []];');
        File::put(config_path('moonshine.php'), '<?php return ["panels" => []];');
        config([
            'api-admin.configs_path' => $directory.'/Configs',
            'api-admin.layouts_path' => $directory.'/Layouts',
            'api-admin.generated_services_path' => config_path('api-admin.php'),
            'api-admin.generated_panels_path' => $directory.'/panels.php',
        ]);
        $params = ['name' => 'realtime-check', 'base_url' => 'https://example.test', 'auth_type' => 'none', 'realtime' => ['enabled' => true]];

        try {
            $result = (new RegisterServiceTool)->execute($params);
            $this->assertFalse($result['success']);
            $this->assertSame('VALIDATION', $result['code']);
            $this->assertFileDoesNotExist($directory.'/panels.php');
            $this->assertDirectoryDoesNotExist($directory.'/Configs');

            $params['realtime']['webhook_secret'] = 'persisted-webhook-secret';
            $this->assertTrue((new RegisterServiceTool)->execute($params)['success']);
            unset($params['realtime']['webhook_secret']);
            $this->assertTrue((new RegisterServiceTool)->execute($params)['success']);
            $this->assertSame('persisted-webhook-secret', app(TenantSecretStore::class)->get($this->tenant->keyAsString(), 'realtime-check', 'webhook_secret'));

            $params['realtime']['webhook_secret'] = '';
            $this->assertFalse((new RegisterServiceTool)->execute($params)['success']);
            $this->assertSame('persisted-webhook-secret', app(TenantSecretStore::class)->get($this->tenant->keyAsString(), 'realtime-check', 'webhook_secret'));

            $params['realtime']['enabled'] = false;
            $this->assertTrue((new RegisterServiceTool)->execute($params)['success']);
            $this->assertSame('', app(TenantSecretStore::class)->get($this->tenant->keyAsString(), 'realtime-check', 'webhook_secret'));
        } finally {
            app()->useConfigPath($configPath);
            File::deleteDirectory($directory);
        }
    }

    private function grantMembership(): void
    {
        DB::table('api_admin_tenant_memberships')->insert(['tenant_id' => $this->tenant->getKey(), 'moonshine_user_id' => $this->user->getKey()]);
    }

    /** @param array<string, mixed> $overrides */
    private function service(string $secret, array $overrides = []): ServiceConfig
    {
        return ServiceConfig::fromArray([
            'name' => 'blog', 'tenant_id' => $this->tenant->keyAsString(),
            'base_url' => 'https://example.test', 'panel' => 'blog-admin',
            'realtime' => ['enabled' => true, 'webhook_secret' => $secret],
            ...$overrides,
        ]);
    }

    private function channel(): string
    {
        return 'private-'.TenantKeyBuilder::realtimeChannel($this->tenant->keyAsString(), 'blog', 'posts');
    }

    /** @return array{channel_name: string, socket_id: string} */
    private function subscription(?string $channel = null): array
    {
        return ['channel_name' => $channel ?? $this->channel(), 'socket_id' => '123.456'];
    }

    private function bindTenant(): void
    {
        app()->instance(TenantResolver::class, new readonly class($this->tenant) implements TenantResolver
        {
            public function __construct(private ApiAdminTenant $tenant) {}

            public function resolve(): TenantContext
            {
                return new TenantContext($this->tenant->keyAsString(), $this->tenant->slug, $this->tenant->name, 'hosted', false);
            }
        });
    }
}
