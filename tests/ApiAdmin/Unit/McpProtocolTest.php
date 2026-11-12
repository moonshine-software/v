<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Unit;

use App\ApiAdmin\Console\McpServerCommand;
use App\ApiAdmin\MCP\McpServer;
use App\ApiAdmin\MCP\Tools\AddFieldTool;
use App\ApiAdmin\MCP\Tools\AggregateResourceTool;
use App\ApiAdmin\MCP\Tools\ConfigureLayoutTool;
use App\ApiAdmin\MCP\Tools\CountResourceTool;
use App\ApiAdmin\MCP\Tools\CreateModelResourceTool;
use App\ApiAdmin\MCP\Tools\CreateResourceTool;
use App\ApiAdmin\MCP\Tools\FetchResourceItemsTool;
use App\ApiAdmin\MCP\Tools\FetchResourceItemTool;
use App\ApiAdmin\MCP\Tools\HealthCheckTool;
use App\ApiAdmin\MCP\Tools\InitializeLocalServiceTool;
use App\ApiAdmin\MCP\Tools\InstructorStatusTool;
use App\ApiAdmin\MCP\Tools\ListResourcesTool;
use App\ApiAdmin\MCP\Tools\ListServicesTool;
use App\ApiAdmin\MCP\Tools\ProbeEndpointTool;
use App\ApiAdmin\MCP\Tools\RegisterServiceTool;
use App\ApiAdmin\MCP\Tools\RemoveResourceTool;
use App\ApiAdmin\MCP\Tools\RemoveServiceTool;
use App\ApiAdmin\MCP\Tools\UpdateResourceTool;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;

/**
 * Guard rails for the MCP stdio handshake contract with Claude Code / MCP
 * Inspector. When any of these fail, the symptom in Claude Code is usually
 * the MCP showing as connected but with zero tools, or the status flipping
 * to `failed` after a minute — both of which are very hard to debug from
 * the client side.
 */
final class McpProtocolTest extends TestCase
{
    /**
     * JSON Schema requires `properties` to be an object. An empty PHP array
     * encodes as JSON `[]` which fails strict validators (including Claude
     * Code's MCP client), dropping the entire tools/list response silently.
     */
    /**
     * @param array<string, mixed> $schema
     */
    #[DataProvider('toolSchemaProvider')]
    public function test_tool_schema_properties_encodes_as_json_object(string $toolName, array $schema): void
    {
        $encoded = json_encode($schema, JSON_UNESCAPED_SLASHES);
        $this->assertIsString($encoded, "{$toolName}: schema must be json-encodable");

        $decoded = json_decode($encoded, associative: false);
        $this->assertIsObject($decoded);
        $this->assertTrue(
            property_exists($decoded, 'inputSchema'),
            "{$toolName}: schema must have inputSchema",
        );
        /** @var object{inputSchema: object{properties?: mixed}} $decoded */
        $this->assertIsObject(
            $decoded->inputSchema->properties ?? null,
            "{$toolName}: inputSchema.properties must encode as JSON object, not array",
        );
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function toolSchemaProvider(): array
    {
        return [
            'register_service' => ['register_service', RegisterServiceTool::schema()],
            'initialize_local_service' => ['initialize_local_service', InitializeLocalServiceTool::schema()],
            'create_resource' => ['create_resource', CreateResourceTool::schema()],
            'create_model_resource' => ['create_model_resource', CreateModelResourceTool::schema()],
            'configure_layout' => ['configure_layout', ConfigureLayoutTool::schema()],
            'list_services' => ['list_services', ListServicesTool::schema()],
            'list_resources' => ['list_resources', ListResourcesTool::schema()],
            'remove_service' => ['remove_service', RemoveServiceTool::schema()],
            'remove_resource' => ['remove_resource', RemoveResourceTool::schema()],
            'probe_endpoint' => ['probe_endpoint', ProbeEndpointTool::schema()],
            'health_check' => ['health_check', HealthCheckTool::schema()],
            'update_resource' => ['update_resource', UpdateResourceTool::schema()],
            'fetch_resource_items' => ['fetch_resource_items', FetchResourceItemsTool::schema()],
            'fetch_resource_item' => ['fetch_resource_item', FetchResourceItemTool::schema()],
            'count_resource' => ['count_resource', CountResourceTool::schema()],
            'aggregate_resource' => ['aggregate_resource', AggregateResourceTool::schema()],
            'instructor_status' => ['instructor_status', InstructorStatusTool::schema()],
        ];
    }

    public function test_initialize_instructions_documents_delta_tools(): void
    {
        // Stdio handshake instructions that arrive on `initialize` must
        // mention every partial-delta tool + per-page array — that string
        // is the agent's only context after a fresh MCP connect, before
        // it caches tools/list. Drift here is silent: the agent loses
        // knowledge of these tools without any error.
        $command = new McpServerCommand;
        $reflect = new ReflectionMethod($command, 'agentInstructions');
        $instructions = $reflect->invoke($command);
        $this->assertIsString($instructions);

        $needles = [
            'add_field',
            'remove_field',
            'rename_field',
            'reorder_fields',
            'index_fields',
            'form_fields',
            'detail_fields',
            'count_resource',
            'aggregate_resource',
            'instructor_status',
            'target_service',
            'list_request',
            'response_mapping',
        ];
        foreach ($needles as $needle) {
            $this->assertStringContainsString(
                $needle,
                $instructions,
                "agentInstructions() must mention `{$needle}` so the agent receives it on initialize.",
            );
        }
    }

    public function test_relation_field_schemas_expose_target_service(): void
    {
        $createSchema = CreateResourceTool::schema();
        $createInput = $createSchema['inputSchema'];
        $createProps = $createInput['properties'];

        $fields = $createProps['fields'];
        $this->assertIsArray($fields);
        $fieldItems = $fields['items'];
        $this->assertIsArray($fieldItems);
        $fieldProps = $fieldItems['properties'];
        $this->assertIsArray($fieldProps);
        $this->assertArrayHasKey('target_service', $fieldProps);
        $fieldTarget = $fieldProps['target_service'];
        $this->assertIsArray($fieldTarget);
        $fieldDescription = $fieldTarget['description'];
        $this->assertIsString($fieldDescription);
        $this->assertStringContainsString('cross-service', $fieldDescription);

        $filters = $createProps['filters'];
        $this->assertIsArray($filters);
        $filterItems = $filters['items'];
        $this->assertIsArray($filterItems);
        $filterProps = $filterItems['properties'];
        $this->assertIsArray($filterProps);
        $this->assertArrayHasKey('target_service', $filterProps);
        $filterTarget = $filterProps['target_service'];
        $this->assertIsArray($filterTarget);
        $filterDescription = $filterTarget['description'];
        $this->assertIsString($filterDescription);
        $this->assertStringContainsString('cross-service', $filterDescription);

        $formFields = $createProps['form_fields'];
        $this->assertIsArray($formFields);
        $formFieldItems = $formFields['items'];
        $this->assertIsArray($formFieldItems);
        $pageFieldProps = $formFieldItems['properties'];
        $this->assertIsArray($pageFieldProps);
        $this->assertArrayHasKey('target_service', $pageFieldProps);

        $addSchema = AddFieldTool::schema();
        $addInput = $addSchema['inputSchema'];
        $this->assertIsArray($addInput);
        $addProps = $addInput['properties'];
        $this->assertIsArray($addProps);
        $addField = $addProps['field'];
        $this->assertIsArray($addField);
        $addFieldProps = $addField['properties'];
        $this->assertIsArray($addFieldProps);
        $this->assertArrayHasKey('target_service', $addFieldProps);
        $addTarget = $addFieldProps['target_service'];
        $this->assertIsArray($addTarget);
        $addDescription = $addTarget['description'];
        $this->assertIsString($addDescription);
        $this->assertStringContainsString('cross-service', $addDescription);
    }

    public function test_server_exposes_all_tools(): void
    {
        $server = new McpServer;
        $schemas = $server->getToolSchemas();

        $this->assertCount(35, $schemas);
        $this->assertSame([
            'register_service',
            'initialize_local_service',
            'create_resource',
            'create_model_resource',
            'configure_layout',
            'list_services',
            'list_resources',
            'get_resource',
            'remove_service',
            'remove_resource',
            'probe_endpoint',
            'health_check',
            'update_resource',
            'fetch_resource_items',
            'fetch_resource_item',
            'count_resource',
            'aggregate_resource',
            'instructor_status',
            'list_fields',
            'describe_field_type',
            'list_field_methods',
            'list_components',
            'create_resource_item',
            'update_resource_item',
            'delete_resource_item',
            'create_field',
            'update_field',
            'delete_field',
            'create_component',
            'update_component',
            'delete_component',
            'add_field',
            'remove_field',
            'rename_field',
            'reorder_fields',
        ], array_keys($schemas));
    }

    /**
     * End-to-end smoke test over real stdio: spawn the artisan command as a
     * subprocess, send initialize + ping + tools/list, and assert each reply.
     * This is the only test that catches protocol-level regressions in
     * McpServerCommand (ping handler, protocolVersion echo, capability shape).
     */
    public function test_stdio_handshake_ping_and_tools_list(): void
    {
        $phpBinary = PHP_BINARY;
        $artisan = realpath(__DIR__.'/../../../artisan');
        $this->assertNotFalse($artisan, 'artisan must be reachable from the package tests');

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(
            [$phpBinary, $artisan, 'api-admin:mcp-server'],
            $descriptors,
            $pipes,
        );

        $this->assertIsResource($process, 'could not spawn mcp-server subprocess');

        $requests = [
            json_encode([
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'initialize',
                'params' => ['protocolVersion' => '2025-06-18'],
            ]),
            json_encode([
                'jsonrpc' => '2.0',
                'id' => 42,
                'method' => 'ping',
                'params' => [],
            ]),
            json_encode([
                'jsonrpc' => '2.0',
                'id' => 2,
                'method' => 'tools/list',
                'params' => [],
            ]),
        ];

        foreach ($requests as $req) {
            fwrite($pipes[0], $req."\n");
        }

        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        proc_close($process);

        $lines = array_filter(explode("\n", (string) $stdout), static fn ($l): bool => $l !== '')
            |> array_values(...);
        $this->assertCount(3, $lines, "expected 3 JSON-RPC responses, got:\n".$stdout);

        /** @var list<array{id: int, result: array<string, mixed>}> $responses */
        $responses = array_map(static fn (string $l): mixed => json_decode($l, true), $lines);

        // 1. initialize echoes protocolVersion and exposes non-null tools capability
        $init = $responses[0];
        $this->assertSame(1, $init['id']);
        $this->assertSame('2025-06-18', $init['result']['protocolVersion']);
        $capabilities = $init['result']['capabilities'];
        $this->assertIsArray($capabilities);
        $this->assertArrayHasKey('tools', $capabilities);

        // 2. ping answers with empty result object (MCP liveness contract)
        $ping = $responses[1];
        $this->assertSame(42, $ping['id']);
        $this->assertArrayHasKey('result', $ping);
        $this->assertArrayNotHasKey('error', $ping, 'ping must not return an error — Claude Code marks the server failed otherwise');

        // 3. tools/list returns the full tool roster
        $list = $responses[2];
        $this->assertSame(2, $list['id']);
        $tools = $list['result']['tools'];
        $this->assertIsArray($tools);
        $this->assertCount(35, $tools);
    }

    /**
     * Exercises the resources/* surface: agents rely on `resources/list`
     * to discover the LLMS.txt cheatsheet and `resources/read` to
     * fetch it without re-parsing tool-schema descriptions each turn.
     */
    public function test_stdio_resources_list_and_read(): void
    {
        $phpBinary = PHP_BINARY;
        $artisan = realpath(__DIR__.'/../../../artisan');
        $this->assertNotFalse($artisan);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(
            [$phpBinary, $artisan, 'api-admin:mcp-server'],
            $descriptors,
            $pipes,
        );

        $this->assertIsResource($process);

        $requests = [
            json_encode([
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'initialize',
                'params' => ['protocolVersion' => '2025-06-18'],
            ]),
            json_encode([
                'jsonrpc' => '2.0',
                'id' => 10,
                'method' => 'resources/list',
                'params' => [],
            ]),
            json_encode([
                'jsonrpc' => '2.0',
                'id' => 11,
                'method' => 'resources/read',
                'params' => ['uri' => 'moonshine-v://llms'],
            ]),
        ];

        foreach ($requests as $req) {
            fwrite($pipes[0], $req."\n");
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $lines = array_filter(explode("\n", (string) $stdout), static fn ($l): bool => $l !== '')
            |> array_values(...);
        $this->assertCount(3, $lines, "expected 3 JSON-RPC responses, got:\n".$stdout);

        /** @var list<array{id: int, result: array<string, mixed>}> $responses */
        $responses = array_map(static fn (string $l): mixed => json_decode($l, true), $lines);

        // initialize must advertise a `resources` capability — without it
        // well-behaved MCP clients skip resources/list entirely.
        $init = $responses[0];
        $capabilities = $init['result']['capabilities'];
        $this->assertIsArray($capabilities);
        $this->assertArrayHasKey('resources', $capabilities);

        // resources/list: at least the LLMS cheatsheet.
        $listResult = $responses[1];
        $this->assertSame(10, $listResult['id']);
        $this->assertArrayHasKey('resources', $listResult['result']);
        $resources = $listResult['result']['resources'];
        $this->assertIsArray($resources);
        $this->assertNotEmpty($resources, 'at least one resource must be advertised');

        $uris = array_column($resources, 'uri');
        $this->assertContains('moonshine-v://llms', $uris);
        // Introspection snapshots must also be advertised so agents can
        // read the live field/component catalog without running tools.
        $this->assertContains('moonshine-v://fields', $uris);
        $this->assertContains('moonshine-v://components', $uris);
        $this->assertContains('moonshine-v://field-methods', $uris);

        // resources/read echoes the URI and returns the markdown body.
        $readResult = $responses[2];
        $this->assertSame(11, $readResult['id']);
        $this->assertArrayHasKey('contents', $readResult['result']);
        $contents = $readResult['result']['contents'];
        $this->assertIsArray($contents);
        $this->assertCount(1, $contents);
        $entry = $contents[0];
        $this->assertIsArray($entry);
        $this->assertSame('moonshine-v://llms', $entry['uri']);
        $this->assertSame('text/markdown', $entry['mimeType']);
        $this->assertIsString($entry['text']);
        $this->assertStringContainsString('moonshine-v', $entry['text']);
    }

    /**
     * The three introspection resources (fields / components /
     * field-methods) must round-trip over stdio and carry the same
     * payload their paired tool produces. Without this the documented
     * "read once per session" contract would silently decay the moment
     * a field was added to the catalog.
     */
    public function test_stdio_resources_read_introspection_snapshots(): void
    {
        $phpBinary = PHP_BINARY;
        $artisan = realpath(__DIR__.'/../../../artisan');
        $this->assertNotFalse($artisan);

        $uris = [
            'moonshine-v://fields',
            'moonshine-v://components',
            'moonshine-v://field-methods',
        ];

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(
            [$phpBinary, $artisan, 'api-admin:mcp-server'],
            $descriptors,
            $pipes,
        );
        $this->assertIsResource($process);

        $requests = [json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18'],
        ])];

        foreach ($uris as $i => $uri) {
            $requests[] = json_encode([
                'jsonrpc' => '2.0',
                'id' => 100 + $i,
                'method' => 'resources/read',
                'params' => ['uri' => $uri],
            ]);
        }

        foreach ($requests as $req) {
            fwrite($pipes[0], $req."\n");
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $lines = array_filter(explode("\n", (string) $stdout), static fn ($l): bool => $l !== '')
            |> array_values(...);
        $this->assertCount(count($requests), $lines);

        /** @var list<array{id: int, result: array<string, mixed>}> $responses */
        $responses = array_map(static fn (string $l): mixed => json_decode($l, true), $lines);

        // Skip the initialize response; assert each introspection read
        // returned a JSON object with success: true under the right URI.
        foreach ($uris as $i => $uri) {
            $readResult = $responses[$i + 1];
            $this->assertSame(100 + $i, $readResult['id']);
            $contents = $readResult['result']['contents'];
            $this->assertIsArray($contents);
            $this->assertCount(1, $contents);
            $entry = $contents[0];
            $this->assertIsArray($entry);
            $this->assertSame($uri, $entry['uri']);
            $this->assertSame('application/json', $entry['mimeType']);
            $text = $entry['text'];
            $this->assertIsString($text);
            /** @var mixed $decoded */
            $decoded = json_decode($text, true);
            $this->assertIsArray($decoded);
            $this->assertTrue($decoded['success'] ?? false, "Resource {$uri} must report success");
        }
    }
}
