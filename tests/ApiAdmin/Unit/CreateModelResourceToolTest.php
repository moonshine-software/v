<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Unit;

use App\ApiAdmin\MCP\McpAuthorizationGate;
use App\ApiAdmin\MCP\ServiceRegistry;
use App\ApiAdmin\MCP\Tools\CreateModelResourceTool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use Orchestra\Testbench\TestCase;
use Override;

final class CreateModelResourceToolTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir().'/moonshine-test-create-model-resource-'.uniqid('', true);
        File::ensureDirectoryExists($this->tmpRoot);

        config([
            'api-admin.local_resources_path' => $this->tmpRoot.'/LocalResources',
            'api-admin.local_resources_namespace' => 'App\\MoonShine\\Resources',
            'api-admin.services' => [
                'catalog' => [
                    'kind' => ServiceRegistry::SERVICE_KIND_LOCAL,
                    'driver' => 'eloquent',
                    'panel' => 'catalog-admin',
                ],
                'billing' => [
                    'kind' => ServiceRegistry::SERVICE_KIND_REST,
                    'base_url' => 'https://billing.example.test',
                    'panel' => 'billing-admin',
                ],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        if (File::isDirectory($this->tmpRoot)) {
            File::deleteDirectory($this->tmpRoot);
        }

        config([
            'api-admin.local_resources_path' => app_path('MoonShine/Resources'),
            'api-admin.local_resources_namespace' => 'App\\MoonShine\\Resources',
            'api-admin.services' => [],
        ]);

        parent::tearDown();
    }

    public function test_schema_documents_local_model_resource_inputs(): void
    {
        $schema = CreateModelResourceTool::schema();

        $this->assertSame('create_model_resource', $schema['name']);
        $inputSchema = $schema['inputSchema'];
        $this->assertSame(['service', 'name', 'model', 'fields'], $inputSchema['required']);
        $properties = $inputSchema['properties'];
        $this->assertArrayHasKey('index_fields', $properties);
        $this->assertArrayHasKey('form_fields', $properties);
        $this->assertArrayHasKey('detail_fields', $properties);
        $fields = $properties['fields'];
        $this->assertIsArray($fields);
        $items = $fields['items'];
        $this->assertIsArray($items);
        $fieldProperties = $items['properties'];
        $this->assertIsArray($fieldProperties);
        $type = $fieldProperties['type'];
        $this->assertIsArray($type);
        $enum = $type['enum'] ?? null;
        $this->assertIsArray($enum);
        $this->assertContains('text', $enum);
        $this->assertNotContains('belongs_to', $enum);
    }

    public function test_authorization_gate_treats_tool_as_generate_scope(): void
    {
        $gate = new McpAuthorizationGate;

        $this->assertSame('generate', $gate->toolGroup('create_model_resource'));
        $this->assertTrue($gate->mutates('create_model_resource'));
        $this->assertTrue($gate->scopeAllows('create_model_resource', ['tools:generate']));
        $this->assertFalse($gate->scopeAllows('create_model_resource', ['tools:read']));
    }

    public function test_creates_native_model_resource_for_local_service(): void
    {
        $result = (new CreateModelResourceTool)->execute([
            'service' => 'catalog',
            'name' => 'post',
            'model' => CreateModelResourceToolFakePost::class,
            'title' => 'Posts',
            'fields' => [
                ['column' => 'id', 'type' => 'id'],
                ['column' => 'title', 'type' => 'text', 'methods' => ['sortable', 'required']],
                ['column' => 'body', 'type' => 'textarea', 'hide_on' => ['index']],
            ],
            'search' => ['id', 'title'],
            'actions' => ['create', 'view', 'update'],
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(ServiceRegistry::SERVICE_KIND_LOCAL, $result['kind']);
        $this->assertSame('PostResource', $result['resource']);
        $this->assertSame(CreateModelResourceToolFakePost::class, $result['model']);
        $files = $result['files'];
        $this->assertIsArray($files);
        $resourceFile = $files['resource'];
        $this->assertIsString($resourceFile);
        $this->assertFileExists($resourceFile);
        $pages = $files['pages'];
        $this->assertIsArray($pages);
        $indexPage = $pages['index'] ?? null;
        $this->assertIsString($indexPage);
        $formPage = $pages['form'] ?? null;
        $this->assertIsString($formPage);
        $detailPage = $pages['detail'] ?? null;
        $this->assertIsString($detailPage);
        $this->assertFileExists($indexPage);
        $this->assertFileExists($formPage);
        $this->assertFileExists($detailPage);

        $resource = File::get($resourceFile);
        $this->assertStringContainsString('class PostResource extends MultiPanelResource', $resource);
        $this->assertStringContainsString("protected string \$panel = 'catalog-admin';", $resource);
        $this->assertStringContainsString('CreateModelResourceToolFakePost::class', $resource);

        $index = File::get($indexPage);
        $this->assertStringContainsString("Text::make('Title', 'title')->sortable()->required()", $index);
        $this->assertStringNotContainsString('RestBelongsTo', $index);

        $registry = new ServiceRegistry;
        $services = $registry->listServices();
        $catalog = $services[0] ?? null;
        $this->assertIsArray($catalog);
        $this->assertSame(1, $catalog['resources_count']);
        $this->assertSame(['post'], $catalog['resources']);
        $this->assertSame([], $registry->listResources('catalog'));
    }

    public function test_rejects_rest_service(): void
    {
        $result = (new CreateModelResourceTool)->execute([
            'service' => 'billing',
            'name' => 'invoice',
            'model' => CreateModelResourceToolFakePost::class,
            'fields' => [
                ['column' => 'id', 'type' => 'id'],
            ],
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('LOCAL_SERVICE_REQUIRED', $result['code']);
        $error = $result['error'] ?? null;
        $this->assertIsString($error);
        $this->assertStringContainsString('not local/Eloquent', $error);
    }

    public function test_rejects_missing_model(): void
    {
        $result = (new CreateModelResourceTool)->execute([
            'service' => 'catalog',
            'name' => 'post',
            'model' => 'Tests\\ApiAdmin\\Unit\\MissingModelResourceToolPost',
            'fields' => [
                ['column' => 'id', 'type' => 'id'],
            ],
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('MODEL_NOT_FOUND', $result['code']);
        $error = $result['error'] ?? null;
        $this->assertIsString($error);
        $this->assertStringContainsString('does not exist', $error);
    }
}

final class CreateModelResourceToolFakePost extends Model
{
    #[Override]
    protected $table = 'posts';
}
