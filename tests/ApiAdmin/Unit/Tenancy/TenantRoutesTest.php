<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Unit\Tenancy;

use App\ApiAdmin\Tenancy\TenantContext;
use App\ApiAdmin\Tenancy\TenantRoutes;
use Orchestra\Testbench\TestCase;

class TenantRoutesTest extends TestCase
{
    public function test_local_mode_keeps_prefixes_unchanged(): void
    {
        config(['api-admin.hosted.mode' => 'local']);

        $this->assertSame('admin', TenantRoutes::scopedPrefix('admin'));
        $this->assertSame([], TenantRoutes::routeParams());
    }

    public function test_hosted_mode_scopes_and_expands_prefixes(): void
    {
        config([
            'api-admin.hosted.mode' => 'hosted',
            'api-admin.hosted.route_prefix' => 't/{tenant}',
        ]);

        $tenant = new TenantContext('1', 'acme', 'Acme', 'hosted', false);

        $this->assertSame('t/{tenant}/blog-admin', TenantRoutes::scopedPrefix('blog-admin'));
        $this->assertSame('t/acme/blog-admin', TenantRoutes::expandPrefix('t/{tenant}/blog-admin', $tenant));
        $this->assertTrue(TenantRoutes::pathMatchesPrefix('t/{tenant}/blog-admin', 't/acme/blog-admin/resource/posts'));
        $this->assertFalse(TenantRoutes::pathMatchesPrefix('t/{tenant}/blog-admin', 't/acme/shop-admin'));
    }

    public function test_moonshine_config_uses_hosted_panel_prefixes_from_env(): void
    {
        putenv('API_ADMIN_HOSTED_MODE=hosted');
        putenv('API_ADMIN_TENANT_ROUTE_PREFIX=tenant/{tenant}');

        try {
            $config = include getcwd().'/config/moonshine.php';
        } finally {
            putenv('API_ADMIN_HOSTED_MODE');
            putenv('API_ADMIN_TENANT_ROUTE_PREFIX');
        }

        $this->assertIsArray($config);
        $panels = $config['panels'] ?? null;
        $this->assertIsArray($panels);
        $admin = $panels['admin'] ?? null;
        $this->assertIsArray($admin);
        $this->assertSame('tenant/{tenant}/admin', $admin['prefix']);
    }
}
