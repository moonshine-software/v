<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\ApiAdmin\Instructor\InstructorSession;
use App\ApiAdmin\Instructor\InstructorSessionRepository;
use App\ApiAdmin\MCP\McpServer;
use App\ApiAdmin\Providers\ApiAdminServiceProvider;
use App\ApiAdmin\ServiceConfig\ServiceConfig;
use App\ApiAdmin\Tenancy\NullTenantResolver;
use App\ApiAdmin\Tenancy\TenantResolver;
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
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\ApiAdmin\Concerns\UsesIsolatedProjectRoot;

final class HostedEditionSmokeTest extends TestCase
{
    use UsesIsolatedProjectRoot;

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
        $this->useIsolatedProjectRoot($app);
        $app['config']->set('app.key', 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');
        $app['config']->set('api-admin.hosted.mode', 'hosted');
        $app['config']->set('api-admin.hosted.mcp.enabled', true);
        $app['config']->set('api-admin.hosted.route_prefix', 't/{tenant}');
        $app['config']->set('api-admin.http.allow_private_addresses', true);
        $app['config']->set('api-admin.configs_path', $this->isolatedRoot.'/Configs');
        $app['config']->set('api-admin.layouts_path', $this->isolatedRoot.'/Layouts');
        $app['config']->set('api-admin.generated_services_path', $this->isolatedRoot.'/Generated/services.php');
        $app['config']->set('api-admin.generated_panels_path', $this->isolatedRoot.'/Generated/panels.php');
        $app['config']->set('api-admin.resources_path', $this->isolatedRoot.'/app/MoonShine/ApiAdmin/Resources');
        $app['config']->set('api-admin.specs_path', $this->isolatedRoot.'/.ai-factory/specs');
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

        foreach (['Configs', 'Layouts', 'Generated'] as $directory) {
            File::ensureDirectoryExists($this->isolatedRoot.'/'.$directory);
        }

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
        $this->removeIsolatedProjectRoot();

        parent::tearDown();
    }

    /** @return iterable<string, array{list<string>, string|null, bool}> */
    public static function probePermissions(): iterable
    {
        yield 'default GET' => [['tools:read'], null, true];
        yield 'explicit GET' => [['tools:read'], 'GET', true];
        yield 'read-only POST' => [['tools:read'], 'POST', false];
        yield 'lowercase POST' => [['tools:read'], 'post', false];
        yield 'probe-only POST' => [['tool:probe_endpoint'], 'POST', false];
        yield 'data-read POST' => [['tools:read', 'tools:data:read'], 'POST', false];
        yield 'create-only POST' => [['tools:read', 'tool:create_resource_item'], 'POST', false];
        yield 'write without probe' => [['tools:data:write'], 'POST', false];
        yield 'read and write POST' => [['tools:read', 'tools:data:write'], 'POST', true];
        yield 'probe and write POST' => [['tool:probe_endpoint', 'tools:data:write'], 'post', true];
        yield 'wildcard POST' => [['*'], 'POST', true];
        yield 'tools wildcard POST' => [['tools:*'], 'POST', true];
    }

    /** @param list<string> $scopes */
    #[DataProvider('probePermissions')]
    public function test_hosted_probe_enforces_method_specific_scopes(array $scopes, ?string $method, bool $allowed): void
    {
        $this->createTenantToken('probe-token', 'Acme', $scopes);
        app()->instance('api-admin.tenant.1.service.blog', ServiceConfig::fromArray([
            'name' => 'blog', 'tenant_id' => '1', 'base_url' => 'https://blog.test/api',
            'auth_type' => 'bearer', 'api_key' => 'upstream-probe-secret',
        ]));
        Http::preventStrayRequests();
        Http::fake(['https://blog.test/api/posts' => Http::response([['id' => 42, 'title' => 'Probe']])]);
        $arguments = ['service' => 'blog', 'path' => '/posts', 'body' => ['title' => 'Probe']];
        if ($method !== null) {
            $arguments['method'] = $method;
        }

        $response = $this->withToken('probe-token')->postJson('/t/acme/api-admin/mcp', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'probe_endpoint', 'arguments' => $arguments],
        ])->assertOk()->assertJsonPath('result.isError', ! $allowed);
        $result = $this->toolPayload($response->json('result.content.0.text'));

        if (! $allowed) {
            $this->assertSame('MCP_SCOPE_DENIED', $result['code']);
            Http::assertNothingSent();

            return;
        }

        $this->assertTrue($result['success']);
        Http::assertSentCount(1);
        Http::assertSent(static fn (ClientRequest $request): bool => $request->method() === strtoupper($method ?? 'GET')
            && $request->hasHeader('Authorization', 'Bearer upstream-probe-secret')
            && ($request->method() === 'GET' || $request['title'] === 'Probe'));
    }

    public function test_post_probe_write_permission_does_not_allow_another_tenant_service(): void
    {
        $this->createTenantToken('probe-token', 'Acme', ['tools:read', 'tools:data:write']);
        Http::fake();

        $response = $this->withToken('probe-token')->postJson('/t/acme/api-admin/mcp', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'probe_endpoint', 'arguments' => [
                'service' => 'shop', 'path' => '/products', 'method' => 'POST', 'body' => ['name' => 'Probe'],
            ]],
        ])->assertOk()->assertJsonPath('result.isError', true);

        $result = $this->toolPayload($response->json('result.content.0.text'));
        $this->assertSame('MCP_TENANT_DENIED', $result['code']);
        Http::assertNothingSent();
    }

    public function test_trusted_local_mcp_keeps_post_rpc_probes_without_token_scopes(): void
    {
        config(['api-admin.hosted.mode' => 'local']);
        app()->instance(TenantResolver::class, new NullTenantResolver);
        app()->instance('api-admin.tenant.local.service.blog', ServiceConfig::fromArray([
            'name' => 'blog', 'base_url' => 'https://blog.test/api', 'auth_type' => 'none',
        ]));
        Http::preventStrayRequests();
        Http::fake(['https://blog.test/api/posts/search' => Http::response([['id' => 42]])]);

        $result = new McpServer()->executeTool('probe_endpoint', [
            'service' => 'blog', 'path' => '/posts/search', 'method' => 'POST', 'body' => ['ids' => [42]],
        ]);

        $this->assertTrue($result['success']);
        Http::assertSentCount(1);
        Http::assertSent(static fn (ClientRequest $request): bool => $request->method() === 'POST'
            && $request['ids'] === [42]);
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
        $initialize->assertJsonPath('result.serverInfo.name', 'moonshine-v-hosted');

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
        $this->assertFileExists($this->isolatedRoot.'/Configs/Tenants/Acme/SmokeCatalogConfig.php');
        $this->assertFileExists($this->isolatedRoot.'/Generated/services.php');
        $this->assertStringContainsString(
            "'tenant_id' => '1'",
            File::get($this->isolatedRoot.'/Generated/services.php'),
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
        $this->assertFileExists($this->isolatedRoot.'/app/MoonShine/ApiAdmin/Resources/Tenants/Acme/SmokeCatalog/Post/PostResource.php');
        $this->assertDirectoryDoesNotExist(dirname(__DIR__, 2).'/app/MoonShine/ApiAdmin/Resources/Tenants/Acme/SmokeCatalog');

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

        $this->assertFileExists($this->isolatedRoot.'/Generated/panels.php');
        $moonshineConfig = File::get($this->isolatedRoot.'/Generated/panels.php');
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
}
