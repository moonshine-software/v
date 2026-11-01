<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Integration;

use App\ApiAdmin\MCP\Tools\HealthCheckTool;
use App\ApiAdmin\Providers\ApiAdminServiceProvider;
use App\ApiAdmin\Tenancy\TenantContext;
use App\ApiAdmin\Tenancy\TenantResolver;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use MoonShine\Laravel\Providers\MoonShineServiceProvider;
use MoonShine\MultiPanels\Providers\MultiPanelServiceProvider;
use Orchestra\Testbench\TestCase;

final class HealthCheckToolTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            MoonShineServiceProvider::class,
            MultiPanelServiceProvider::class,
            ApiAdminServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('api-admin.hosted.mode', 'hosted');
        $app['config']->set('api-admin.http.allow_private_addresses', true);
        $app['config']->set('api-admin.services', [
            'blog' => [
                'name' => 'blog',
                'tenant_id' => '1',
                'base_url' => 'https://blog.test/api',
                'health_path' => '/health',
                'panel' => 'blog-admin',
            ],
            'shop' => [
                'name' => 'shop',
                'tenant_id' => '2',
                'base_url' => 'https://shop.test/api',
                'health_path' => '/health',
                'panel' => 'shop-admin',
            ],
        ]);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('session.driver', 'file');
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('broadcasting.default', 'log');
        $app['config']->set('moonshine.use_routes', false);
    }

    public function test_hosted_health_check_reports_only_current_tenant_services(): void
    {
        $this->bindTenant('1', 'acme');
        Http::fake([
            'https://blog.test/api/health' => Http::response(['ok' => true], 200),
            'https://shop.test/api/health' => Http::response(['ok' => true], 200),
        ]);

        $result = (new HealthCheckTool)->execute([]);
        $services = $result['services'] ?? null;
        $diagnostics = $result['diagnostics'] ?? null;

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['count']);
        $this->assertIsArray($services);
        $this->assertIsArray($services[0] ?? null);
        $this->assertSame('blog', $services[0]['service']);
        $this->assertSame('1', $services[0]['tenant_id']);
        $this->assertIsArray($diagnostics);
        $this->assertSame('hosted', $diagnostics['mode']);
        $this->assertNotEmpty($diagnostics['warnings']);

        Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'https://blog.test/api/health');
        Http::assertNotSent(fn (ClientRequest $request): bool => $request->url() === 'https://shop.test/api/health');
    }

    public function test_hosted_health_check_denies_service_outside_current_tenant(): void
    {
        $this->bindTenant('1', 'acme');

        $result = (new HealthCheckTool)->execute(['service' => 'shop']);

        $this->assertFalse($result['success']);
        $this->assertSame('SERVICE_NOT_REGISTERED', $result['code']);
    }

    private function bindTenant(string $id, string $slug): void
    {
        $tenant = new TenantContext($id, $slug, ucfirst($slug), 'hosted', false);

        app()->instance(TenantResolver::class, new readonly class($tenant) implements TenantResolver
        {
            public function __construct(
                private TenantContext $tenant,
            ) {}

            public function resolve(): TenantContext
            {
                return $this->tenant;
            }
        });
    }
}
