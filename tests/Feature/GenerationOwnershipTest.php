<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\ApiAdmin\MCP\McpServer;
use App\ApiAdmin\Tenancy\TenantContext;
use App\ApiAdmin\Tenancy\TenantResolver;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class GenerationOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    private string $originalBasePath;

    private string $firstService;

    private string $secondService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalBasePath = base_path();
        $this->directory = sys_get_temp_dir().'/moonshine-generation-ownership-'.bin2hex(random_bytes(6));
        $this->firstService = 'first-'.bin2hex(random_bytes(6));
        $this->secondService = 'second-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($this->directory.'/config');
        File::ensureDirectoryExists($this->directory.'/Layouts');
        File::put($this->directory.'/config/api-admin.php', '<?php return ["services" => []];');
        File::put($this->directory.'/.env', "UNRELATED=kept\n");
        File::copy($this->originalBasePath.'/app/MoonShine/ApiAdmin/Layouts/AdminHubLayout.php', $this->directory.'/Layouts/AdminHubLayout.php');
        app()->setBasePath($this->directory);
        config([
            'api-admin.services' => [],
            'api-admin.hosted.mode' => 'local',
            'api-admin.local_resources_path' => $this->directory.'/LocalResources',
            'api-admin.resources_path' => $this->directory.'/Resources',
            'api-admin.configs_path' => $this->directory.'/Configs',
            'api-admin.layouts_path' => $this->directory.'/Layouts',
            'api-admin.generated_services_path' => $this->directory.'/services.php',
            'api-admin.generated_panels_path' => $this->directory.'/panels.php',
        ]);
    }

    protected function tearDown(): void
    {
        app()->setBasePath($this->originalBasePath);
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    #[DataProvider('hubServiceNames')]
    public function test_service_registration_preserves_the_system_hub_and_registry(string $name): void
    {
        $before = $this->fileContents();
        $result = new McpServer()->executeTool('register_service', [
            'name' => $name,
            'base_url' => 'https://api.test',
            'auth_type' => 'none',
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('MCP_NAME_CONFLICT', $result['code']);
        $this->assertSame($before, $this->fileContents());
        $this->assertSame([], config('api-admin.services'));
    }

    /** @return array<string, array{string}> */
    public static function hubServiceNames(): array
    {
        return [
            'hyphen' => ['admin-hub'],
            'underscore' => ['admin_hub'],
            'studly' => ['AdminHub'],
            'case alias' => ['adminhub'],
        ];
    }

    public function test_hosted_hub_named_service_uses_its_tenant_layout(): void
    {
        config(['api-admin.hosted.mode' => 'hosted']);
        app()->instance(TenantResolver::class, new readonly class implements TenantResolver
        {
            public function resolve(): TenantContext
            {
                return new TenantContext('tenant-a', 'acme', 'Acme', 'hosted', false);
            }
        });
        $hub = File::get($this->directory.'/Layouts/AdminHubLayout.php');
        $result = new McpServer()->executeTool('register_service', [
            'name' => 'admin-hub',
            'base_url' => 'https://api.test',
            'auth_type' => 'none',
        ]);

        $this->assertTrue($result['success'], json_encode($result, JSON_THROW_ON_ERROR));
        $this->assertSame($hub, File::get($this->directory.'/Layouts/AdminHubLayout.php'));
        $this->assertFileExists($this->directory.'/Layouts/Tenants/Acme/AdminHubLayout.php');
    }

    #[DataProvider('nativeResourceNames')]
    public function test_model_generation_preserves_native_resources(string $name, string $nativeName): void
    {
        $this->initialize($this->firstService);
        File::copyDirectory(
            $this->originalBasePath.'/app/MoonShine/Resources/'.$nativeName,
            $this->directory.'/LocalResources/'.$nativeName,
        );
        $before = $this->fileContents();

        $result = $this->generate($this->firstService, $name);

        $this->assertFalse($result['success']);
        $this->assertSame('GENERATION_FAILED', $result['code']);
        $this->assertSame($before, $this->fileContents());
    }

    /** @return array<string, array{string, string}> */
    public static function nativeResourceNames(): array
    {
        return [
            'users' => ['moon_shine_user', 'MoonShineUser'],
            'roles' => ['moon-shine-user-role', 'MoonShineUserRole'],
            'case alias' => ['moonshineuser', 'MoonShineUser'],
        ];
    }

    public function test_reserved_model_name_is_rejected_even_before_its_directory_exists(): void
    {
        $this->initialize($this->firstService);
        $before = $this->fileContents();

        $result = $this->generate($this->firstService, 'moon_shine_user');

        $this->assertFalse($result['success']);
        $this->assertSame($before, $this->fileContents());
        $this->assertDirectoryDoesNotExist($this->directory.'/LocalResources');
    }

    public function test_model_generation_does_not_replace_an_unowned_directory(): void
    {
        $this->initialize($this->firstService);
        File::ensureDirectoryExists($this->directory.'/LocalResources/User/Pages');
        File::put($this->directory.'/LocalResources/User/Pages/Custom.php', '<?php // custom page');
        $before = $this->fileContents();

        $result = $this->generate($this->firstService, 'user');

        $this->assertFalse($result['success']);
        $this->assertSame($before, $this->fileContents());
    }

    public function test_other_panel_cannot_overwrite_a_model_resource_or_its_pages(): void
    {
        $this->initialize($this->firstService);
        $this->initialize($this->secondService);
        $created = $this->generate($this->firstService, 'user');
        $this->assertTrue($created['success'], json_encode($created, JSON_THROW_ON_ERROR));
        $before = $this->fileContents();

        foreach (['user', 'USER'] as $name) {
            $result = $this->generate($this->secondService, $name);
            $this->assertFalse($result['success']);
            $this->assertSame('GENERATION_FAILED', $result['code']);
            $this->assertSame($before, $this->fileContents());
        }
    }

    public function test_same_panel_can_regenerate_its_model_resource(): void
    {
        $this->initialize($this->firstService);
        $created = $this->generate($this->firstService, 'user');
        $this->assertTrue($created['success'], json_encode($created, JSON_THROW_ON_ERROR));

        $updated = $this->generate($this->firstService, 'USER', 'Renamed users');

        $this->assertTrue($updated['success'], json_encode($updated, JSON_THROW_ON_ERROR));
        $this->assertSame($created['files'], $updated['files']);
        $this->assertStringContainsString(
            "'Renamed users'",
            File::get($this->directory.'/LocalResources/User/UserResource.php'),
        );
    }

    public function test_explicit_panel_cannot_override_the_selected_local_services_ownership(): void
    {
        $this->initialize($this->firstService);
        $this->initialize($this->secondService);
        $created = $this->generate($this->firstService, 'user');
        $this->assertTrue($created['success'], json_encode($created, JSON_THROW_ON_ERROR));
        $before = $this->fileContents();

        foreach ([$this->firstService.'-admin', 'admin'] as $panel) {
            $result = new McpServer()->executeTool('create_model_resource', [
                'service' => $this->secondService,
                'panel' => $panel,
                'name' => 'user',
                'model' => User::class,
                'fields' => [['column' => 'id', 'type' => 'id']],
            ]);

            $this->assertFalse($result['success']);
            $this->assertSame('LOCAL_PANEL_MISMATCH', $result['code']);
            $this->assertSame($before, $this->fileContents());
        }

        $result = new McpServer()->executeTool('create_model_resource', [
            'service' => $this->firstService,
            'panel' => $this->firstService.'-admin',
            'name' => 'user',
            'model' => User::class,
            'fields' => [['column' => 'id', 'type' => 'id']],
        ]);
        $this->assertTrue($result['success'], json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function test_local_service_cannot_claim_another_services_panel(): void
    {
        $this->initialize($this->firstService);
        $before = $this->fileContents();

        $result = new McpServer()->executeTool('initialize_local_service', [
            'name' => $this->secondService,
            'panel' => $this->firstService.'-admin',
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('MCP_NAME_CONFLICT', $result['code']);
        $this->assertSame($before, $this->fileContents());
        $this->initialize($this->firstService);
        $this->assertSame($before, $this->fileContents());
    }

    public function test_local_service_cannot_claim_the_platform_panel(): void
    {
        $before = $this->fileContents();
        $panels = config('moonshine.panels');

        $result = new McpServer()->executeTool('initialize_local_service', [
            'name' => $this->firstService,
            'panel' => 'admin',
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('MCP_NAME_CONFLICT', $result['code']);
        $this->assertSame($before, $this->fileContents());
        $this->assertSame($panels, config('moonshine.panels'));
    }

    private function initialize(string $service): void
    {
        $result = new McpServer()->executeTool('initialize_local_service', ['name' => $service]);
        $this->assertTrue($result['success'], json_encode($result, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function generate(string $service, string $name, string $title = 'Users'): array
    {
        return new McpServer()->executeTool('create_model_resource', [
            'service' => $service,
            'name' => $name,
            'title' => $title,
            'model' => User::class,
            'fields' => [['column' => 'id', 'type' => 'id']],
        ]);
    }

    /** @return array<string, string> */
    private function fileContents(): array
    {
        $files = [];
        foreach (File::allFiles($this->directory, hidden: true) as $file) {
            if ($file->getRelativePathname() === 'storage/api-admin/locks/generation.lock') {
                continue;
            }
            $files[$file->getRelativePathname()] = $file->getContents();
        }
        ksort($files);

        return $files;
    }
}
