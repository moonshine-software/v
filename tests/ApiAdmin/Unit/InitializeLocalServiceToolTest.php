<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Unit;

use App\ApiAdmin\Layouts\LocalPanelLayout;
use App\ApiAdmin\MCP\McpAuthorizationGate;
use App\ApiAdmin\MCP\ServiceRegistry;
use App\ApiAdmin\MCP\Tools\InitializeLocalServiceTool;
use Illuminate\Support\Facades\File;
use Orchestra\Testbench\TestCase;

final class InitializeLocalServiceToolTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir().'/moonshine-test-local-service-'.uniqid('', true);
        File::ensureDirectoryExists($this->tmpRoot);

        config([
            'api-admin.services' => [],
            'api-admin.resources_path' => $this->tmpRoot.'/RestResources',
            'api-admin.generated_services_path' => $this->tmpRoot.'/services.php',
            'api-admin.generated_panels_path' => $this->tmpRoot.'/panels.php',
            'moonshine.panels' => [],
        ]);
    }

    protected function tearDown(): void
    {
        if (File::isDirectory($this->tmpRoot)) {
            File::deleteDirectory($this->tmpRoot);
        }

        config([
            'api-admin.services' => [],
            'api-admin.resources_path' => app_path('MoonShine/ApiAdmin/Resources'),
            'api-admin.generated_services_path' => storage_path('api-admin/generated/services.php'),
            'api-admin.generated_panels_path' => storage_path('api-admin/generated/panels.php'),
            'moonshine.panels' => [],
        ]);

        parent::tearDown();
    }

    public function test_schema_documents_local_initialization_inputs(): void
    {
        $schema = InitializeLocalServiceTool::schema();

        $this->assertSame('initialize_local_service', $schema['name']);
        $inputSchema = $schema['inputSchema'];
        $this->assertIsArray($inputSchema);
        $this->assertSame(['name'], $inputSchema['required']);
        $properties = $inputSchema['properties'];
        $this->assertIsArray($properties);
        $this->assertArrayHasKey('panel', $properties);
        $this->assertArrayHasKey('title', $properties);
        $authType = $properties['auth_type'];
        $this->assertIsArray($authType);
        $this->assertSame(['local', 'none'], $authType['enum']);
    }

    public function test_authorization_gate_treats_tool_as_generate_scope_and_name_scoped(): void
    {
        $gate = new McpAuthorizationGate;

        $this->assertSame('generate', $gate->toolGroup('initialize_local_service'));
        $this->assertTrue($gate->mutates('initialize_local_service'));
        $this->assertTrue($gate->scopeAllows('initialize_local_service', ['tools:generate']));
        $this->assertFalse($gate->scopeAllows('initialize_local_service', ['tools:read']));
    }

    public function test_initializes_local_service_without_upstream_api_config(): void
    {
        $result = (new InitializeLocalServiceTool)->execute([
            'name' => 'catalog',
            'panel' => 'catalog-admin',
            'title' => 'Catalog Admin',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('local', $result['kind']);
        $this->assertFileExists($this->tmpRoot.'/services.php');
        $this->assertFileExists($this->tmpRoot.'/panels.php');

        $servicesConfig = include $this->tmpRoot.'/services.php';
        $this->assertIsArray($servicesConfig);
        $services = $servicesConfig['services'] ?? null;
        $this->assertIsArray($services);
        $service = $services['catalog'] ?? null;
        $this->assertIsArray($service);
        $this->assertSame(ServiceRegistry::SERVICE_KIND_LOCAL, $service['kind']);
        $this->assertSame('eloquent', $service['driver']);
        $this->assertSame('catalog-admin', $service['panel']);
        $this->assertArrayNotHasKey('base_url', $service);
        $this->assertArrayNotHasKey('response_mapping', $service);

        $panels = include $this->tmpRoot.'/panels.php';
        $this->assertIsArray($panels);
        $panel = $panels['catalog-admin'] ?? null;
        $this->assertIsArray($panel);
        $this->assertSame('catalog-admin', $panel['prefix']);
        $this->assertSame('Catalog Admin', $panel['title']);
        $this->assertSame(LocalPanelLayout::class, $panel['layout']);
        $this->assertSame(ServiceRegistry::SERVICE_KIND_LOCAL, $panel['api_admin_kind']);

        $listed = (new ServiceRegistry)->listServices();
        $this->assertCount(1, $listed);
        $this->assertSame('catalog', $listed[0]['name']);
        $this->assertSame(ServiceRegistry::SERVICE_KIND_LOCAL, $listed[0]['kind']);
        $this->assertNull($listed[0]['base_url']);
    }

    public function test_rejects_upstream_only_arguments(): void
    {
        $result = (new InitializeLocalServiceTool)->execute([
            'name' => 'catalog',
            'base_url' => 'https://api.example.test',
            'response_mapping' => ['data_key' => 'data'],
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('LOCAL_ONLY', $result['code']);
        $error = $result['error'] ?? null;
        $this->assertIsString($error);
        $this->assertStringContainsString('base_url', $error);
        $this->assertStringContainsString('response_mapping', $error);
    }

    public function test_rejects_upstream_auth_type(): void
    {
        $result = (new InitializeLocalServiceTool)->execute([
            'name' => 'catalog',
            'auth_type' => 'bearer',
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('LOCAL_ONLY', $result['code']);
        $error = $result['error'] ?? null;
        $this->assertIsString($error);
        $this->assertStringContainsString('auth_type', $error);
    }
}
