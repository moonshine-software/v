<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\ApiAdmin\MCP\McpServer;
use App\ApiAdmin\ServiceConfig\ServiceConfig;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class NumericAggregateGroupsTest extends TestCase
{
    public function test_mcp_groups_numeric_values_and_numeric_strings_without_losing_zero(): void
    {
        config([
            'api-admin.resources_path' => base_path('tests/ApiAdmin/Fixtures/Resources'),
            'api-admin.resources_namespace' => 'Tests\\ApiAdmin\\Fixtures\\Resources',
        ]);
        app()->instance('api-admin.tenant.local.service.blog', ServiceConfig::fromArray([
            'name' => 'blog', 'base_url' => 'https://api.blog.test/v1', 'auth_type' => 'none',
        ]));
        Http::preventStrayRequests();
        Http::fake(['https://api.blog.test/v1/posts*' => Http::response([
            'data' => [
                ['id' => 1, 'status_id' => 0, 'amount' => 3],
                ['id' => 2, 'status_id' => '0', 'amount' => 5],
                ['id' => 3, 'status_id' => 2, 'amount' => 2],
                ['id' => 4, 'status_id' => '10', 'amount' => 10],
                ['id' => 5, 'status_id' => -1, 'amount' => 7],
            ],
            'total' => 5, 'per_page' => 200, 'current_page' => 1, 'last_page' => 1,
        ])]);
        $params = ['service' => 'blog', 'resource' => 'post', 'group_by' => 'status_id'];

        $count = new McpServer()->executeTool('aggregate_resource', [...$params, 'operation' => 'count']);

        $this->assertTrue($count['success'], json_encode($count, JSON_THROW_ON_ERROR));
        $this->assertSame([
            ['key' => '-1', 'value' => 1, 'count' => 1, 'skipped_non_numeric' => 0],
            ['key' => '0', 'value' => 2, 'count' => 2, 'skipped_non_numeric' => 0],
            ['key' => '10', 'value' => 1, 'count' => 1, 'skipped_non_numeric' => 0],
            ['key' => '2', 'value' => 1, 'count' => 1, 'skipped_non_numeric' => 0],
        ], $count['groups']);

        $sum = new McpServer()->executeTool('aggregate_resource', [
            ...$params, 'operation' => 'sum', 'field' => 'amount',
        ]);

        $this->assertTrue($sum['success'], json_encode($sum, JSON_THROW_ON_ERROR));
        $this->assertSame([
            ['key' => '-1', 'value' => 7.0, 'count' => 1, 'skipped_non_numeric' => 0],
            ['key' => '0', 'value' => 8.0, 'count' => 2, 'skipped_non_numeric' => 0],
            ['key' => '10', 'value' => 10.0, 'count' => 1, 'skipped_non_numeric' => 0],
            ['key' => '2', 'value' => 2.0, 'count' => 1, 'skipped_non_numeric' => 0],
        ], $sum['groups']);
        Http::assertSentCount(2);
    }
}
