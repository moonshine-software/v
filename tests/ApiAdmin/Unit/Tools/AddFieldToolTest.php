<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Unit\Tools;

use App\ApiAdmin\MCP\Generator\ResourceGenerator;
use App\ApiAdmin\MCP\SpecStore\SpecStore;
use App\ApiAdmin\MCP\Tools\AddFieldTool;
use App\ApiAdmin\Providers\ApiAdminServiceProvider;
use App\ApiAdmin\ServiceConfig\ResponseMapping;
use App\ApiAdmin\ServiceConfig\ServiceConfig;
use Illuminate\Support\Facades\File;
use MoonShine\Laravel\Providers\MoonShineServiceProvider;
use MoonShine\MultiPanels\Providers\MultiPanelServiceProvider;
use Orchestra\Testbench\TestCase;

final class AddFieldToolTest extends TestCase
{
    private string $serviceSlug;

    private string $resourcesRoot;

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
        $this->resourcesRoot = sys_get_temp_dir().'/moonshine-add-field-resources-'.uniqid('', true);
        $app['config']->set('api-admin.resources_path', $this->resourcesRoot);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->serviceSlug = 'addfsvc'.bin2hex(random_bytes(8));

        config(["api-admin.services.{$this->serviceSlug}" => [
            'config' => "App\\MoonShine\\ApiAdmin\\Configs\\{$this->serviceSlug}Config",
            'panel' => $this->serviceSlug.'-admin',
            'enabled' => true,
        ]]);

        app()->forgetInstance("api-admin.service.{$this->serviceSlug}");
        app()->instance(
            "api-admin.service.{$this->serviceSlug}",
            new ServiceConfig(
                name: $this->serviceSlug,
                baseUrl: 'https://api.test/v1',
                apiKey: '',
                responseMapping: new ResponseMapping(primaryKey: 'id'),
                authType: 'bearer',
            ),
        );

        $this->seedResource(['id', 'title', 'body']);
    }

    protected function tearDown(): void
    {
        $sidecarDir = dirname(SpecStore::path($this->serviceSlug, 'x'));
        if (File::isDirectory($sidecarDir)) {
            File::deleteDirectory($sidecarDir);
        }

        if (File::isDirectory($this->resourcesRoot)) {
            File::deleteDirectory($this->resourcesRoot);
        }

        parent::tearDown();
    }

    /**
     * @param list<string> $columns
     */
    private function seedResource(array $columns): void
    {
        $fields = array_map(
            fn (string $c): array => $c === 'id'
                ? ['column' => 'id', 'type' => 'id']
                : ['column' => $c, 'type' => 'text'],
            $columns,
        );

        $_ = (new ResourceGenerator)->generate([
            'service' => $this->serviceSlug,
            'name' => 'post',
            'endpoint' => '/posts',
            'fields' => $fields,
        ]);
    }

    /**
     * @param array<string, mixed> $result
     */
    private function positionIndex(array $result): int
    {
        $position = $result['position'] ?? null;
        $this->assertIsArray($position);
        $index = $position['index'] ?? null;
        $this->assertIsInt($index);

        return $index;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readFields(): array
    {
        $spec = SpecStore::read($this->serviceSlug, 'post');
        $this->assertIsArray($spec);
        /** @var list<array<string, mixed>> $fields */
        $fields = is_array($spec['fields'] ?? null) ? array_values($spec['fields']) : [];

        return $fields;
    }

    public function test_schema_documents_cross_service_relation_field_key(): void
    {
        $schema = AddFieldTool::schema();
        $inputSchema = $schema['inputSchema'];
        $this->assertIsArray($inputSchema);
        $inputProperties = $inputSchema['properties'];
        $this->assertIsArray($inputProperties);
        $field = $inputProperties['field'];
        $this->assertIsArray($field);

        $properties = $field['properties'];
        $this->assertIsArray($properties);
        $this->assertArrayHasKey('target_service', $properties);
        $targetService = $properties['target_service'];
        $this->assertIsArray($targetService);
        $description = $targetService['description'];
        $this->assertIsString($description);
        $this->assertStringContainsString('cross-service', $description);
    }

    public function test_appends_field_at_end_by_default(): void
    {
        $result = (new AddFieldTool)->execute([
            'service' => $this->serviceSlug,
            'resource' => 'post',
            'field' => ['column' => 'subtitle', 'type' => 'text'],
        ]);

        $this->assertSame('subtitle', $result['column']);
        $this->assertSame(3, $this->positionIndex($result));

        $columns = array_column($this->readFields(), 'column');
        $this->assertSame(['id', 'title', 'body', 'subtitle'], $columns);
    }

    public function test_inserts_before_named_column(): void
    {
        $result = (new AddFieldTool)->execute([
            'service' => $this->serviceSlug,
            'resource' => 'post',
            'field' => ['column' => 'subtitle', 'type' => 'text'],
            'position' => ['before' => 'body'],
        ]);

        $this->assertSame(2, $this->positionIndex($result));
        $this->assertSame(['id', 'title', 'subtitle', 'body'], array_column($this->readFields(), 'column'));
    }

    public function test_inserts_after_named_column(): void
    {
        $result = (new AddFieldTool)->execute([
            'service' => $this->serviceSlug,
            'resource' => 'post',
            'field' => ['column' => 'slug', 'type' => 'text'],
            'position' => ['after' => 'title'],
        ]);

        $this->assertSame(2, $this->positionIndex($result));
        $this->assertSame(['id', 'title', 'slug', 'body'], array_column($this->readFields(), 'column'));
    }

    public function test_inserts_at_explicit_index(): void
    {
        $result = (new AddFieldTool)->execute([
            'service' => $this->serviceSlug,
            'resource' => 'post',
            'field' => ['column' => 'lead', 'type' => 'text'],
            'position' => ['index' => 0],
        ]);

        $this->assertSame(0, $this->positionIndex($result));
        $this->assertSame(['lead', 'id', 'title', 'body'], array_column($this->readFields(), 'column'));
    }

    public function test_rejects_duplicate_column(): void
    {
        $result = (new AddFieldTool)->execute([
            'service' => $this->serviceSlug,
            'resource' => 'post',
            'field' => ['column' => 'title', 'type' => 'text'],
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('FIELD_ALREADY_EXISTS', $result['code']);
        $this->assertSame(['id', 'title', 'body'], array_column($this->readFields(), 'column'));
    }

    public function test_invalid_position_column_returns_invalid_position(): void
    {
        $result = (new AddFieldTool)->execute([
            'service' => $this->serviceSlug,
            'resource' => 'post',
            'field' => ['column' => 'subtitle', 'type' => 'text'],
            'position' => ['after' => 'nonexistent'],
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('INVALID_POSITION', $result['code']);
    }

    public function test_invalid_index_out_of_bounds_returns_invalid_position(): void
    {
        $result = (new AddFieldTool)->execute([
            'service' => $this->serviceSlug,
            'resource' => 'post',
            'field' => ['column' => 'subtitle', 'type' => 'text'],
            'position' => ['index' => 99],
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('INVALID_POSITION', $result['code']);
    }

    public function test_position_keys_are_mutually_exclusive(): void
    {
        $result = (new AddFieldTool)->execute([
            'service' => $this->serviceSlug,
            'resource' => 'post',
            'field' => ['column' => 'subtitle', 'type' => 'text'],
            'position' => ['before' => 'body', 'after' => 'title'],
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('VALIDATION', $result['code']);
    }

    public function test_pages_subset_emits_hide_on_complement(): void
    {
        $result = (new AddFieldTool)->execute([
            'service' => $this->serviceSlug,
            'resource' => 'post',
            'field' => ['column' => 'admin_only', 'type' => 'text'],
            'pages' => ['form'],
        ]);

        $this->assertSame(['form'], $result['pages_visible']);

        $written = SpecStore::fieldByColumn(SpecStore::read($this->serviceSlug, 'post') ?? [], 'admin_only');
        $this->assertIsArray($written);
        $this->assertSame(['index', 'detail'], $written['hide_on'] ?? null);
    }

    public function test_pages_full_set_does_not_emit_hide_on(): void
    {
        $result = (new AddFieldTool)->execute([
            'service' => $this->serviceSlug,
            'resource' => 'post',
            'field' => ['column' => 'everywhere', 'type' => 'text'],
            'pages' => ['index', 'form', 'detail'],
        ]);

        $this->assertSame(['index', 'form', 'detail'], $result['pages_visible']);

        $written = SpecStore::fieldByColumn(SpecStore::read($this->serviceSlug, 'post') ?? [], 'everywhere');
        $this->assertIsArray($written);
        $this->assertArrayNotHasKey('hide_on', $written);
    }

    public function test_empty_pages_array_rejected(): void
    {
        $result = (new AddFieldTool)->execute([
            'service' => $this->serviceSlug,
            'resource' => 'post',
            'field' => ['column' => 'x', 'type' => 'text'],
            'pages' => [],
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('VALIDATION', $result['code']);
    }

    public function test_unknown_resource_returns_resource_not_found(): void
    {
        $result = (new AddFieldTool)->execute([
            'service' => $this->serviceSlug,
            'resource' => 'nonexistent',
            'field' => ['column' => 'x', 'type' => 'text'],
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('RESOURCE_NOT_FOUND', $result['code']);
    }

    public function test_unknown_service_returns_service_not_registered(): void
    {
        $result = (new AddFieldTool)->execute([
            'service' => 'never-registered-svc',
            'resource' => 'post',
            'field' => ['column' => 'x', 'type' => 'text'],
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('SERVICE_NOT_REGISTERED', $result['code']);
    }

    public function test_appends_into_existing_form_fields_override(): void
    {
        $_ = (new ResourceGenerator)->generate([
            'service' => $this->serviceSlug,
            'name' => 'post',
            'endpoint' => '/posts',
            'fields' => [
                ['column' => 'id', 'type' => 'id'],
                ['column' => 'title', 'type' => 'text'],
            ],
            'form_fields' => [
                ['column' => 'title', 'type' => 'text'],
            ],
        ]);

        $result = (new AddFieldTool)->execute([
            'service' => $this->serviceSlug,
            'resource' => 'post',
            'field' => ['column' => 'subtitle', 'type' => 'text'],
        ]);

        $touched = $result['page_overrides_touched'];
        $this->assertIsArray($touched);
        $this->assertContains('form', $touched);

        $spec = SpecStore::read($this->serviceSlug, 'post');
        $this->assertIsArray($spec);
        $formFields = $spec['form_fields'];
        $this->assertIsArray($formFields);
        $this->assertSame(['title', 'subtitle'], array_column($formFields, 'column'));
    }

    public function test_skips_override_when_pages_excludes_it(): void
    {
        $_ = (new ResourceGenerator)->generate([
            'service' => $this->serviceSlug,
            'name' => 'post',
            'endpoint' => '/posts',
            'fields' => [
                ['column' => 'id', 'type' => 'id'],
                ['column' => 'title', 'type' => 'text'],
            ],
            'form_fields' => [
                ['column' => 'title', 'type' => 'text'],
            ],
        ]);

        $result = (new AddFieldTool)->execute([
            'service' => $this->serviceSlug,
            'resource' => 'post',
            'field' => ['column' => 'subtitle', 'type' => 'text'],
            'pages' => ['index'],
        ]);

        $touched = $result['page_overrides_touched'];
        $this->assertIsArray($touched);
        $this->assertNotContains('form', $touched);

        $spec = SpecStore::read($this->serviceSlug, 'post');
        $this->assertIsArray($spec);
        $formFields = $spec['form_fields'];
        $this->assertIsArray($formFields);
        $this->assertSame(['title'], array_column($formFields, 'column'));
    }

    public function test_missing_required_field_keys_rejected(): void
    {
        $result = (new AddFieldTool)->execute([
            'service' => $this->serviceSlug,
            'resource' => 'post',
            'field' => ['column' => 'x'],
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('VALIDATION', $result['code']);
    }
}
