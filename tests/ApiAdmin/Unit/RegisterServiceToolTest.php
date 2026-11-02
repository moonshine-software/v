<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Unit;

use App\ApiAdmin\MCP\Tools\RegisterServiceTool;
use App\ApiAdmin\ServiceConfig\ServiceConfig;
use App\ApiAdmin\Tenancy\TenantContext;
use App\ApiAdmin\Tenancy\TenantResolver;
use App\ApiAdmin\Tenancy\TenantSecretStore;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use Tests\ApiAdmin\Concerns\UsesIsolatedProjectRoot;

class RegisterServiceToolTest extends TestCase
{
    use UsesIsolatedProjectRoot;

    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useIsolatedProjectRoot(app());

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Cache::flush();
        Schema::create('api_admin_tenant_secrets', static function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id');
            $table->string('service');
            $table->string('name');
            $table->text('value');
            $table->timestamps();
            $table->unique(['tenant_id', 'service', 'name']);
        });

        $this->tmpRoot = sys_get_temp_dir().'/moonshine-test-register-'.uniqid('', true);
        File::ensureDirectoryExists($this->tmpRoot.'/Configs');
        File::ensureDirectoryExists($this->tmpRoot.'/Layouts');
        File::ensureDirectoryExists(config_path());

        config([
            'api-admin.configs_path' => $this->tmpRoot.'/Configs',
            'api-admin.layouts_path' => $this->tmpRoot.'/Layouts',
            'api-admin.generated_services_path' => config_path('api-admin.php'),
            'api-admin.generated_panels_path' => $this->tmpRoot.'/panels.php',
        ]);

        // Seed config/api-admin.php and config/moonshine.php so updateConfig/registerPanel can edit them
        File::put(config_path('api-admin.php'), "<?php\n\ndeclare(strict_types=1);\n\nreturn ".var_export(['services' => []], true).";\n");
        File::put(config_path('moonshine.php'), "<?php\n\nreturn [\n    'middleware' => [\n        ChangeLocale::class,\n    ],\n    'use_routes' => true,\n];\n");
    }

    protected function tearDown(): void
    {
        if (File::isDirectory($this->tmpRoot)) {
            File::deleteDirectory($this->tmpRoot);
        }

        $this->removeIsolatedProjectRoot();
        config([
            'api-admin.services' => [],
            'api-admin.generated_services_path' => storage_path('api-admin/generated/services.php'),
            'api-admin.generated_panels_path' => storage_path('api-admin/generated/panels.php'),
        ]);
        Cache::flush();

        Schema::dropIfExists('api_admin_tenant_secrets');

        parent::tearDown();
    }

    public function test_generated_service_config_treats_template_inputs_as_literal_data(): void
    {
        $literal = "x'.(1+2).'{{ apiKey }}\\tail";
        $result = (new RegisterServiceTool)->execute([
            'name' => 'quoted', 'base_url' => 'https://example.test/'.$literal,
            'auth_header' => $literal, 'health_path' => $literal,
            'oauth_client_id' => $literal, 'oauth_scope' => $literal,
            'response_mapping' => ['data_key' => $literal],
        ]);
        $this->assertTrue($result['success']);
        $generated = require $this->tmpRoot.'/Configs/QuotedConfig.php';
        $this->assertIsArray($generated);
        $this->assertIsArray($generated['response_mapping']);
        $this->assertSame('https://example.test/'.$literal, $generated['base_url']);
        $this->assertSame($literal, $generated['auth_header']);
        $this->assertSame($literal, $generated['health_path']);
        $this->assertSame($literal, $generated['oauth_client_id']);
        $this->assertSame($literal, $generated['oauth_scope']);
        $this->assertSame($literal, $generated['response_mapping']['data_key']);
    }

    public function test_invalid_generated_service_identifier_is_rejected_before_writing(): void
    {
        $result = (new RegisterServiceTool)->execute(['name' => '../escape', 'base_url' => 'https://example.test']);
        $this->assertFalse($result['success']);
        $this->assertSame('VALIDATION', $result['code']);
        $this->assertSame([], File::files($this->tmpRoot.'/Configs'));
    }

    public function test_re_register_preserves_api_key_when_omitted(): void
    {
        // Agents commonly re-register a service just to tweak
        // response_mapping / filter_param_name / detail_data_key without
        // restating the secret. Prior behavior silently nulled the
        // api_key on those calls and every downstream HTTP went 401.
        // New contract: an OMITTED `api_key` preserves the previously-
        // stored value from .env / baked config. An EXPLICIT empty
        // string still clears it (for auth_type="none").
        $envPath = base_path('.env');
        File::put($envPath, "BLOG_API_KEY=secret-from-previous-registration\n");

        try {
            $tool = new RegisterServiceTool;

            $result = $tool->execute([
                'name' => 'blog',
                'base_url' => 'https://api.blog.test/v1',
                'panel' => 'blog-admin',
                // NOTE: no api_key passed — must preserve.
                'response_mapping' => ['filter_param_name' => 'filters'],
            ]);

            $this->assertTrue($result['success']);

            $envContent = File::get($envPath);
            $this->assertStringContainsString('BLOG_API_KEY=secret-from-previous-registration', $envContent);

            $configContent = File::get($this->tmpRoot.'/Configs/BlogConfig.php');
            $this->assertStringContainsString("env('BLOG_API_KEY', 'secret-from-previous-registration')", $configContent);
        } finally {
            @unlink($envPath);
        }
    }

    public function test_re_register_clears_api_key_when_explicit_empty_string(): void
    {
        $envPath = base_path('.env');
        File::put($envPath, "BLOG_API_KEY=secret\n");

        try {
            $tool = new RegisterServiceTool;

            $tool->execute([
                'name' => 'blog',
                'base_url' => 'https://api.blog.test/v1',
                'api_key' => '', // explicit clear — auth_type switching to "none"
                'auth_type' => 'none',
            ]);

            $envContent = File::get($envPath);
            $this->assertStringNotContainsString('secret', $envContent);
        } finally {
            @unlink($envPath);
        }
    }

    public function test_schema_accepts_health_path(): void
    {
        $schema = RegisterServiceTool::schema();
        $props = $schema['inputSchema']['properties'];
        $this->assertArrayHasKey('health_path', $props);
        $health = $props['health_path'];
        $this->assertIsArray($health);
        $this->assertSame('string', $health['type']);
    }

    public function test_schema_accepts_realtime_settings(): void
    {
        $schema = RegisterServiceTool::schema();
        $props = $schema['inputSchema']['properties'];

        $this->assertArrayHasKey('realtime', $props);
        $this->assertIsArray($props['realtime']);
        $this->assertSame('object', $props['realtime']['type']);
        $realtimeProperties = $props['realtime']['properties'] ?? null;
        $this->assertIsArray($realtimeProperties);
        $this->assertArrayHasKey('webhook_secret', $realtimeProperties);
        $this->assertArrayHasKey('refresh_strategy', $realtimeProperties);
    }

    public function test_schema_accepts_admin_auth_mode_settings(): void
    {
        $schema = RegisterServiceTool::schema();
        $props = $schema['inputSchema']['properties'];

        $this->assertArrayHasKey('admin_auth_mode', $props);
        $this->assertArrayHasKey('admin_auth', $props);
        $adminAuthMode = $props['admin_auth_mode'];
        $this->assertIsArray($adminAuthMode);
        $this->assertSame(['moonshine', 'delegated_token', 'oauth2_authorization_code'], $adminAuthMode['enum']);

        $adminAuth = $props['admin_auth'];
        $this->assertIsArray($adminAuth);
        $adminAuthProperties = $adminAuth['properties'] ?? null;
        $this->assertIsArray($adminAuthProperties);
        $this->assertArrayHasKey('validate_endpoint', $adminAuthProperties);
        $this->assertArrayHasKey('authorize_url', $adminAuthProperties);
        $this->assertArrayHasKey('token_url', $adminAuthProperties);
        $this->assertArrayHasKey('scopes', $adminAuthProperties);
        $this->assertArrayHasKey('redirect_uri', $adminAuthProperties);
        $authType = $props['auth_type'];
        $this->assertIsArray($authType);
        $authTypeDescription = $authType['description'] ?? null;
        $this->assertIsString($authTypeDescription);
        $adminAuthModeDescription = $adminAuthMode['description'] ?? null;
        $this->assertIsString($adminAuthModeDescription);

        $this->assertStringContainsString('server-to-upstream', $authTypeDescription);
        $this->assertStringContainsString('admin-to-panel', $adminAuthModeDescription);
        $this->assertStringNotContainsString('future', $adminAuthModeDescription);
    }

    public function test_writes_health_path_into_service_config_stub(): void
    {
        $tool = new RegisterServiceTool;

        $result = $tool->execute([
            'name' => 'blog',
            'base_url' => 'https://api.blog.test/v1',
            'panel' => 'blog-admin',
            'title' => 'Blog Admin',
            'health_path' => '/health',
        ]);

        $this->assertTrue($result['success']);

        $configContent = File::get($this->tmpRoot.'/Configs/BlogConfig.php');
        $this->assertStringContainsString("'health_path' => env('BLOG_HEALTH_PATH', '/health')", $configContent);

        // Loading the generated config yields the health_path back verbatim
        $loaded = include $this->tmpRoot.'/Configs/BlogConfig.php';
        $this->assertIsArray($loaded);
        $this->assertSame('/health', $loaded['health_path']);
    }

    public function test_defaults_health_path_to_empty_when_not_provided(): void
    {
        $tool = new RegisterServiceTool;

        $tool->execute([
            'name' => 'blog',
            'base_url' => 'https://api.blog.test/v1',
        ]);

        $configContent = File::get($this->tmpRoot.'/Configs/BlogConfig.php');
        $this->assertStringContainsString("'health_path' => env('BLOG_HEALTH_PATH', '')", $configContent);
    }

    public function test_persists_health_path_into_config_api_admin_php(): void
    {
        $tool = new RegisterServiceTool;

        $tool->execute([
            'name' => 'shop',
            'base_url' => 'https://api.shop.test/api',
            'panel' => 'shop-admin',
            'health_path' => '/-/ready',
        ]);

        $appConfig = include config_path('api-admin.php');
        $this->assertIsArray($appConfig);
        /** @var array{services: array<string, array<string, mixed>>} $appConfig */
        $this->assertSame('/-/ready', $appConfig['services']['shop']['health_path']);
    }

    public function test_persists_realtime_settings_and_webhook_secret_via_env(): void
    {
        $envPath = base_path('.env');
        File::put($envPath, "APP_NAME=Laravel\n");

        try {
            $result = (new RegisterServiceTool)->execute([
                'name' => 'blog',
                'base_url' => 'https://api.blog.test/v1',
                'realtime' => [
                    'enabled' => true,
                    'webhook_secret' => 'whsec-test',
                    'webhook_signature_header' => 'X-Upstream-Signature',
                    'webhook_event_key' => 'type',
                    'webhook_resource_key' => 'model',
                    'webhook_action_key' => 'verb',
                    'webhook_id_key' => 'payload.id',
                    'allowed_events' => ['created', 'updated'],
                    'refresh_debounce_ms' => 1250,
                    'refresh_strategy' => 'notice',
                    'sensitive_payload_keys' => ['password', 'token'],
                ],
            ]);

            $this->assertTrue($result['success']);

            $appConfig = include config_path('api-admin.php');
            $this->assertIsArray($appConfig);
            /** @var array{services: array<string, array<string, mixed>>} $appConfig */
            $realtime = $appConfig['services']['blog']['realtime'];
            $this->assertIsArray($realtime);
            $this->assertTrue($realtime['enabled']);
            $this->assertSame('', $realtime['webhook_secret']);
            $this->assertSame('X-Upstream-Signature', $realtime['webhook_signature_header']);
            $this->assertSame(['created', 'updated'], $realtime['allowed_events']);
            $this->assertSame(1250, $realtime['refresh_debounce_ms']);
            $this->assertSame('notice', $realtime['refresh_strategy']);

            $env = File::get($envPath);
            $this->assertStringContainsString('BLOG_WEBHOOK_SECRET=whsec-test', $env);
            $this->assertSame('whsec-test', $_ENV['BLOG_WEBHOOK_SECRET'] ?? null);

            $configContent = File::get($this->tmpRoot.'/Configs/BlogConfig.php');
            $this->assertStringContainsString("'webhook_secret' => env('BLOG_WEBHOOK_SECRET', '')", $configContent);
            $this->assertStringNotContainsString('whsec-test', $configContent);
        } finally {
            @unlink($envPath);
            unset($_ENV['BLOG_WEBHOOK_SECRET']);
            putenv('BLOG_WEBHOOK_SECRET');
        }
    }

    public function test_persists_admin_auth_mode_into_generated_and_aggregate_config(): void
    {
        $result = (new RegisterServiceTool)->execute([
            'name' => 'oauth_panel',
            'base_url' => 'https://api.panel.test',
            'auth_type' => 'none',
            'admin_auth_mode' => 'oauth2_authorization_code',
            'admin_auth' => [
                'token_url' => 'https://idp.test/oauth/token',
                'authorize_url' => 'https://idp.test/oauth/authorize',
                'scopes' => ['openid', 'email'],
                'redirect_uri' => 'https://moonshine.test/oauth-panel/oauth/callback',
            ],
        ]);

        $this->assertTrue($result['success']);

        $appConfig = include config_path('api-admin.php');
        $this->assertIsArray($appConfig);
        /** @var array{services: array<string, array<string, mixed>>} $appConfig */
        $adminAuth = $appConfig['services']['oauth_panel']['admin_auth'];
        $this->assertIsArray($adminAuth);
        $this->assertSame('oauth2_authorization_code', $adminAuth['mode']);
        $this->assertSame('https://idp.test/oauth/token', $adminAuth['token_url']);
        $this->assertSame(['openid', 'email'], $adminAuth['scopes']);

        $generated = include $this->tmpRoot.'/Configs/OauthPanelConfig.php';
        $this->assertIsArray($generated);
        $generatedAdminAuth = $generated['admin_auth'] ?? null;
        $this->assertIsArray($generatedAdminAuth);
        $this->assertSame('oauth2_authorization_code', $generatedAdminAuth['mode']);
        $this->assertSame('https://idp.test/oauth/authorize', $generatedAdminAuth['authorize_url']);
    }

    public function test_rejects_unknown_admin_auth_mode(): void
    {
        $result = (new RegisterServiceTool)->execute([
            'name' => 'bad_admin_auth',
            'base_url' => 'https://api.panel.test',
            'admin_auth_mode' => 'magic',
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('VALIDATION', $result['code']);
        $error = $result['error'] ?? null;
        $this->assertIsString($error);
        $this->assertStringContainsString('admin_auth_mode', $error);
    }

    public function test_rejects_admin_auth_mode_value_in_auth_type_axis(): void
    {
        $result = (new RegisterServiceTool)->execute([
            'name' => 'bad_axis',
            'base_url' => 'https://api.panel.test',
            'auth_type' => 'delegated_token',
            'admin_auth_mode' => 'moonshine',
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('VALIDATION', $result['code']);
        $error = $result['error'] ?? null;
        $this->assertIsString($error);
        $this->assertStringContainsString('admin_auth_mode value', $error);
    }

    public function test_requires_admin_auth_endpoint_for_delegated_token_mode(): void
    {
        $result = (new RegisterServiceTool)->execute([
            'name' => 'missing_delegated_endpoint',
            'base_url' => 'https://api.panel.test',
            'auth_type' => 'none',
            'admin_auth_mode' => 'delegated_token',
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('VALIDATION', $result['code']);
        $error = $result['error'] ?? null;
        $this->assertIsString($error);
        $this->assertStringContainsString('admin_auth.validate_endpoint', $error);
    }

    public function test_requires_admin_auth_endpoints_for_oauth_code_mode(): void
    {
        $result = (new RegisterServiceTool)->execute([
            'name' => 'missing_oauth_endpoints',
            'base_url' => 'https://api.panel.test',
            'auth_type' => 'none',
            'admin_auth_mode' => 'oauth2_authorization_code',
            'admin_auth' => [
                'authorize_url' => 'https://idp.test/oauth/authorize',
            ],
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('VALIDATION', $result['code']);
        $error = $result['error'] ?? null;
        $this->assertIsString($error);
        $this->assertStringContainsString('admin_auth.token_url', $error);
    }

    public function test_re_register_preserves_realtime_webhook_secret_when_omitted(): void
    {
        $envPath = base_path('.env');
        File::put($envPath, "BLOG_WEBHOOK_SECRET=existing-secret\n");

        try {
            (new RegisterServiceTool)->execute([
                'name' => 'blog',
                'base_url' => 'https://api.blog.test/v1',
                'realtime' => [
                    'enabled' => true,
                    'refresh_strategy' => 'auto',
                ],
            ]);

            $env = File::get($envPath);
            $this->assertStringContainsString('BLOG_WEBHOOK_SECRET=existing-secret', $env);

            $configContent = File::get($this->tmpRoot.'/Configs/BlogConfig.php');
            $this->assertStringContainsString("env('BLOG_WEBHOOK_SECRET', '')", $configContent);
            $this->assertStringNotContainsString('existing-secret', $configContent);
        } finally {
            @unlink($envPath);
        }
    }

    public function test_schema_exposes_api_key_param(): void
    {
        $schema = RegisterServiceTool::schema();

        $this->assertArrayHasKey(
            'api_key',
            $schema['inputSchema']['properties'],
            'api_key must be a documented input so the agent knows it can pass a token.',
        );
    }

    public function test_api_key_is_baked_into_stub_as_env_fallback(): void
    {
        $tool = new RegisterServiceTool;

        $result = $tool->execute([
            'name' => 'workshop',
            'base_url' => 'https://api.workshop.test',
            'api_key' => '1|sanctum-token-xyz',
            'auth_type' => 'bearer',
        ]);

        $this->assertTrue($result['success']);

        $configContent = File::get($this->tmpRoot.'/Configs/WorkshopConfig.php');

        // env() wins when WORKSHOP_API_KEY is set in .env; literal fallback
        // ensures a fresh register_service works even without .env edits.
        $this->assertStringContainsString(
            "'api_key' => env('WORKSHOP_API_KEY', '1|sanctum-token-xyz')",
            $configContent,
        );

        $loaded = include $this->tmpRoot.'/Configs/WorkshopConfig.php';
        $this->assertIsArray($loaded);
        $this->assertSame('1|sanctum-token-xyz', $loaded['api_key']);
    }

    public function test_syncs_dotenv_with_service_keys(): void
    {
        // Seed a .env with unrelated keys to prove we don't clobber them.
        $envPath = base_path('.env');
        File::put($envPath, "APP_NAME=Laravel\nAPP_ENV=testing\n");

        try {
            (new RegisterServiceTool)->execute([
                'name' => 'workshop',
                'base_url' => 'https://api.workshop.test/api',
                'api_key' => '1|sanctum-token-xyz',
            ]);

            $env = File::get($envPath);

            $this->assertStringContainsString('APP_NAME=Laravel', $env);
            $this->assertStringContainsString('APP_ENV=testing', $env);
            $this->assertStringContainsString('WORKSHOP_API_URL=', $env);
            $this->assertStringContainsString('WORKSHOP_API_KEY=', $env);
            $this->assertStringContainsString('1|sanctum-token-xyz', $env);
        } finally {
            @unlink($envPath);
        }
    }

    public function test_in_process_env_refreshes_immediately_after_register(): void
    {
        // Before the refresh step, a long-lived worker would see the
        // token via .env but the container-bound ServiceConfig would
        // still hold the pre-register snapshot → 401 on every API call.
        // After register_service the running process's $_ENV + the
        // `api-admin.service.{name}` binding must reflect the new token.
        $envPath = base_path('.env');
        File::put($envPath, "APP_NAME=Laravel\n");

        try {
            (new RegisterServiceTool)->execute([
                'name' => 'workshop',
                'base_url' => 'https://api.workshop.test/api',
                'api_key' => '1|fresh-pat-xyz',
                'auth_type' => 'bearer',
            ]);

            $this->assertSame('1|fresh-pat-xyz', $_ENV['WORKSHOP_API_KEY'] ?? null);
            $this->assertSame('https://api.workshop.test/api', $_ENV['WORKSHOP_API_URL'] ?? null);
            $this->assertSame('1|fresh-pat-xyz', getenv('WORKSHOP_API_KEY'));

            // Container singleton resolves to fresh config (bearer header
            // derived from fresh api_key, not whatever was there at boot).
            $resolved = app('api-admin.service.workshop');
            $this->assertInstanceOf(ServiceConfig::class, $resolved);
            $this->assertSame('1|fresh-pat-xyz', $resolved->apiKey);
            $this->assertSame(
                ['Authorization' => 'Bearer 1|fresh-pat-xyz'],
                $resolved->authHeaders(),
            );
        } finally {
            @unlink($envPath);
            unset($_ENV['WORKSHOP_API_KEY'], $_ENV['WORKSHOP_API_URL'], $_ENV['WORKSHOP_HEALTH_PATH']);
            putenv('WORKSHOP_API_KEY');
            putenv('WORKSHOP_API_URL');
            putenv('WORKSHOP_HEALTH_PATH');
        }
    }

    public function test_sanctum_token_with_pipe_is_quoted_in_dotenv(): void
    {
        $envPath = base_path('.env');
        File::put($envPath, '');

        try {
            (new RegisterServiceTool)->execute([
                'name' => 'tracker',
                'base_url' => 'https://api.tracker.test',
                'api_key' => '1|abcDEF123',
            ]);

            $env = File::get($envPath);

            // `|` triggers the quoting path so the token survives even
            // parsers that interpret pipe literally when sourcing .env.
            $this->assertStringContainsString('TRACKER_API_KEY="1|abcDEF123"', $env);
        } finally {
            @unlink($envPath);
        }
    }

    public function test_kebab_case_service_name_normalizes_env_keys_with_underscores(): void
    {
        // Service names like "qa-cc" / "qa-pwd" / "qa-spa" used to flow
        // straight through Str::snake (which preserves dashes), producing
        // env keys like "QA-CC_API_URL". phpdotenv rejects those at the
        // next boot ("invalid name"), bricking the whole app. The writer
        // must normalize dashes → underscores before forming env keys.
        $envPath = base_path('.env');
        File::put($envPath, '');

        try {
            (new RegisterServiceTool)->execute([
                'name' => 'qa-cc',
                'base_url' => 'http://127.0.0.1:9201',
                'auth_type' => 'oauth2_cc',
                'token_url' => 'http://127.0.0.1:9201/oauth/token',
                'oauth_client_id' => 'demo-client',
                'oauth_client_secret' => 'demo-secret',
                'oauth_scope' => 'read',
            ]);

            $env = File::get($envPath);

            $this->assertStringContainsString('QA_CC_API_URL=http://127.0.0.1:9201', $env);
            $this->assertStringContainsString('QA_CC_HEALTH_PATH=', $env);
            $this->assertStringNotContainsString('QA-CC_', $env);

            $configContent = File::get($this->tmpRoot.'/Configs/QaCcConfig.php');
            $this->assertStringContainsString("env('QA_CC_API_URL'", $configContent);
            $this->assertStringNotContainsString("env('QA-CC_", $configContent);
        } finally {
            @unlink($envPath);
        }
    }

    public function test_syncs_dotenv_idempotently_updating_existing_keys(): void
    {
        $envPath = base_path('.env');
        File::put($envPath, "WORKSHOP_API_URL=http://stale.example\nWORKSHOP_API_KEY=old-token\n");

        try {
            (new RegisterServiceTool)->execute([
                'name' => 'workshop',
                'base_url' => 'https://api.workshop.test/api',
                'api_key' => 'fresh-token',
            ]);

            $env = File::get($envPath);

            $this->assertStringContainsString('https://api.workshop.test/api', $env);
            $this->assertStringContainsString('fresh-token', $env);
            $this->assertStringNotContainsString('http://stale.example', $env);
            $this->assertStringNotContainsString('old-token', $env);

            // No duplicate lines
            $this->assertSame(1, substr_count($env, 'WORKSHOP_API_URL='));
            $this->assertSame(1, substr_count($env, 'WORKSHOP_API_KEY='));
        } finally {
            @unlink($envPath);
        }
    }

    public function test_hosted_registration_stores_secrets_per_tenant_without_dotenv_or_literal_config_fallbacks(): void
    {
        $envPath = base_path('.env');
        File::put($envPath, '');
        config(['api-admin.hosted.mode' => 'hosted']);
        $this->bindTenant('tenant-a', 'acme');

        try {
            $result = (new RegisterServiceTool)->execute([
                'name' => 'secure-api',
                'base_url' => 'https://api.secure.test',
                'api_key' => 'hosted-api-key',
                'auth_type' => 'oauth2_cc',
                'token_url' => 'https://idp.secure.test/oauth/token',
                'oauth_client_id' => 'hosted-client',
                'oauth_client_secret' => 'hosted-client-secret',
                'realtime' => [
                    'enabled' => true,
                    'webhook_secret' => 'hosted-webhook-secret',
                ],
            ]);

            $this->assertTrue($result['success']);
            $this->assertSame('', File::get($envPath));

            $configContent = File::get($this->tmpRoot.'/Configs/Tenants/Acme/SecureApiConfig.php');
            $this->assertStringNotContainsString('hosted-api-key', $configContent);
            $this->assertStringNotContainsString('hosted-client-secret', $configContent);
            $this->assertStringNotContainsString('hosted-webhook-secret', $configContent);
            $this->assertStringContainsString("env('SECURE_API_API_KEY', '')", $configContent);
            $this->assertStringContainsString("env('SECURE_API_OAUTH_CLIENT_SECRET', '')", $configContent);

            $config = include config_path('api-admin.php');
            $this->assertIsArray($config);
            $this->assertIsArray($config['services'] ?? null);
            $this->assertIsArray($config['services']['secure-api'] ?? null);
            $this->assertSame('', $config['services']['secure-api']['oauth_client_secret']);

            $store = new TenantSecretStore;
            $this->assertSame('hosted-api-key', $store->get('tenant-a', 'secure-api', 'api_key'));
            $this->assertSame('hosted-client-secret', $store->get('tenant-a', 'secure-api', 'oauth_client_secret'));
            $this->assertSame('hosted-webhook-secret', $store->get('tenant-a', 'secure-api', 'webhook_secret'));
            $this->assertSame('', $store->get('tenant-b', 'secure-api', 'api_key'));
        } finally {
            @unlink($envPath);
        }
    }

    private function bindTenant(string $id, string $slug): void
    {
        app()->instance(TenantResolver::class, new readonly class($id, $slug) implements TenantResolver
        {
            public function __construct(
                private string $id,
                private string $slug,
            ) {}

            public function resolve(): TenantContext
            {
                return new TenantContext(
                    id: $this->id,
                    slug: $this->slug,
                    name: $this->slug,
                    mode: 'hosted',
                    isLocal: false,
                );
            }
        });
    }
}
