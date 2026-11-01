<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Unit\Tools;

use App\ApiAdmin\MCP\Generator\ResourceGenerator;
use App\ApiAdmin\MCP\SpecStore\SpecStore;
use App\ApiAdmin\MCP\Tools\RemoveFieldTool;
use App\ApiAdmin\Providers\ApiAdminServiceProvider;
use App\ApiAdmin\ServiceConfig\ResponseMapping;
use App\ApiAdmin\ServiceConfig\ServiceConfig;
use Illuminate\Support\Facades\File;
use MoonShine\Laravel\Providers\MoonShineServiceProvider;
use MoonShine\MultiPanels\Providers\MultiPanelServiceProvider;
use Orchestra\Testbench\TestCase;

final class RemoveFieldToolTest extends TestCase
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
        $this->resourcesRoot = sys_get_temp_dir().'/moonshine-remove-field-resources-'.uniqid('', true);
        $app['config']->set('api-admin.resources_path', $this->resourcesRoot);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->serviceSlug = 'rmsvc'.substr(str_replace('.', '', uniqid('', true)), 0, 8);

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
     * @param array<string, mixed> $extra
     */
    private function seedResource(array $extra = []): void
    {
        $params = array_merge([
            'service' => $this->serviceSlug,
            'name' => 'post',
            'endpoint' => '/posts',
            'fields' => [
                ['column' => 'id', 'type' => 'id'],
                ['column' => 'title', 'type' => 'text'],
                ['column' => 'body', 'type' => 'textarea'],
            ],
        ], $extra);

        $_ = (new ResourceGenerator)->generate($params);
    }

    /**
     * @return list<string>
     */
    private function readColumns(): array
    {
        $spec = SpecStore::read($this->serviceSlug, 'post');
        $this->assertIsArray($spec);
        /** @var list<array<string, mixed>> $fields */
        $fields = is_array($spec['fields'] ?? null) ? array_values($spec['fields']) : [];
        $cols = [];
        foreach ($fields as $field) {
            if (is_string($field['column'] ?? null)) {
                $cols[] = $field['column'];
            }
        }

        return $cols;
    }

    public function test_removes_unreferenced_field(): void
    {
        $this->seedResource();

        $result = (new RemoveFieldTool)->execute([
            'service' => $this->serviceSlug,
            'resource' => 'post',
            'column' => 'body',
            'confirm' => true,
        ]);

        $this->assertSame('body', $result['column']);
        $this->assertSame(['id', 'title'], $this->readColumns());
    }

    public function test_requires_confirm_true(): void
    {
        $this->seedResource();

        $result = (new RemoveFieldTool)->execute([
            'service' => $this->serviceSlug,
            'resource' => 'post',
            'column' => 'body',
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('CONFIRM_REQUIRED', $result['code']);
        $this->assertSame(['id', 'title', 'body'], $this->readColumns());
    }

    public function test_returns_field_not_found_for_unknown_column(): void
    {
        $this->seedResource();

        $result = (new RemoveFieldTool)->execute([
            'service' => $this->serviceSlug,
            'resource' => 'post',
            'column' => 'never_existed',
            'confirm' => true,
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('FIELD_NOT_FOUND', $result['code']);
    }

    public function test_refuses_when_referenced_in_search(): void
    {
        $this->seedResource(['search' => ['title']]);

        $result = (new RemoveFieldTool)->execute([
            'service' => $this->serviceSlug,
            'resource' => 'post',
            'column' => 'title',
            'confirm' => true,
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('FIELD_REFERENCED', $result['code']);
        $references = $result['references'];
        $this->assertIsArray($references);
        $this->assertNotEmpty($references);
        $this->assertSame(['id', 'title', 'body'], $this->readColumns());
    }

    public function test_force_strips_references_and_removes(): void
    {
        $this->seedResource([
            'search' => ['title', 'body'],
            'rules' => ['title' => 'required'],
            'filters' => [['column' => 'title', 'type' => 'text']],
        ]);

        $result = (new RemoveFieldTool)->execute([
            'service' => $this->serviceSlug,
            'resource' => 'post',
            'column' => 'title',
            'confirm' => true,
            'force' => true,
        ]);

        $this->assertSame('title', $result['column']);
        $this->assertSame(['id', 'body'], $this->readColumns());

        $spec = SpecStore::read($this->serviceSlug, 'post');
        $this->assertIsArray($spec);
        $this->assertSame(['body'], $spec['search'] ?? null);
        $this->assertArrayNotHasKey('rules', $spec);
        $this->assertArrayNotHasKey('filters', $spec);
    }

    public function test_unknown_resource_returns_resource_not_found(): void
    {
        $result = (new RemoveFieldTool)->execute([
            'service' => $this->serviceSlug,
            'resource' => 'nonexistent',
            'column' => 'x',
            'confirm' => true,
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('RESOURCE_NOT_FOUND', $result['code']);
    }
}
