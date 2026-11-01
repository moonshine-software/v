<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Integration;

use App\ApiAdmin\MCP\Tools\RegisterServiceTool;
use Illuminate\Support\Facades\File;
use Orchestra\Testbench\TestCase;

class RegisterServiceAuthSchemesTest extends TestCase
{
    private string $tmpRoot;

    private string $envPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir().'/moonshine-test-auth-'.uniqid('', true);
        File::ensureDirectoryExists($this->tmpRoot.'/Configs');
        File::ensureDirectoryExists($this->tmpRoot.'/Layouts');
        File::ensureDirectoryExists(config_path());

        config([
            'api-admin.configs_path' => $this->tmpRoot.'/Configs',
            'api-admin.layouts_path' => $this->tmpRoot.'/Layouts',
            'api-admin.generated_services_path' => config_path('api-admin.php'),
            'api-admin.generated_panels_path' => $this->tmpRoot.'/panels.php',
        ]);

        File::put(config_path('api-admin.php'), "<?php\n\ndeclare(strict_types=1);\n\nreturn ".var_export(['services' => []], true).";\n");
        File::put(config_path('moonshine.php'), "<?php\n\nreturn [\n    'middleware' => [\n        ChangeLocale::class,\n    ],\n    'use_routes' => true,\n];\n");

        $this->envPath = base_path('.env');
        File::put($this->envPath, '');
    }

    protected function tearDown(): void
    {
        if (File::isDirectory($this->tmpRoot)) {
            File::deleteDirectory($this->tmpRoot);
        }

        @unlink(config_path('api-admin.php'));
        @unlink(config_path('moonshine.php'));
        @unlink($this->envPath);
        config([
            'api-admin.services' => [],
            'api-admin.generated_services_path' => storage_path('api-admin/generated/services.php'),
            'api-admin.generated_panels_path' => storage_path('api-admin/generated/panels.php'),
        ]);

        parent::tearDown();
    }

    public function test_oauth2_password_round_trip_writes_secrets_to_env_and_descriptors_to_config(): void
    {
        $tool = new RegisterServiceTool;

        $result = $tool->execute([
            'name' => 'idp_app',
            'base_url' => 'https://api.idp.test',
            'auth_type' => 'oauth2_password',
            'token_url' => 'https://idp.test/oauth/token',
            'oauth_client_id' => 'cid-1',
            'oauth_client_secret' => 'csec-1',
            'oauth_username' => 'alice@example.com',
            'oauth_password' => 'p4ssw0rd',
            'oauth_scope' => 'read write',
        ]);

        $this->assertTrue($result['success']);

        $envContent = File::get($this->envPath);
        $this->assertStringContainsString('IDP_APP_OAUTH_PASSWORD=p4ssw0rd', $envContent);

        /** @var array{services: array<string, array<string, mixed>>} $config */
        $config = include config_path('api-admin.php');
        $this->assertArrayHasKey('idp_app', $config['services']);
        $svc = $config['services']['idp_app'];
        $this->assertSame('oauth2_password', $svc['auth_type']);
        $this->assertSame('https://idp.test/oauth/token', $svc['token_url']);
        $this->assertSame('cid-1', $svc['oauth_client_id']);
        $this->assertSame('alice@example.com', $svc['oauth_username']);
        $this->assertSame('', $svc['oauth_password'], 'sensitive — must be empty in baked config');
    }

    public function test_delegated_oauth_admin_login_does_not_require_or_store_resource_owner_password(): void
    {
        $tool = new RegisterServiceTool;

        $result = $tool->execute([
            'name' => 'delegated_idp',
            'base_url' => 'https://api.idp.test',
            'auth_type' => 'oauth2_password',
            'admin_login' => true,
            'token_url' => 'https://idp.test/oauth/token',
            'oauth_client_id' => 'cid-1',
            'oauth_client_secret' => 'csec-1',
        ]);

        $this->assertTrue($result['success']);

        $envContent = File::get($this->envPath);
        $this->assertStringNotContainsString('DELEGATED_IDP_OAUTH_PASSWORD=', $envContent);

        /** @var array{services: array<string, array<string, mixed>>} $config */
        $config = include config_path('api-admin.php');
        $svc = $config['services']['delegated_idp'];
        $this->assertTrue($svc['admin_login']);
        $this->assertSame('', $svc['oauth_username']);
        $this->assertSame('', $svc['oauth_password']);
    }

    public function test_oauth2_refresh_token_seeds_initial_token_in_env(): void
    {
        $tool = new RegisterServiceTool;

        $result = $tool->execute([
            'name' => 'rotating',
            'base_url' => 'https://api.rotating.test',
            'auth_type' => 'oauth2_refresh_token',
            'token_url' => 'https://idp.test/oauth/token',
            'oauth_client_id' => 'cid',
            'oauth_client_secret' => 'csec',
            'oauth_refresh_token' => 'seed-rt-7',
        ]);

        $this->assertTrue($result['success']);

        $envContent = File::get($this->envPath);
        $this->assertStringContainsString('ROTATING_OAUTH_REFRESH_TOKEN=seed-rt-7', $envContent);
    }

    public function test_session_round_trip_persists_csrf_config_in_baked_file(): void
    {
        $tool = new RegisterServiceTool;

        $result = $tool->execute([
            'name' => 'sanctum_app',
            'base_url' => 'https://app.test',
            'auth_type' => 'session',
            'session_login_endpoint' => '/login',
            'session_username' => 'me@example.com',
            'session_password' => 'hunter2',
            'csrf_token_source' => 'cookie',
            'csrf_token_key' => 'XSRF-TOKEN',
            'csrf_header_name' => 'X-XSRF-TOKEN',
            'csrf_bootstrap_path' => '/sanctum/csrf-cookie',
            'session_ttl' => 3600,
        ]);

        $this->assertTrue($result['success']);

        $envContent = File::get($this->envPath);
        $this->assertStringContainsString('SANCTUM_APP_SESSION_PASSWORD=hunter2', $envContent);

        /** @var array{services: array<string, array<string, mixed>>} $config */
        $config = include config_path('api-admin.php');
        $svc = $config['services']['sanctum_app'];
        $this->assertSame('session', $svc['auth_type']);
        $this->assertSame('/login', $svc['session_login_endpoint']);
        $this->assertSame('me@example.com', $svc['session_username']);
        $this->assertSame('', $svc['session_password'], 'sensitive — must be empty in baked config');
        $this->assertSame('/sanctum/csrf-cookie', $svc['csrf_bootstrap_path']);
        $this->assertSame(3600, $svc['session_ttl']);
    }

    public function test_delegated_session_admin_login_does_not_require_or_store_user_password(): void
    {
        $tool = new RegisterServiceTool;

        $result = $tool->execute([
            'name' => 'delegated_spa',
            'base_url' => 'https://app.test',
            'auth_type' => 'session',
            'admin_login' => true,
            'session_login_endpoint' => '/login',
            'csrf_bootstrap_path' => '/sanctum/csrf-cookie',
        ]);

        $this->assertTrue($result['success']);

        $envContent = File::get($this->envPath);
        $this->assertStringNotContainsString('DELEGATED_SPA_SESSION_PASSWORD=', $envContent);

        /** @var array{services: array<string, array<string, mixed>>} $config */
        $config = include config_path('api-admin.php');
        $svc = $config['services']['delegated_spa'];
        $this->assertTrue($svc['admin_login']);
        $this->assertSame('', $svc['session_username']);
        $this->assertSame('', $svc['session_password']);
    }

    public function test_validation_error_when_oauth2_password_missing_username(): void
    {
        $tool = new RegisterServiceTool;

        $result = $tool->execute([
            'name' => 'invalid',
            'base_url' => 'https://api.invalid.test',
            'auth_type' => 'oauth2_password',
            'token_url' => 'https://idp.test/oauth/token',
            'oauth_client_id' => 'cid',
            'oauth_client_secret' => 'csec',
            // missing oauth_username + oauth_password
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('VALIDATION', $result['code']);
        $error = $result['error'];
        $this->assertIsString($error);
        $this->assertStringContainsString('oauth_username', $error);
        $this->assertStringContainsString('oauth_password', $error);
    }

    public function test_validation_error_when_session_missing_login_endpoint(): void
    {
        $tool = new RegisterServiceTool;

        $result = $tool->execute([
            'name' => 'invalid_session',
            'base_url' => 'https://app.test',
            'auth_type' => 'session',
            'session_username' => 'u',
            'session_password' => 'p',
            // missing session_login_endpoint
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('VALIDATION', $result['code']);
        $error = $result['error'];
        $this->assertIsString($error);
        $this->assertStringContainsString('session_login_endpoint', $error);
    }

    public function test_re_register_preserves_oauth_password_when_omitted(): void
    {
        File::put($this->envPath, "PRESERVE_OAUTH_PASSWORD=keep-me\n");

        $tool = new RegisterServiceTool;

        $result = $tool->execute([
            'name' => 'preserve',
            'base_url' => 'https://api.preserve.test',
            'auth_type' => 'oauth2_password',
            'token_url' => 'https://idp.test/oauth/token',
            'oauth_client_id' => 'cid',
            'oauth_client_secret' => 'csec',
            'oauth_username' => 'u',
            // NO oauth_password — should preserve from env
        ]);

        $this->assertTrue($result['success']);

        $envContent = File::get($this->envPath);
        $this->assertStringContainsString('PRESERVE_OAUTH_PASSWORD=keep-me', $envContent);
    }

    public function test_re_register_preserves_session_password_when_omitted(): void
    {
        File::put($this->envPath, "STAY_SESSION_PASSWORD=session-secret\n");

        $tool = new RegisterServiceTool;

        $result = $tool->execute([
            'name' => 'stay',
            'base_url' => 'https://app.stay.test',
            'auth_type' => 'session',
            'session_login_endpoint' => '/login',
            'session_username' => 'u',
            // NO session_password — should preserve from env
        ]);

        $this->assertTrue($result['success']);

        $envContent = File::get($this->envPath);
        $this->assertStringContainsString('STAY_SESSION_PASSWORD=session-secret', $envContent);
    }
}
