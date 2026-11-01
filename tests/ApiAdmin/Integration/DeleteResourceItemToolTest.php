<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Integration;

use App\ApiAdmin\MCP\Tools\DeleteResourceItemTool;
use App\ApiAdmin\Providers\ApiAdminServiceProvider;
use App\ApiAdmin\ServiceConfig\ResponseMapping;
use App\ApiAdmin\ServiceConfig\ServiceConfig;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use MoonShine\Laravel\Providers\MoonShineServiceProvider;
use MoonShine\MultiPanels\Providers\MultiPanelServiceProvider;
use Orchestra\Testbench\TestCase;

final class DeleteResourceItemToolTest extends TestCase
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
        $app['config']->set('api-admin.resources_path', __DIR__.'/../Fixtures/Resources');
        $app['config']->set('api-admin.resources_namespace', 'Tests\\ApiAdmin\\Fixtures\\Resources');
    }

    protected function setUp(): void
    {
        parent::setUp();

        app()->forgetInstance('api-admin.service.blog');
        app()->instance(
            'api-admin.service.blog',
            new ServiceConfig(
                name: 'blog',
                baseUrl: 'https://api.blog.test/v1',
                apiKey: '',
                responseMapping: new ResponseMapping(primaryKey: 'id'),
                authType: 'bearer',
            ),
        );
    }

    public function test_without_confirm_returns_confirm_required(): void
    {
        $result = (new DeleteResourceItemTool)->execute([
            'service' => 'blog',
            'resource' => 'post',
            'id' => 1,
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('CONFIRM_REQUIRED', $result['code']);
    }

    public function test_with_confirm_false_returns_confirm_required(): void
    {
        $result = (new DeleteResourceItemTool)->execute([
            'service' => 'blog',
            'resource' => 'post',
            'id' => 1,
            'confirm' => false,
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('CONFIRM_REQUIRED', $result['code']);
    }

    public function test_with_confirm_true_deletes_item(): void
    {
        Http::fake([
            'https://api.blog.test/v1/posts/1' => Http::sequence()
                ->push(['id' => 1, 'title' => 'Old'], 200) // findItem pre-check
                ->push(null, 204), // DELETE
        ]);

        $result = (new DeleteResourceItemTool)->execute([
            'service' => 'blog',
            'resource' => 'post',
            'id' => 1,
            'confirm' => true,
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['deleted_id']);

        Http::assertSent(fn (Request $r): bool => $r->method() === 'DELETE'
            && str_contains($r->url(), '/posts/1'));
    }

    public function test_upstream_404_surfaces_as_not_found(): void
    {
        Http::fake([
            'https://api.blog.test/v1/posts/999' => Http::response(null, 404),
        ]);

        $result = (new DeleteResourceItemTool)->execute([
            'service' => 'blog',
            'resource' => 'post',
            'id' => 999,
            'confirm' => true,
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('NOT_FOUND', $result['code']);
    }
}
