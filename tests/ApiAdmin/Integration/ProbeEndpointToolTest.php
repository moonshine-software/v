<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Integration;

use App\ApiAdmin\MCP\Tools\ProbeEndpointTool;
use App\ApiAdmin\ServiceConfig\ResponseMapping;
use App\ApiAdmin\ServiceConfig\ServiceConfig;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use MoonShine\Laravel\Providers\MoonShineServiceProvider;
use Orchestra\Testbench\TestCase;

/**
 * Integration coverage for the probe_endpoint MCP tool.
 *
 * The tool is the AI agent's way to discover API shape before calling
 * create_resource — it must report columns it can see in the sample
 * payload, guess the envelope format, and (optionally) detect whether
 * the upstream filter query encoding is `nested` (?filter[x]=y) or
 * `flat` (?x=y). Without these hints the agent falls back to defaults
 * that silently break filter/sort UIs.
 */
final class ProbeEndpointToolTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [MoonShineServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Bind the 'tasks' service directly into the container, bypassing the
        // full service-provider boot. The probe tool only uses this resolver.
        app()->instance(
            'api-admin.service.tasks',
            new ServiceConfig(
                name: 'tasks',
                baseUrl: 'https://api.tasks.test/v1',
                apiKey: '',
                responseMapping: new ResponseMapping(dataKey: 'tasks'),
                authType: 'bearer',
            ),
        );
    }

    public function test_probe_returns_detected_columns_from_list_items(): void
    {
        Http::fake([
            'https://api.tasks.test/v1/tasks' => Http::response([
                'tasks' => [
                    ['id' => 'uuid-1', 'title' => 'A', 'status' => 'todo', 'assignee_id' => null],
                    ['id' => 'uuid-2', 'title' => 'B', 'status' => 'done', 'assignee_id' => 'alice'],
                ],
            ]),
        ]);

        $result = (new ProbeEndpointTool)->execute([
            'service' => 'tasks',
            'path' => '/tasks',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(['id', 'title', 'status', 'assignee_id'], $result['detected_columns']);
        $mapping = $result['guessed_mapping'];
        $this->assertIsArray($mapping);
        $this->assertSame('tasks', $mapping['data_key']);
    }

    public function test_probe_can_post_json_body_for_rpc_list_endpoint(): void
    {
        Http::fake(function (Request $request) {
            if ($request->method() !== 'POST') {
                return Http::response(['message' => 'Method Not Allowed', 'trace' => [['file' => 'bad.php']]], 405);
            }

            if (($request->data()['ids'] ?? null) !== [1, 2]) {
                return Http::response(['message' => 'Invalid body'], 422);
            }

            return Http::response([
                'data' => [
                    'customers' => [
                        ['id' => 1, 'email' => 'a@example.test'],
                        ['id' => 2, 'email' => 'b@example.test'],
                    ],
                ],
            ]);
        });

        $result = (new ProbeEndpointTool)->execute([
            'service' => 'tasks',
            'path' => '/customers/list',
            'method' => 'POST',
            'body' => ['ids' => [1, 2]],
        ]);

        $this->assertTrue($result['success'], (string) json_encode($result));
        $this->assertSame('POST', $result['method']);
        $this->assertSame(['id', 'email'], $result['detected_columns']);
        $mapping = $result['guessed_mapping'];
        $this->assertIsArray($mapping);
        $this->assertSame('data.customers', $mapping['data_key']);
    }

    public function test_probe_detects_flat_filter_format(): void
    {
        // Baseline 2 items. Only the flat-encoded filter narrows the list
        // — nested (?filter[status]=todo) gets ignored and returns baseline.
        Http::fake(function (Request $request) {
            $url = $request->url();
            $queryString = parse_url($url, PHP_URL_QUERY);
            $query = [];
            parse_str(is_string($queryString) ? $queryString : '', $query);

            if (($query['status'] ?? null) === 'todo') {
                return Http::response(['tasks' => [['id' => '1', 'status' => 'todo']]]);
            }

            return Http::response([
                'tasks' => [
                    ['id' => '1', 'status' => 'todo'],
                    ['id' => '2', 'status' => 'done'],
                ],
            ]);
        });

        $result = (new ProbeEndpointTool)->execute([
            'service' => 'tasks',
            'path' => '/tasks',
            'detect_filter_for' => ['column' => 'status', 'value' => 'todo'],
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('flat', $result['guessed_filter_format']);
        $detection = $result['filter_format_detection'];
        $this->assertIsArray($detection);
        $this->assertSame(2, $detection['baseline']);
        $this->assertSame(1, $detection['flat']);
        $this->assertSame(2, $detection['nested']);
    }

    public function test_probe_detects_nested_filter_format(): void
    {
        // Baseline 3 items. Only the nested form honors the filter.
        Http::fake(function (Request $request) {
            $url = $request->url();
            if (str_contains(rawurldecode($url), 'filter[status]=done')) {
                return Http::response(['tasks' => [['id' => '9', 'status' => 'done']]]);
            }

            return Http::response([
                'tasks' => [
                    ['id' => '1', 'status' => 'todo'],
                    ['id' => '2', 'status' => 'todo'],
                    ['id' => '9', 'status' => 'done'],
                ],
            ]);
        });

        $result = (new ProbeEndpointTool)->execute([
            'service' => 'tasks',
            'path' => '/tasks',
            'detect_filter_for' => ['column' => 'status', 'value' => 'done'],
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('nested', $result['guessed_filter_format']);
    }

    public function test_probe_leaves_filter_format_null_when_no_signal(): void
    {
        Http::fake([
            '*' => Http::response(['tasks' => [['id' => '1'], ['id' => '2']]]),
        ]);

        $result = (new ProbeEndpointTool)->execute([
            'service' => 'tasks',
            'path' => '/tasks',
            'detect_filter_for' => ['column' => 'status', 'value' => 'todo'],
        ]);

        $this->assertTrue($result['success']);
        $this->assertNull($result['guessed_filter_format']);
        $detection = $result['filter_format_detection'];
        $this->assertIsArray($detection);
        $this->assertSame(2, $detection['baseline']);
    }
}
