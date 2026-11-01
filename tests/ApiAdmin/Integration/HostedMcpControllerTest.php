<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Integration;

use App\ApiAdmin\Providers\ApiAdminServiceProvider;
use App\Models\ApiAdminMcpToken;
use App\Models\ApiAdminTenant;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use MoonShine\Laravel\Providers\MoonShineServiceProvider;
use MoonShine\MultiPanels\Providers\MultiPanelServiceProvider;
use Orchestra\Testbench\TestCase;

final class HostedMcpControllerTest extends TestCase
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
        $app['config']->set('app.key', 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');
        $app['config']->set('api-admin.hosted.mode', 'hosted');
        $app['config']->set('api-admin.hosted.mcp.enabled', true);
        $app['config']->set('api-admin.hosted.route_prefix', 't/{tenant}');
        $app['config']->set('api-admin.services', [
            'blog' => [
                'name' => 'blog',
                'tenant_id' => '1',
                'base_url' => 'https://api.blog.test',
                'panel' => 'blog-admin',
            ],
            'shop' => [
                'name' => 'shop',
                'tenant_id' => '999',
                'base_url' => 'https://api.shop.test',
                'panel' => 'shop-admin',
            ],
        ]);
        $app['config']->set('moonshine.use_routes', false);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('api_admin_tenants', function (Blueprint $table): void {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->boolean('active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('api_admin_mcp_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('api_admin_tenant_id')->constrained('api_admin_tenants')->cascadeOnDelete();
            $table->string('name');
            $table->string('token_hash', 64)->unique();
            $table->json('scopes');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('api_admin_mcp_tokens');
        Schema::dropIfExists('api_admin_tenants');

        parent::tearDown();
    }

    public function test_hosted_mcp_initialize_requires_valid_tenant_bound_token(): void
    {
        $this->createToken('secret-token', ['tools:read']);

        $response = $this
            ->withToken('secret-token')
            ->postJson('/t/acme/api-admin/mcp', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'initialize',
                'params' => ['protocolVersion' => '2025-06-18'],
            ]);

        $response->assertOk();
        $response->assertJsonPath('result.protocolVersion', '2025-06-18');
        $response->assertJsonPath('result.serverInfo.name', 'moonshine-api-admin-hosted');
    }

    public function test_hosted_mcp_rejects_route_tenant_mismatch(): void
    {
        $this->createToken('secret-token', ['tools:read']);

        $response = $this
            ->withToken('secret-token')
            ->postJson('/t/other/api-admin/mcp', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'initialize',
                'params' => [],
            ]);

        $response->assertUnauthorized();
        $response->assertJsonPath('error.message', 'Invalid tenant token');
    }

    public function test_hosted_mcp_denies_tool_calls_outside_token_scope(): void
    {
        $this->createToken('secret-token', ['tools:read']);

        $response = $this
            ->withToken('secret-token')
            ->postJson('/t/acme/api-admin/mcp', [
                'jsonrpc' => '2.0',
                'id' => 2,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'remove_service',
                    'arguments' => ['service' => 'blog', 'confirm' => true],
                ],
            ]);

        $response->assertOk();
        $response->assertJsonPath('result.isError', true);
        $content = $response->json('result.content.0.text');
        $this->assertIsString($content);
        $this->assertStringContainsString('Token scope does not allow tool: remove_service', $content);
    }

    public function test_hosted_mcp_allows_scoped_read_tool_inside_token_tenant(): void
    {
        $this->createToken('secret-token', ['tools:read']);

        $response = $this
            ->withToken('secret-token')
            ->postJson('/t/acme/api-admin/mcp', [
                'jsonrpc' => '2.0',
                'id' => 3,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'list_services',
                    'arguments' => [],
                ],
            ]);

        $response->assertOk();
        $response->assertJsonPath('result.isError', false);
        $content = $response->json('result.content.0.text');
        $this->assertIsString($content);
        $this->assertStringContainsString('"name":"blog"', $content);
        $this->assertStringNotContainsString('secret-token', $content);
    }

    public function test_hosted_mcp_denies_cross_tenant_service_even_with_destructive_scope(): void
    {
        $this->createToken('secret-token', ['tools:destructive']);

        $response = $this
            ->withToken('secret-token')
            ->postJson('/t/acme/api-admin/mcp', [
                'jsonrpc' => '2.0',
                'id' => 4,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'remove_service',
                    'arguments' => ['name' => 'shop', 'confirm' => true],
                ],
            ]);

        $response->assertOk();
        $response->assertJsonPath('result.isError', true);
        $content = $response->json('result.content.0.text');
        $this->assertIsString($content);
        $this->assertStringContainsString('MCP_TENANT_DENIED', $content);
        $this->assertStringContainsString("Service 'shop' is not available for tenant '1'.", $content);
    }

    public function test_disabled_transport_rejects_rpc_and_sse_even_when_routes_exist(): void
    {
        $this->createToken('secret-token', ['*']);
        config(['api-admin.hosted.mcp.enabled' => false]);
        $this->withToken('secret-token')->postJson('/t/acme/api-admin/mcp', ['method' => 'initialize'])->assertNotFound();
        $this->withToken('secret-token')->get('/t/acme/api-admin/mcp/sse')->assertNotFound();
    }

    public function test_hosted_code_generation_is_disabled_even_with_wildcard_scope(): void
    {
        $this->createToken('secret-token', ['*']);
        foreach (['create_field', 'update_field', 'delete_field', 'create_component', 'update_component', 'delete_component', 'initialize_local_service', 'create_model_resource'] as $tool) {
            $response = $this->withToken('secret-token')->postJson('/t/acme/api-admin/mcp', [
                'jsonrpc' => '2.0', 'id' => 10, 'method' => 'tools/call',
                'params' => ['name' => $tool, 'arguments' => ['name' => 'Unsafe']],
            ]);
            $response->assertOk()->assertJsonPath('result.isError', true);
            $content = $response->json('result.content.0.text');
            $this->assertIsString($content);
            $this->assertStringContainsString('MCP_HOSTED_TOOL_DISABLED', $content);
        }
    }

    public function test_generation_rejects_path_traversal_identifiers(): void
    {
        $this->createToken('secret-token', ['*']);
        $response = $this->withToken('secret-token')->postJson('/t/acme/api-admin/mcp', [
            'jsonrpc' => '2.0', 'id' => 11, 'method' => 'tools/call',
            'params' => ['name' => 'register_service', 'arguments' => ['name' => '../escape']],
        ]);
        $response->assertOk()->assertJsonPath('result.isError', true);
        $content = $response->json('result.content.0.text');
        $this->assertIsString($content);
        $this->assertStringContainsString('VALIDATION', $content);
    }

    public function test_persistent_service_ownership_overrides_stale_worker_configuration(): void
    {
        $this->createToken('secret-token', ['*']);
        $path = tempnam(sys_get_temp_dir(), 'api-admin-ownership-');
        $this->assertIsString($path);
        file_put_contents($path, "<?php return ['services' => ['blog' => ['tenant_id' => '999', 'panel' => 'blog-admin']]];");
        config(['api-admin.generated_services_path' => $path]);

        try {
            $response = $this->withToken('secret-token')->postJson('/t/acme/api-admin/mcp', [
                'jsonrpc' => '2.0', 'id' => 12, 'method' => 'tools/call',
                'params' => ['name' => 'remove_service', 'arguments' => ['name' => 'blog', 'confirm' => true]],
            ]);
            $response->assertOk()->assertJsonPath('result.isError', true);
            $content = $response->json('result.content.0.text');
            $this->assertIsString($content);
            $this->assertStringContainsString('MCP_TENANT_DENIED', $content);
        } finally {
            unlink($path);
        }
    }

    public function test_registration_cannot_replace_another_service_panel(): void
    {
        $this->createToken('secret-token', ['*']);
        $response = $this->withToken('secret-token')->postJson('/t/acme/api-admin/mcp', [
            'jsonrpc' => '2.0', 'id' => 13, 'method' => 'tools/call',
            'params' => ['name' => 'register_service', 'arguments' => ['name' => 'different', 'panel' => 'shop-admin', 'base_url' => 'https://example.test']],
        ]);
        $response->assertOk()->assertJsonPath('result.isError', true);
        $content = $response->json('result.content.0.text');
        $this->assertIsString($content);
        $this->assertStringContainsString('MCP_NAME_CONFLICT', $content);
    }

    public function test_transport_limits_request_size_and_request_rate(): void
    {
        $this->postJson('/t/acme/api-admin/mcp', ['padding' => str_repeat('x', 1048576)])->assertStatus(413);
        for ($i = 0; $i < 60; $i++) {
            $this->postJson('/t/acme/api-admin/mcp', ['method' => 'initialize'])->assertUnauthorized();
        }
        $this->postJson('/t/acme/api-admin/mcp', ['method' => 'initialize'])->assertStatus(429);
    }

    /**
     * @param array<int, string> $scopes
     */
    private function createToken(string $plainTextToken, array $scopes): ApiAdminMcpToken
    {
        $tenant = ApiAdminTenant::query()->create([
            'slug' => 'acme',
            'name' => 'Acme',
            'active' => true,
        ]);

        $token = ApiAdminMcpToken::query()->create([
            'api_admin_tenant_id' => $tenant->id,
            'name' => 'Local developer',
            'token_hash' => ApiAdminMcpToken::hashToken($plainTextToken),
            'scopes' => $scopes,
        ]);

        $this->assertInstanceOf(ApiAdminMcpToken::class, $token);

        return $token;
    }
}
