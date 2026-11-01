<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Integration;

use App\ApiAdmin\Providers\ApiAdminServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use MoonShine\Laravel\Providers\MoonShineServiceProvider;
use MoonShine\MultiPanels\Providers\MultiPanelServiceProvider;
use Orchestra\Testbench\TestCase;

class TenantPanelRoutingTest extends TestCase
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
        $app['config']->set('api-admin.hosted.route_prefix', 't/{tenant}');
        $app['config']->set('moonshine.use_routes', false);
        $app['config']->set('moonshine.panels', [
            'admin' => [
                'prefix' => 't/{tenant}/admin',
                'title' => 'Admin',
                'pages' => [],
            ],
            'blog-admin' => [
                'prefix' => 't/{tenant}/blog-admin',
                'title' => 'Blog Admin',
            ],
        ]);
    }

    public function test_hosted_api_admin_routes_include_tenant_parameter(): void
    {
        $this->assertSame('t/{tenant}/api-admin/instructor', Route::getRoutes()->getByName('api-admin.instructor.show')?->uri());
        $this->assertSame('t/{tenant}/api-admin/async-search', Route::getRoutes()->getByName('api-admin.async-search')?->uri());
        $this->assertSame('t/{tenant}/api-admin/webhooks/{service}', Route::getRoutes()->getByName('api-admin.webhooks.ingest')?->uri());
    }

    public function test_panel_name_macro_matches_tenant_prefixes(): void
    {
        $request = Request::create('/t/acme/blog-admin/resource/blog-post-resource');
        app()->instance('request', $request);

        $this->assertSame('blog-admin', $request->getPanelName());
    }
}
