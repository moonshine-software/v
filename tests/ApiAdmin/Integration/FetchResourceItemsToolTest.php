<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Integration;

use App\ApiAdmin\MCP\Tools\FetchResourceItemsTool;
use App\ApiAdmin\Providers\ApiAdminServiceProvider;
use App\ApiAdmin\ServiceConfig\ResponseMapping;
use App\ApiAdmin\ServiceConfig\ServiceConfig;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use MoonShine\Laravel\Providers\MoonShineServiceProvider;
use MoonShine\MultiPanels\Providers\MultiPanelServiceProvider;
use Orchestra\Testbench\TestCase;
use Tests\ApiAdmin\Fixtures\MockApi\BlogApiService;

/**
 * fetch_resource_items happy-path + passthrough coverage. Uses the
 * generated Blog/Post resource (standalone endpoint) and Blog/Comment
 * resource (relation endpoint with {parent_id}) so the tests hit the
 * same code the admin panel UI exercises.
 */
final class FetchResourceItemsToolTest extends TestCase
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
        // Fixtures stand in for generated resources, which the repository does not ship.
        $app['config']->set('api-admin.resources_path', __DIR__.'/../Fixtures/Resources');
        $app['config']->set('api-admin.resources_namespace', 'Tests\\ApiAdmin\\Fixtures\\Resources');
        // Same redirect for spec sidecars — ResourceGenerator::specSidecarPath
        // reads `api-admin.specs_path` before falling back to base_path.
        $app['config']->set('api-admin.specs_path', __DIR__.'/../Fixtures/Specs');
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Rebind the blog service with the fake-compatible base URL so
        // PostResource and CommentResource hit the mocked HTTP endpoints.
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

    public function test_length_aware_paginator_passthrough(): void
    {
        BlogApiService::fake();

        $result = (new FetchResourceItemsTool)->execute([
            'service' => 'blog',
            'resource' => 'post',
            'page' => 1,
            'per_page' => 25,
        ]);

        $this->assertTrue($result['success'], (string) json_encode($result));
        $this->assertSame('blog', $result['service']);
        $this->assertSame('post', $result['resource']);
        $this->assertSame('/posts', $result['endpoint']);

        $data = $result['data'];
        $this->assertIsArray($data);
        $this->assertIsArray($data['items']);
        $this->assertCount(3, $data['items']);
        $this->assertIsArray($data['items'][0]);
        $this->assertSame(1, $data['items'][0]['id']);
        $this->assertIsArray($data['pagination']);
        $this->assertSame('length_aware', $data['pagination']['strategy']);
        $this->assertSame(3, $data['pagination']['total']);
        $this->assertSame(1, $data['pagination']['current_page']);
        $this->assertSame(1, $data['pagination']['last_page']);
        $this->assertSame(25, $data['pagination']['per_page']);

        // Spec sidecar discovery hints — title has `sortable` in its
        // methods list; no `filters` declared.
        $this->assertIsArray($result['available_sorts']);
        $this->assertContains('title', $result['available_sorts']);
        $this->assertIsArray($result['available_filters']);
        $this->assertSame([], $result['available_filters']);
    }

    public function test_filter_is_emitted_in_nested_shape(): void
    {
        BlogApiService::fake();

        $result = (new FetchResourceItemsTool)->execute([
            'service' => 'blog',
            'resource' => 'post',
            'filter' => ['status' => 'published'],
            'sort' => '-id',
        ]);

        $this->assertTrue($result['success'], (string) json_encode($result));

        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'filter%5Bstatus%5D=published')
            && str_contains($r->url(), 'sort=-id'));
    }

    public function test_relation_resource_without_parent_context_errors(): void
    {
        BlogApiService::fake();

        $result = (new FetchResourceItemsTool)->execute([
            'service' => 'blog',
            'resource' => 'comment',
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('RELATION_RESOURCE_REQUIRES_PARENT', $result['code']);
        $this->assertSame(['parent_id'], $result['placeholders']);
    }

    public function test_relation_resource_with_parent_context_resolves_endpoint(): void
    {
        BlogApiService::fake();

        $result = (new FetchResourceItemsTool)->execute([
            'service' => 'blog',
            'resource' => 'comment',
            'parent_context' => ['parent_id' => 7],
        ]);

        $this->assertTrue($result['success'], (string) json_encode($result));
        $this->assertSame('/posts/7/comments', $result['endpoint']);
        $data = $result['data'];
        $this->assertIsArray($data);
        $this->assertIsArray($data['items']);

        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/posts/7/comments'));
    }

    public function test_parent_context_on_non_relation_resource_is_ignored_with_flag(): void
    {
        BlogApiService::fake();

        $result = (new FetchResourceItemsTool)->execute([
            'service' => 'blog',
            'resource' => 'post',
            'parent_context' => ['stray_key' => 'value'],
        ]);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['ignored_parent_context']);
    }

    public function test_upstream_5xx_surfaces_as_upstream_error_with_body(): void
    {
        Http::fake([
            'https://api.blog.test/v1/posts*' => Http::response(
                body: ['error' => 'db_exploded', 'trace_id' => 'abc'],
                status: 500,
            ),
        ]);

        $result = (new FetchResourceItemsTool)->execute([
            'service' => 'blog',
            'resource' => 'post',
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('UPSTREAM_ERROR', $result['code']);
        $this->assertSame(500, $result['status']);
        $errorBody = $result['error_body'];
        $this->assertIsArray($errorBody);
        $this->assertSame('db_exploded', $errorBody['error']);
    }

    public function test_endpoint_override_is_cleared_after_call_so_subsequent_call_rebinds(): void
    {
        BlogApiService::fake();

        (new FetchResourceItemsTool)->execute([
            'service' => 'blog',
            'resource' => 'comment',
            'parent_context' => ['parent_id' => 7],
        ]);

        // Second call with parent 42 — if override from prior call leaked,
        // endpoint would still be /posts/7/comments. Binder must
        // re-resolve each time.
        $second = (new FetchResourceItemsTool)->execute([
            'service' => 'blog',
            'resource' => 'comment',
            'parent_context' => ['parent_id' => 42],
        ]);

        $this->assertTrue($second['success']);
        $this->assertSame('/posts/42/comments', $second['endpoint']);
    }
}
