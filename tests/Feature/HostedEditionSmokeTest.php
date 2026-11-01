<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\ApiAdmin\Instructor\InstructorSession;
use App\ApiAdmin\Instructor\InstructorSessionRepository;
use App\ApiAdmin\Providers\ApiAdminServiceProvider;
use App\Models\ApiAdminMcpToken;
use App\Models\ApiAdminTenant;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use MoonShine\Laravel\Providers\MoonShineServiceProvider;
use MoonShine\MultiPanels\Providers\MultiPanelServiceProvider;
use Orchestra\Testbench\TestCase;

final class HostedEditionSmokeTest extends TestCase
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
        $app['config']->set('api-admin.http.allow_private_addresses', true);
        $app['config']->set('api-admin.configs_path', $this->tmpRoot().'/Configs');
        $app['config']->set('api-admin.layouts_path', $this->tmpRoot().'/Layouts');
        $app['config']->set('api-admin.generated_services_path', $this->tmpRoot().'/Generated/services.php');
        $app['config']->set('api-admin.generated_panels_path', $this->tmpRoot().'/Generated/panels.php');
        $app['config']->set('api-admin.resources_path', dirname(__DIR__, 2).'/app/MoonShine/ApiAdmin/Resources');
        $app['config']->set('api-admin.services', [
            'blog' => [
                'name' => 'blog',
                'tenant_id' => '1',
                'base_url' => 'https://blog.test/api',
                'health_path' => '/health',
                'panel' => 'blog-admin',
            ],
            'shop' => [
                'name' => 'shop',
                'tenant_id' => '2',
                'base_url' => 'https://shop.test/api',
                'health_path' => '/health',
                'panel' => 'shop-admin',
            ],
        ]);
        $app['config']->set('moonshine.use_routes', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        File::deleteDirectory($this->tmpRoot());
        File::ensureDirectoryExists($this->tmpRoot().'/Configs');
        File::ensureDirectoryExists($this->tmpRoot().'/Layouts');
        File::ensureDirectoryExists($this->tmpRoot().'/Generated');
        File::put(config_path('api-admin.php'), "<?php\n\ndeclare(strict_types=1);\n\nreturn ".var_export(['services' => []], true).";\n");
        File::put(config_path('moonshine.php'), "<?php\n\nreturn [\n    'use_routes' => true,\n    'panels' => [],\n];\n");

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

        Schema::create('api_admin_tenant_secrets', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id', 64);
            $table->string('service', 190);
            $table->string('name', 190);
            $table->text('value');
            $table->timestamps();
            $table->unique(['tenant_id', 'service', 'name']);
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('api_admin_tenant_secrets');
        Schema::dropIfExists('api_admin_mcp_tokens');
        Schema::dropIfExists('api_admin_tenants');
        File::deleteDirectory($this->tmpRoot());
        File::deleteDirectory(dirname(__DIR__, 2).'/app/MoonShine/ApiAdmin/Resources/Tenants/Acme/SmokeCatalog');
        @unlink(config_path('api-admin.php'));
        @unlink(config_path('moonshine.php'));

        parent::tearDown();
    }

    public function test_hosted_smoke_covers_provisioning_mcp_health_panel_and_tenant_denial(): void
    {
        $this->createTenantToken('acme-token', 'Acme', ['tools:*']);
        $this->createTenantToken('beta-token', 'Beta');

        Http::fake([
            'https://blog.test/api/health' => Http::response(['ok' => true], 200),
            'https://shop.test/api/health' => Http::response(['ok' => true], 200),
        ]);

        $initialize = $this
            ->withToken('acme-token')
            ->postJson('/t/acme/api-admin/mcp', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'initialize',
                'params' => ['protocolVersion' => '2025-06-18'],
            ]);

        $initialize->assertOk();
        $initialize->assertJsonPath('result.serverInfo.name', 'moonshine-api-admin-hosted');

        $health = $this
            ->withToken('acme-token')
            ->postJson('/t/acme/api-admin/mcp', [
                'jsonrpc' => '2.0',
                'id' => 2,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'health_check',
                    'arguments' => [],
                ],
            ]);

        $health->assertOk();
        $health->assertJsonPath('result.isError', false);
        $healthText = $health->json('result.content.0.text');
        $this->assertIsString($healthText);
        $this->assertStringContainsString('"service":"blog"', $healthText);
        $this->assertStringNotContainsString('"service":"shop"', $healthText);
        $this->assertStringContainsString('"mode":"hosted"', $healthText);

        $register = $this
            ->withToken('acme-token')
            ->postJson('/t/acme/api-admin/mcp', [
                'jsonrpc' => '2.0',
                'id' => 3,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'register_service',
                    'arguments' => [
                        'name' => 'smoke-catalog',
                        'base_url' => 'https://catalog.test/api',
                        'panel' => 'smoke-catalog-admin',
                        'auth_type' => 'none',
                    ],
                ],
            ]);

        $register->assertOk();
        $register->assertJsonPath('result.isError', false);
        $registerResult = $this->toolPayload($register->json('result.content.0.text'));
        $this->assertSame('smoke-catalog', $registerResult['service']);
        $this->assertSame('smoke-catalog-admin', $registerResult['panel']);
        $this->assertFileExists($this->tmpRoot().'/Configs/Tenants/Acme/SmokeCatalogConfig.php');
        $this->assertFileExists($this->tmpRoot().'/Generated/services.php');
        $this->assertStringContainsString(
            "'tenant_id' => '1'",
            File::get($this->tmpRoot().'/Generated/services.php'),
        );

        $createResource = $this
            ->withToken('acme-token')
            ->postJson('/t/acme/api-admin/mcp', [
                'jsonrpc' => '2.0',
                'id' => 4,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'create_resource',
                    'arguments' => [
                        'service' => 'smoke-catalog',
                        'name' => 'post',
                        'endpoint' => '/posts',
                        'title' => 'Posts',
                        'fields' => [
                            ['column' => 'id', 'type' => 'id'],
                            ['column' => 'title', 'type' => 'text'],
                        ],
                    ],
                ],
            ]);

        $createResource->assertOk();
        $this->assertFalse($createResource->json('result.isError'), (string) $createResource->getContent());
        $resourceResult = $this->toolPayload($createResource->json('result.content.0.text'));
        $this->assertSame('PostResource', $resourceResult['resource']);
        $validation = $resourceResult['validation'] ?? null;
        $this->assertIsArray($validation);
        $this->assertTrue($validation['ok']);
        $this->assertFileExists(dirname(__DIR__, 2).'/app/MoonShine/ApiAdmin/Resources/Tenants/Acme/SmokeCatalog/Post/PostResource.php');

        $resources = $this
            ->withToken('acme-token')
            ->postJson('/t/acme/api-admin/mcp', [
                'jsonrpc' => '2.0',
                'id' => 5,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'list_resources',
                    'arguments' => ['service' => 'smoke-catalog'],
                ],
            ]);

        $resources->assertOk();
        $resources->assertJsonPath('result.isError', false);
        $resourcesText = $resources->json('result.content.0.text');
        $this->assertIsString($resourcesText);
        $this->assertStringContainsString('"name":"post"', $resourcesText);

        $this->assertFileExists($this->tmpRoot().'/Generated/panels.php');
        $moonshineConfig = File::get($this->tmpRoot().'/Generated/panels.php');
        $this->assertStringContainsString("'smoke-catalog-admin'", $moonshineConfig);
        $this->assertStringContainsString("'prefix' => 't/{tenant}/smoke-catalog-admin'", $moonshineConfig);

        $denied = $this
            ->withToken('acme-token')
            ->postJson('/t/beta/api-admin/mcp', [
                'jsonrpc' => '2.0',
                'id' => 6,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'list_services',
                    'arguments' => [],
                ],
            ]);

        $denied->assertUnauthorized();
        $denied->assertJsonPath('error.message', 'Invalid tenant token');

        // A tenant URL alone must not grant access to the upstream proxy.
        $this->get('/t/acme/api-admin/async-search')->assertNotFound();

        Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'https://blog.test/api/health');
        Http::assertNotSent(fn (ClientRequest $request): bool => $request->url() === 'https://shop.test/api/health');
    }

    public function test_instructor_sessions_are_isolated_between_tenants_including_legacy_cache(): void
    {
        $this->createTenantToken('acme-token', 'Acme');
        $this->createTenantToken('beta-token', 'Beta');
        $sessionId = 'moonshine-user-42';
        $repository = new InstructorSessionRepository;
        $frame = [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'instructor_status', 'arguments' => ['session_id' => $sessionId]],
        ];
        $this->withToken('acme-token')->postJson('/t/acme/api-admin/mcp', $frame)->assertOk();
        $acme = new InstructorSession(id: $sessionId, proposal: ['service' => ['name' => 'private-acme']]);
        $repository->save($acme);
        Cache::put('api-admin:instructor:'.$sessionId, $acme->toArray(), 3600);

        $response = $this->withToken('beta-token')->postJson('/t/beta/api-admin/mcp', $frame)
            ->assertOk()->assertDontSee('private-acme');
        $this->assertFalse($this->toolPayload($response->json('result.content.0.text'))['active']);
        $repository->save(new InstructorSession(id: $sessionId, proposal: ['service' => ['name' => 'private-beta']]));
        $response = $this->postJson('/t/beta/api-admin/mcp', $frame)->assertOk();
        $this->assertSame('private-beta', data_get($this->toolPayload($response->json('result.content.0.text')), 'session.proposal.service.name'));
        $repository->reset($sessionId);
        $response = $this->postJson('/t/beta/api-admin/mcp', $frame)->assertOk();
        $this->assertFalse($this->toolPayload($response->json('result.content.0.text'))['active']);

        $response = $this->withToken('acme-token')->postJson('/t/acme/api-admin/mcp', $frame)->assertOk();
        $this->assertSame('private-acme', data_get($this->toolPayload($response->json('result.content.0.text')), 'session.proposal.service.name'));
    }

    public function test_streamable_http_handshake_notifications_and_tool_discovery(): void
    {
        $this->createTenantToken('acme-token', 'Acme');
        $this->withToken('acme-token')->withHeader('Accept', 'application/json, text/event-stream');

        foreach (['2025-03-26', '2025-06-18'] as $version) {
            $this->withHeader('MCP-Protocol-Version', $version)
                ->postJson('/t/acme/api-admin/mcp', [
                    'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
                    'params' => ['protocolVersion' => $version],
                ])->assertOk()->assertJsonPath('result.protocolVersion', $version);

            foreach (['notifications/initialized', 'notifications/cancelled', 'notifications/unknown'] as $method) {
                $this->postJson('/t/acme/api-admin/mcp', [
                    'jsonrpc' => '2.0', 'method' => $method,
                ])->assertStatus(202)->assertContent('');
            }

            $tools = $this->postJson('/t/acme/api-admin/mcp', [
                'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list',
            ])->assertOk()->json('result.tools');
            $this->assertIsArray($tools);
            $this->assertContains('list_services', array_column($tools, 'name'));

            $this->postJson('/t/acme/api-admin/mcp', [
                'jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call',
                'params' => ['name' => 'list_services', 'arguments' => []],
            ])->assertOk()->assertJsonPath('result.isError', false);
        }

        $this->get('/t/acme/api-admin/mcp')->assertStatus(405);
        $this->get('/t/acme/api-admin/mcp/sse')->assertNotFound();
    }

    public function test_streamable_http_rejects_foreign_origins_and_unsupported_versions(): void
    {
        $this->createTenantToken('acme-token', 'Acme');
        config(['app.url' => 'https://admin.example.com/prefix']);
        $frame = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'];

        $this->withToken('acme-token')->withHeader('Origin', 'https://foreign.example.com')
            ->postJson('/t/acme/api-admin/mcp', $frame)->assertForbidden();
        $this->withHeader('Origin', 'null')->postJson('/t/acme/api-admin/mcp', $frame)->assertForbidden();
        $this->withHeader('Origin', 'https://admin.example.com')
            ->postJson('/t/acme/api-admin/mcp', $frame)->assertOk();
        config(['app.url' => 'https://ADMIN.example.com:443/prefix']);
        $this->postJson('/t/acme/api-admin/mcp', $frame)->assertOk();
        $this->withHeader('MCP-Protocol-Version', 'unsupported')
            ->postJson('/t/acme/api-admin/mcp', $frame)->assertBadRequest();
    }

    public function test_streamable_http_rejects_invalid_envelopes_and_authenticates_notifications(): void
    {
        $this->postJson('/t/acme/api-admin/mcp', [
            'jsonrpc' => '2.0', 'method' => 'notifications/initialized',
        ])->assertUnauthorized();
        $this->createTenantToken('acme-token', 'Acme');
        $this->withToken('acme-token');

        foreach ([[], ['jsonrpc' => '1.0', 'method' => 'ping'], ['jsonrpc' => '2.0', 'method' => 'ping', 'id' => []],
            ['jsonrpc' => '2.0', 'method' => 'ping', 'params' => 'invalid']] as $frame) {
            $this->postJson('/t/acme/api-admin/mcp', $frame)
                ->assertBadRequest()->assertJsonPath('error.code', -32600);
        }
    }

    public function test_bearer_transport_works_with_browser_csrf_protection_enabled(): void
    {
        $this->createTenantToken('acme-token', 'Acme');
        app()->instance('env', 'production');
        Route::middleware('web')->post('/csrf-control', static fn () => response()->noContent());

        $this->withToken('acme-token')->postJson('/t/acme/api-admin/mcp', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
        ])->assertOk();
        $this->postJson('/t/acme/api-admin/mcp', [
            'jsonrpc' => '2.0', 'method' => 'notifications/initialized',
        ])->assertStatus(202)->assertContent('');
        $this->postJson('/csrf-control')->assertStatus(419);
    }

    /**
     * @param list<string> $scopes
     */
    private function createTenantToken(string $plainTextToken, string $tenantName, array $scopes = ['tools:read']): ApiAdminMcpToken
    {
        $slug = strtolower($tenantName);
        $tenant = ApiAdminTenant::query()->create([
            'slug' => $slug,
            'name' => $tenantName,
            'active' => true,
        ]);

        $token = ApiAdminMcpToken::query()->create([
            'api_admin_tenant_id' => $tenant->id,
            'name' => "{$tenantName} developer",
            'token_hash' => ApiAdminMcpToken::hashToken($plainTextToken),
            'scopes' => $scopes,
        ]);

        $this->assertInstanceOf(ApiAdminMcpToken::class, $token);

        return $token;
    }

    /**
     * @return array<string, mixed>
     */
    private function toolPayload(mixed $text): array
    {
        $this->assertIsString($text);

        $decoded = json_decode($text, true);
        $this->assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function tmpRoot(): string
    {
        return sys_get_temp_dir().'/moonshine-hosted-smoke-test';
    }
}
