<?php

declare(strict_types=1);

namespace App\ApiAdmin\MCP;

use App\ApiAdmin\MCP\Tools\AddFieldTool;
use App\ApiAdmin\MCP\Tools\AggregateResourceTool;
use App\ApiAdmin\MCP\Tools\ConfigureLayoutTool;
use App\ApiAdmin\MCP\Tools\CountResourceTool;
use App\ApiAdmin\MCP\Tools\CreateComponentTool;
use App\ApiAdmin\MCP\Tools\CreateFieldTool;
use App\ApiAdmin\MCP\Tools\CreateModelResourceTool;
use App\ApiAdmin\MCP\Tools\CreateResourceItemTool;
use App\ApiAdmin\MCP\Tools\CreateResourceTool;
use App\ApiAdmin\MCP\Tools\DeleteComponentTool;
use App\ApiAdmin\MCP\Tools\DeleteFieldTool;
use App\ApiAdmin\MCP\Tools\DeleteResourceItemTool;
use App\ApiAdmin\MCP\Tools\DescribeFieldTypeTool;
use App\ApiAdmin\MCP\Tools\FetchResourceItemsTool;
use App\ApiAdmin\MCP\Tools\FetchResourceItemTool;
use App\ApiAdmin\MCP\Tools\GetResourceTool;
use App\ApiAdmin\MCP\Tools\HealthCheckTool;
use App\ApiAdmin\MCP\Tools\InitializeLocalServiceTool;
use App\ApiAdmin\MCP\Tools\InstructorStatusTool;
use App\ApiAdmin\MCP\Tools\ListComponentsTool;
use App\ApiAdmin\MCP\Tools\ListFieldMethodsTool;
use App\ApiAdmin\MCP\Tools\ListFieldsTool;
use App\ApiAdmin\MCP\Tools\ListResourcesTool;
use App\ApiAdmin\MCP\Tools\ListServicesTool;
use App\ApiAdmin\MCP\Tools\ProbeEndpointTool;
use App\ApiAdmin\MCP\Tools\RegisterServiceTool;
use App\ApiAdmin\MCP\Tools\RemoveFieldTool;
use App\ApiAdmin\MCP\Tools\RemoveResourceTool;
use App\ApiAdmin\MCP\Tools\RemoveServiceTool;
use App\ApiAdmin\MCP\Tools\RenameFieldTool;
use App\ApiAdmin\MCP\Tools\ReorderFieldsTool;
use App\ApiAdmin\MCP\Tools\ToolContract;
use App\ApiAdmin\MCP\Tools\UpdateComponentTool;
use App\ApiAdmin\MCP\Tools\UpdateFieldTool;
use App\ApiAdmin\MCP\Tools\UpdateResourceItemTool;
use App\ApiAdmin\MCP\Tools\UpdateResourceTool;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * MCP Server for AI-driven admin panel management.
 *
 * Provides tools for the AI agent to:
 * - Register microservices (register_service)
 * - Create REST resources with fields (create_resource)
 * - Configure panel layouts (configure_layout)
 *
 * This is the entry point for MCP tool dispatch.
 */
final class McpServer
{
    /** Kept equal to the version in package.json. */
    public const string VERSION = '0.1.0';

    private const array GENERATION_TOOLS = [
        'register_service', 'initialize_local_service', 'create_resource',
        'create_model_resource', 'configure_layout', 'remove_service',
        'remove_resource', 'update_resource', 'create_field', 'update_field',
        'delete_field', 'create_component', 'update_component', 'delete_component',
        'add_field', 'remove_field', 'rename_field', 'reorder_fields',
    ];

    /** @var array<string, ToolContract> */
    private array $tools;

    public function __construct(
        private readonly McpAuthorizationGate $authorizationGate = new McpAuthorizationGate,
    ) {
        Log::debug('McpServer: initializing');

        $this->registerTools();
    }

    /**
     * Register all available MCP tools.
     */
    private function registerTools(): void
    {
        $this->tools = [
            'register_service' => new RegisterServiceTool,
            'initialize_local_service' => new InitializeLocalServiceTool,
            'create_resource' => new CreateResourceTool,
            'create_model_resource' => new CreateModelResourceTool,
            'configure_layout' => new ConfigureLayoutTool,
            'list_services' => new ListServicesTool,
            'list_resources' => new ListResourcesTool,
            'get_resource' => new GetResourceTool,
            'remove_service' => new RemoveServiceTool,
            'remove_resource' => new RemoveResourceTool,
            'probe_endpoint' => new ProbeEndpointTool,
            'health_check' => new HealthCheckTool,
            'update_resource' => new UpdateResourceTool,
            'fetch_resource_items' => new FetchResourceItemsTool,
            'fetch_resource_item' => new FetchResourceItemTool,
            // Phase 5 — analytics
            'count_resource' => new CountResourceTool,
            'aggregate_resource' => new AggregateResourceTool,
            // Built-in instructor agent
            'instructor_status' => new InstructorStatusTool,
            // Phase 1 — introspection
            'list_fields' => new ListFieldsTool,
            'describe_field_type' => new DescribeFieldTypeTool,
            'list_field_methods' => new ListFieldMethodsTool,
            'list_components' => new ListComponentsTool,
            // Phase 2 — resource-item CRUD
            'create_resource_item' => new CreateResourceItemTool,
            'update_resource_item' => new UpdateResourceItemTool,
            'delete_resource_item' => new DeleteResourceItemTool,
            // Phase 3 — custom field/component generation
            'create_field' => new CreateFieldTool,
            'update_field' => new UpdateFieldTool,
            'delete_field' => new DeleteFieldTool,
            'create_component' => new CreateComponentTool,
            'update_component' => new UpdateComponentTool,
            'delete_component' => new DeleteComponentTool,
            // Phase 4 — partial resource deltas
            'add_field' => new AddFieldTool,
            'remove_field' => new RemoveFieldTool,
            'rename_field' => new RenameFieldTool,
            'reorder_fields' => new ReorderFieldsTool,
        ];

        Log::info('McpServer: tools registered', [
            'tools' => array_keys($this->tools),
        ]);
    }

    /**
     * Get the tool schema definitions for MCP protocol.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getToolSchemas(): array
    {
        return [
            'register_service' => RegisterServiceTool::schema(),
            'initialize_local_service' => InitializeLocalServiceTool::schema(),
            'create_resource' => CreateResourceTool::schema(),
            'create_model_resource' => CreateModelResourceTool::schema(),
            'configure_layout' => ConfigureLayoutTool::schema(),
            'list_services' => ListServicesTool::schema(),
            'list_resources' => ListResourcesTool::schema(),
            'get_resource' => GetResourceTool::schema(),
            'remove_service' => RemoveServiceTool::schema(),
            'remove_resource' => RemoveResourceTool::schema(),
            'probe_endpoint' => ProbeEndpointTool::schema(),
            'health_check' => HealthCheckTool::schema(),
            'update_resource' => UpdateResourceTool::schema(),
            'fetch_resource_items' => FetchResourceItemsTool::schema(),
            'fetch_resource_item' => FetchResourceItemTool::schema(),
            'count_resource' => CountResourceTool::schema(),
            'aggregate_resource' => AggregateResourceTool::schema(),
            'instructor_status' => InstructorStatusTool::schema(),
            'list_fields' => ListFieldsTool::schema(),
            'describe_field_type' => DescribeFieldTypeTool::schema(),
            'list_field_methods' => ListFieldMethodsTool::schema(),
            'list_components' => ListComponentsTool::schema(),
            'create_resource_item' => CreateResourceItemTool::schema(),
            'update_resource_item' => UpdateResourceItemTool::schema(),
            'delete_resource_item' => DeleteResourceItemTool::schema(),
            'create_field' => CreateFieldTool::schema(),
            'update_field' => UpdateFieldTool::schema(),
            'delete_field' => DeleteFieldTool::schema(),
            'create_component' => CreateComponentTool::schema(),
            'update_component' => UpdateComponentTool::schema(),
            'delete_component' => DeleteComponentTool::schema(),
            'add_field' => AddFieldTool::schema(),
            'remove_field' => RemoveFieldTool::schema(),
            'rename_field' => RenameFieldTool::schema(),
            'reorder_fields' => ReorderFieldsTool::schema(),
        ];
    }

    /**
     * Execute an MCP tool by name.
     *
     * @param array<string, mixed> $params
     * @param list<string>|null $scopes
     *
     * @return array<string, mixed> Tool execution result
     */
    public function executeTool(string $toolName, array $params, ?array $scopes = null): array
    {
        Log::info('McpServer::executeTool', [
            'tool' => $toolName,
            'params_keys' => array_keys($params),
        ]);

        if (! isset($this->tools[$toolName])) {
            Log::error('McpServer: unknown tool', ['tool' => $toolName]);

            return [
                'success' => false,
                'error' => "Unknown tool: {$toolName}",
            ];
        }

        $authorization = $this->authorizationGate->authorize($toolName, $params, $scopes);
        if (! $authorization->allowed) {
            return $authorization->toToolResult();
        }

        try {
            $operation = function () use ($toolName, $params, $scopes): array {
                // Tenant ownership may have changed while waiting for the
                // generation lock. Recheck against the current persisted state.
                $authorization = $this->authorizationGate->authorize($toolName, $params, $scopes);

                return $authorization->allowed
                    ? $this->tools[$toolName]->execute($params)
                    : $authorization->toToolResult();
            };
            $result = in_array($toolName, self::GENERATION_TOOLS, true)
                ? GenerationTransaction::run($operation)
                : $this->tools[$toolName]->execute($params);

            Log::info('McpServer::executeTool — completed', [
                'tool' => $toolName,
                'success' => $result['success'] ?? false,
            ]);

            return $result;
        } catch (Throwable $e) {
            if ($e instanceof ValidationException) {
                return UpstreamErrorResponder::fromThrowable($e);
            }

            Log::error('McpServer::executeTool — failed', [
                'tool' => $toolName,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Hosted MCP tokens carry coarse tool scopes. Task-level authorization
     * will add deeper per-resource checks; this gate prevents a token from
     * reaching an entire tool family it was not issued for.
     *
     * @param array<int, string> $scopes
     */
    public function isToolAllowed(string $toolName, array $scopes): bool
    {
        $allowed = $this->authorizationGate->scopeAllows($toolName, array_values($scopes));

        Log::debug('McpServer: scope check completed', [
            'tool' => $toolName,
            'group' => $this->authorizationGate->toolGroup($toolName),
            'allowed' => $allowed,
        ]);

        return $allowed;
    }
}
