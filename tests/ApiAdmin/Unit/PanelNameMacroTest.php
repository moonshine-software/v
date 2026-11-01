<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Unit;

use App\ApiAdmin\Providers\ApiAdminServiceProvider;
use Illuminate\Http\Request;
use MoonShine\Laravel\Providers\MoonShineServiceProvider;
use MoonShine\MultiPanels\Providers\MultiPanelServiceProvider;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class PanelNameMacroTest extends TestCase
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
        $app['config']->set('moonshine.panels', [
            'admin' => ['prefix' => 'admin'],
            'blog-admin' => ['prefix' => 'admin/blog'],
            'shop-admin' => ['prefix' => 'admin/shop'],
        ]);
    }

    #[DataProvider('urlToPanelProvider')]
    public function test_resolves_panel_from_nested_prefix(string $path, string $expectedPanel): void
    {
        app()->instance('request', Request::create('/'.ltrim($path, '/'), 'GET'));

        $this->assertSame($expectedPanel, request()->getPanelName());
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function urlToPanelProvider(): iterable
    {
        yield 'hub root' => ['admin', 'admin'];
        yield 'blog root' => ['admin/blog', 'blog-admin'];
        yield 'shop root' => ['admin/shop', 'shop-admin'];
        yield 'blog nested' => ['admin/blog/resource/post-resource/crud', 'blog-admin'];
        yield 'shop nested' => ['admin/shop/page/dashboard', 'shop-admin'];
    }

    public function test_longest_prefix_wins_over_shorter(): void
    {
        app()->instance('request', Request::create('/admin/blog/anything', 'GET'));

        $this->assertSame('blog-admin', request()->getPanelName());
    }

    public function test_returns_empty_when_no_panel_matches(): void
    {
        app()->instance('request', Request::create('/unrelated/path', 'GET'));

        $this->assertSame('', request()->getPanelName());
    }
}
