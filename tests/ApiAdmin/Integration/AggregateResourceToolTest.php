<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Integration;

use App\ApiAdmin\MCP\Tools\AggregateResourceTool;
use App\ApiAdmin\Providers\ApiAdminServiceProvider;
use App\ApiAdmin\ServiceConfig\ResponseMapping;
use App\ApiAdmin\ServiceConfig\ServiceConfig;
use MoonShine\Laravel\Providers\MoonShineServiceProvider;
use MoonShine\MultiPanels\Providers\MultiPanelServiceProvider;
use Orchestra\Testbench\TestCase;
use Tests\ApiAdmin\Fixtures\MockApi\BlogApiService;

final class AggregateResourceToolTest extends TestCase
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

    public function test_aggregates_numeric_field(): void
    {
        BlogApiService::fake();

        $result = (new AggregateResourceTool)->execute([
            'service' => 'blog',
            'resource' => 'post',
            'operation' => 'max',
            'field' => 'id',
        ]);

        $this->assertTrue($result['success'], (string) json_encode($result));
        $this->assertSame('/posts', $result['endpoint']);
        $this->assertSame('max', $result['operation']);
        $this->assertSame(3.0, $result['result']);
        $this->assertSame(3, $result['count']);
    }

    public function test_groups_count_by_field(): void
    {
        BlogApiService::fake();

        $result = (new AggregateResourceTool)->execute([
            'service' => 'blog',
            'resource' => 'post',
            'operation' => 'count',
            'group_by' => 'status',
        ]);

        $this->assertTrue($result['success'], (string) json_encode($result));
        $groups = $result['groups'];
        $this->assertIsArray($groups);
        $this->assertCount(1, $groups);
        $first = $groups[0];
        $this->assertIsArray($first);
        $this->assertSame('published', $first['key']);
        $this->assertSame(3, $first['value']);
        $this->assertSame(3, $first['count']);
    }

    public function test_numeric_operation_without_field_returns_validation(): void
    {
        BlogApiService::fake();

        $result = (new AggregateResourceTool)->execute([
            'service' => 'blog',
            'resource' => 'post',
            'operation' => 'sum',
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('VALIDATION', $result['code']);
    }
}
