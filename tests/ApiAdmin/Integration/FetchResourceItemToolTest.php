<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Integration;

use App\ApiAdmin\MCP\Tools\FetchResourceItemTool;
use App\ApiAdmin\Providers\ApiAdminServiceProvider;
use App\ApiAdmin\ServiceConfig\ResponseMapping;
use App\ApiAdmin\ServiceConfig\ServiceConfig;
use Illuminate\Support\Facades\Http;
use MoonShine\Laravel\Providers\MoonShineServiceProvider;
use MoonShine\MultiPanels\Providers\MultiPanelServiceProvider;
use Orchestra\Testbench\TestCase;
use Tests\ApiAdmin\Fixtures\MockApi\BlogApiService;

final class FetchResourceItemToolTest extends TestCase
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
                responseMapping: new ResponseMapping(
                    dataKey: 'data',
                    primaryKey: 'id',
                ),
                authType: 'bearer',
            ),
        );
    }

    public function test_endpoint_strategy_returns_item_with_primary_key(): void
    {
        // Don't reuse BlogApiService::fake() for single-item — its `/posts*`
        // wildcard shadows `/posts/1` in fake-match order, so detail calls
        // pick up the list envelope instead of the item body.
        Http::fake([
            'https://api.blog.test/v1/posts/1' => Http::response([
                'id' => 1,
                'title' => 'First Post',
                'body' => 'Hello world',
            ]),
        ]);

        $result = (new FetchResourceItemTool)->execute([
            'service' => 'blog',
            'resource' => 'post',
            'id' => 1,
        ]);

        $this->assertTrue($result['success'], (string) json_encode($result));
        $item = $result['item'];
        $this->assertIsArray($item);
        $this->assertSame(1, $item['id']);
        $this->assertSame('First Post', $item['title']);
        $this->assertSame('/posts', $result['endpoint']);
    }

    public function test_returns_not_found_when_upstream_404s(): void
    {
        Http::fake([
            'https://api.blog.test/v1/posts/999' => Http::response(['message' => 'not found'], 404),
        ]);

        $result = (new FetchResourceItemTool)->execute([
            'service' => 'blog',
            'resource' => 'post',
            'id' => 999,
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('NOT_FOUND', $result['code']);
    }

    public function test_upstream_5xx_surfaces_error_body(): void
    {
        Http::fake([
            'https://api.blog.test/v1/posts/5' => Http::response(['error' => 'boom'], 503),
        ]);

        $result = (new FetchResourceItemTool)->execute([
            'service' => 'blog',
            'resource' => 'post',
            'id' => 5,
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('UPSTREAM_ERROR', $result['code']);
        $this->assertSame(503, $result['status']);
        $errorBody = $result['error_body'];
        $this->assertIsArray($errorBody);
        $this->assertSame('boom', $errorBody['error']);
    }

    public function test_relation_resource_requires_parent_context(): void
    {
        BlogApiService::fake();

        $result = (new FetchResourceItemTool)->execute([
            'service' => 'blog',
            'resource' => 'comment',
            'id' => 1,
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('RELATION_RESOURCE_REQUIRES_PARENT', $result['code']);
    }

    public function test_relation_resource_with_parent_context_resolves_endpoint(): void
    {
        Http::fake([
            'https://api.blog.test/v1/posts/7/comments/1' => Http::response(['data' => ['id' => 1, 'body' => 'Hi']]),
        ]);

        $result = (new FetchResourceItemTool)->execute([
            'service' => 'blog',
            'resource' => 'comment',
            'id' => 1,
            'parent_context' => ['parent_id' => 7],
        ]);

        $this->assertTrue($result['success'], (string) json_encode($result));
        $this->assertSame('/posts/7/comments', $result['endpoint']);
        $item = $result['item'];
        $this->assertIsArray($item);
        $this->assertSame(1, $item['id']);
        $this->assertSame('Hi', $item['body']);
    }
}
