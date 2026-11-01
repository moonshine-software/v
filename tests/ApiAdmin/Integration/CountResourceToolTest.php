<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Integration;

use App\ApiAdmin\MCP\Tools\CountResourceTool;
use App\ApiAdmin\Providers\ApiAdminServiceProvider;
use App\ApiAdmin\ServiceConfig\ResponseMapping;
use App\ApiAdmin\ServiceConfig\ServiceConfig;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use MoonShine\Laravel\Providers\MoonShineServiceProvider;
use MoonShine\MultiPanels\Providers\MultiPanelServiceProvider;
use Orchestra\Testbench\TestCase;
use Tests\ApiAdmin\Fixtures\MockApi\BlogApiService;

final class CountResourceToolTest extends TestCase
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
                    totalKey: 'total',
                    perPageKey: 'per_page',
                    currentPageKey: 'current_page',
                    lastPageKey: 'last_page',
                    primaryKey: 'id',
                ),
                authType: 'bearer',
            ),
        );
    }

    public function test_counts_with_upstream_total(): void
    {
        BlogApiService::fake();

        $result = (new CountResourceTool)->execute([
            'service' => 'blog',
            'resource' => 'post',
        ]);

        $this->assertTrue($result['success'], (string) json_encode($result));
        $this->assertSame('/posts', $result['endpoint']);
        $this->assertSame(3, $result['count']);
        $this->assertSame('upstream_total', $result['source']);
    }

    public function test_time_window_is_emitted_as_nested_filter(): void
    {
        BlogApiService::fake();

        $result = (new CountResourceTool)->execute([
            'service' => 'blog',
            'resource' => 'post',
            'time_window' => [
                'field' => 'created_at',
                'from' => '2026-01-01T00:00:00Z',
                'to' => '2026-01-31T23:59:59Z',
            ],
        ]);

        $this->assertTrue($result['success'], (string) json_encode($result));
        $this->assertSame([
            'created_at' => [
                'from' => '2026-01-01T00:00:00Z',
                'to' => '2026-01-31T23:59:59Z',
            ],
        ], $result['applied_filters']);

        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'filter%5Bcreated_at%5D%5Bfrom%5D=2026-01-01T00%3A00%3A00Z')
            && str_contains($r->url(), 'filter%5Bcreated_at%5D%5Bto%5D=2026-01-31T23%3A59%3A59Z'));
    }

    public function test_relation_resource_requires_parent_context(): void
    {
        BlogApiService::fake();

        $result = (new CountResourceTool)->execute([
            'service' => 'blog',
            'resource' => 'comment',
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('RELATION_RESOURCE_REQUIRES_PARENT', $result['code']);
    }

    public function test_relation_resource_with_parent_context_counts_materialized_items(): void
    {
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
                    paginationType: 'none',
                ),
                authType: 'bearer',
            ),
        );

        Http::fake([
            '*comments*' => Http::response([
                'data' => [
                    ['id' => 1, 'author_name' => 'Alice', 'body' => 'Great post!'],
                    ['id' => 2, 'author_name' => 'Bob', 'body' => 'Thanks for sharing.'],
                ],
            ]),
        ]);

        $result = (new CountResourceTool)->execute([
            'service' => 'blog',
            'resource' => 'comment',
            'parent_context' => ['parent_id' => 7],
        ]);

        $this->assertTrue($result['success'], (string) json_encode($result));
        $this->assertSame('/posts/7/comments', $result['endpoint']);
        $this->assertSame(2, $result['count']);
        $this->assertSame('materialized_items', $result['source']);
    }
}
