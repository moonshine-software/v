<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Unit;

use App\ApiAdmin\MCP\Tools\RegisterServiceTool;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Orchestra\Testbench\TestCase;
use Tests\ApiAdmin\Concerns\UsesIsolatedProjectRoot;

class RegisterServiceToolAdminAuthTest extends TestCase
{
    use UsesIsolatedProjectRoot;

    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useIsolatedProjectRoot(app());

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Cache::flush();

        $this->tmpRoot = sys_get_temp_dir().'/moonshine-test-admin-auth-'.uniqid('', true);
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
        File::put(config_path('moonshine.php'), "<?php\n\nreturn [\n    'use_routes' => true,\n    'middleware' => [],\n];\n");
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmpRoot);
        $this->removeIsolatedProjectRoot();
        config([
            'api-admin.services' => [],
            'api-admin.generated_services_path' => storage_path('api-admin/generated/services.php'),
            'api-admin.generated_panels_path' => storage_path('api-admin/generated/panels.php'),
        ]);

        parent::tearDown();
    }

    public function test_admin_auth_modes_round_trip_through_generated_and_aggregate_config(): void
    {
        $tool = new RegisterServiceTool;

        $cases = [
            'moonshine_panel' => [
                'admin_auth_mode' => 'moonshine',
                'admin_auth' => [],
            ],
            'delegated_panel' => [
                'admin_auth_mode' => 'delegated_token',
                'admin_auth' => ['validate_endpoint' => '/me'],
            ],
            'oauth_panel' => [
                'admin_auth_mode' => 'oauth2_authorization_code',
                'admin_auth' => [
                    'authorize_url' => 'https://idp.test/oauth/authorize',
                    'token_url' => 'https://idp.test/oauth/token',
                    'scopes' => ['openid', 'email'],
                    'redirect_uri' => 'https://moonshine.test/oauth-panel/oauth/callback',
                ],
            ],
        ];

        foreach ($cases as $name => $case) {
            $result = $tool->execute([
                'name' => $name,
                'base_url' => 'https://api.test/'.$name,
                'auth_type' => 'none',
                'admin_auth_mode' => $case['admin_auth_mode'],
                'admin_auth' => $case['admin_auth'],
            ]);

            $error = $result['error'] ?? null;
            $this->assertTrue($result['success'], is_string($error) ? $error : 'register_service failed');
        }

        $appConfig = include config_path('api-admin.php');
        $this->assertIsArray($appConfig);
        $services = $appConfig['services'] ?? null;
        $this->assertIsArray($services);

        foreach ($cases as $name => $case) {
            $serviceConfig = $services[$name] ?? null;
            $this->assertIsArray($serviceConfig);
            $aggregateAdminAuth = $serviceConfig['admin_auth'] ?? null;
            $this->assertIsArray($aggregateAdminAuth);
            $this->assertSame($case['admin_auth_mode'], $aggregateAdminAuth['mode'] ?? null);

            $generated = include $this->tmpRoot.'/Configs/'.str($name)->studly()->value().'Config.php';
            $this->assertIsArray($generated);
            $generatedAdminAuth = $generated['admin_auth'] ?? null;
            $this->assertIsArray($generatedAdminAuth);
            $this->assertSame($case['admin_auth_mode'], $generatedAdminAuth['mode'] ?? null);
        }
    }
}
