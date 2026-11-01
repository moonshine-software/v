<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Integration;

use App\ApiAdmin\Providers\ApiAdminServiceProvider;
use App\ApiAdmin\Realtime\Events\UpstreamResourceMutated;
use App\ApiAdmin\ServiceConfig\ServiceConfig;
use App\ApiAdmin\Tenancy\TenantContext;
use App\ApiAdmin\Tenancy\TenantResolver;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use MoonShine\Laravel\Providers\MoonShineServiceProvider;
use Orchestra\Testbench\TestCase;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class RealtimeWebhookRouteTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            MoonShineServiceProvider::class,
            ApiAdminServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('api-admin.resources_path', sys_get_temp_dir().'/missing-api-admin-resources');
        $app['config']->set('api-admin.services', [
            'blog' => [
                'name' => 'blog',
                'base_url' => 'https://api.blog.test',
                'api_key' => '',
                'realtime' => [
                    'enabled' => true,
                    'webhook_secret' => 'webhook-secret',
                    'webhook_signature_header' => 'X-ApiAdmin-Signature',
                    'webhook_signature_algo' => 'sha256',
                ],
            ],
            'disabled' => [
                'name' => 'disabled',
                'base_url' => 'https://api.disabled.test',
                'api_key' => '',
                'realtime' => [
                    'enabled' => false,
                    'webhook_secret' => '',
                ],
            ],
        ]);
    }

    protected function setUp(): void
    {
        $this->resetProviderFlags();

        parent::setUp();

        $this->withoutMiddleware(VerifyCsrfToken::class);
        $this->bindConfiguredService('blog');
        $this->bindConfiguredService('disabled');
    }

    protected function tearDown(): void
    {
        $this->resetProviderFlags();

        parent::tearDown();
    }

    public function test_accepts_signed_webhook_and_dispatches_mutation_event(): void
    {
        Event::fake();

        $body = '{"event":"updated","resource":"posts","id":42,"token":"secret-value"}';
        $response = $this->signedWebhook('blog', $body);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('mutation.service', 'blog')
            ->assertJsonPath('mutation.resource', 'posts')
            ->assertJsonPath('mutation.action', 'updated')
            ->assertJsonPath('mutation.id', '42')
            ->assertJsonPath('mutation.metadata.payload.token', '[redacted]');

        Event::assertDispatched(
            UpstreamResourceMutated::class,
            fn (UpstreamResourceMutated $event): bool => $event->mutation->service === 'blog'
                && $event->mutation->resource === 'posts'
                && $event->mutation->action === 'updated'
                && $event->mutation->id === '42',
        );
    }

    public function test_rejects_unknown_service_before_payload_handling(): void
    {
        Event::fake();

        $response = $this->postJson('/api-admin/webhooks/missing', [
            'event' => 'updated',
            'resource' => 'posts',
        ]);

        $response
            ->assertNotFound()
            ->assertJsonPath('success', false);
        Event::assertNotDispatched(UpstreamResourceMutated::class);
    }

    public function test_rejects_disabled_service(): void
    {
        Event::fake();

        $response = $this->postJson('/api-admin/webhooks/disabled', [
            'event' => 'updated',
            'resource' => 'posts',
        ]);

        $response
            ->assertForbidden()
            ->assertJsonPath('success', false);
        Event::assertNotDispatched(UpstreamResourceMutated::class);
    }

    public function test_rejects_invalid_signature_before_normalization(): void
    {
        Event::fake();

        $response = $this->call(
            'POST',
            '/api-admin/webhooks/blog',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_APIADMIN_SIGNATURE' => 'sha256=bad',
            ],
            content: '{"event":"updated","resource":"posts"}',
        );

        $response
            ->assertForbidden()
            ->assertJsonPath('success', false);
        Event::assertNotDispatched(UpstreamResourceMutated::class);
    }

    public function test_rejects_cross_tenant_webhook_even_with_matching_service_secret(): void
    {
        Event::fake();
        config(['api-admin.services.blog.tenant_id' => 'tenant-a']);
        $this->bindConfiguredService('blog');
        $this->bindTenant('tenant-b');

        $response = $this->signedWebhook('blog', '{"event":"updated","resource":"posts"}');

        $response
            ->assertNotFound()
            ->assertJsonPath('success', false);
        Event::assertNotDispatched(UpstreamResourceMutated::class);
    }

    public function test_accepts_tenant_scoped_webhook_and_dispatches_tenant_mutation(): void
    {
        Event::fake();
        config(['api-admin.services.blog.tenant_id' => 'tenant-a']);
        $this->bindConfiguredService('blog');
        $this->bindTenant('tenant-a');

        $response = $this->signedWebhook('blog', '{"event":"updated","resource":"posts"}');

        $response
            ->assertOk()
            ->assertJsonPath('mutation.tenant_id', 'tenant-a');
        Event::assertDispatched(
            UpstreamResourceMutated::class,
            fn (UpstreamResourceMutated $event): bool => $event->mutation->tenantId === 'tenant-a',
        );
    }

    public function test_rejects_malformed_payload_without_dispatching_event(): void
    {
        Event::fake();

        $body = '{"event":"ignored","resource":"posts"';
        $response = $this->signedWebhook('blog', $body);

        $response
            ->assertUnprocessable()
            ->assertJsonPath('success', false);
        Event::assertNotDispatched(UpstreamResourceMutated::class);
    }

    /**
     * @return TestResponse<Response>
     */
    private function signedWebhook(string $service, string $body): TestResponse
    {
        return $this->call(
            'POST',
            "/api-admin/webhooks/{$service}",
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_APIADMIN_SIGNATURE' => 'sha256='.hash_hmac('sha256', $body, 'webhook-secret'),
            ],
            content: $body,
        );
    }

    private function resetProviderFlags(): void
    {
        $reflection = new ReflectionClass(ApiAdminServiceProvider::class);
        foreach (['servicesRegistered', 'resourcesRegistered'] as $name) {
            if ($reflection->hasProperty($name)) {
                $reflection->getProperty($name)->setValue(null, false);
            }
        }
    }

    private function bindConfiguredService(string $name): void
    {
        $config = $this->configuredServiceArray($name);

        app()->forgetInstance("api-admin.service.{$name}");
        app()->singleton(
            "api-admin.service.{$name}",
            static fn (): ServiceConfig => ServiceConfig::fromArray($config),
        );

        $tenantId = is_string($config['tenant_id'] ?? null) && $config['tenant_id'] !== ''
            ? $config['tenant_id']
            : TenantContext::LOCAL_ID;
        app()->forgetInstance("api-admin.tenant.{$tenantId}.service.{$name}");
        app()->singleton(
            "api-admin.tenant.{$tenantId}.service.{$name}",
            static fn (): ServiceConfig => ServiceConfig::fromArray($config),
        );
    }

    private function bindTenant(string $tenantId): void
    {
        app()->instance(TenantResolver::class, new readonly class($tenantId) implements TenantResolver
        {
            public function __construct(
                private string $tenantId,
            ) {}

            public function resolve(): TenantContext
            {
                return new TenantContext(
                    id: $this->tenantId,
                    slug: $this->tenantId,
                    name: $this->tenantId,
                    mode: 'hosted',
                    isLocal: false,
                );
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function configuredServiceArray(string $name): array
    {
        $config = config("api-admin.services.{$name}");
        if (! is_array($config)) {
            throw new RuntimeException("Test service [{$name}] is not configured.");
        }

        return $this->stringKeyedArray($config);
    }

    /**
     * @param array<mixed> $value
     *
     * @return array<string, mixed>
     */
    private function stringKeyedArray(array $value): array
    {
        $result = [];
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $result[$key] = $item;
            }
        }

        return $result;
    }
}
