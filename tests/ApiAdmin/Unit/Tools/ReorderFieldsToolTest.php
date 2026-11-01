<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Unit\Tools;

use App\ApiAdmin\MCP\Generator\ResourceGenerator;
use App\ApiAdmin\MCP\SpecStore\SpecStore;
use App\ApiAdmin\MCP\Tools\ReorderFieldsTool;
use App\ApiAdmin\Providers\ApiAdminServiceProvider;
use App\ApiAdmin\ServiceConfig\ResponseMapping;
use App\ApiAdmin\ServiceConfig\ServiceConfig;
use Illuminate\Support\Facades\File;
use MoonShine\Laravel\Providers\MoonShineServiceProvider;
use MoonShine\MultiPanels\Providers\MultiPanelServiceProvider;
use Orchestra\Testbench\TestCase;

final class ReorderFieldsToolTest extends TestCase
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
        $this->resourcesRoot = sys_get_temp_dir().'/moonshine-reorder-fields-resources-'.uniqid('', true);
        $app['config']->set('api-admin.resources_path', $this->resourcesRoot);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->serviceSlug = 'rosvc'.bin2hex(random_bytes(8));

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

        $_ = (new ResourceGenerator)->generate([
            'service' => $this->serviceSlug,
            'name' => 'post',
            'endpoint' => '/posts',
            'fields' => [
                ['column' => 'id', 'type' => 'id'],
                ['column' => 'title', 'type' => 'text'],
                ['column' => 'body', 'type' => 'textarea'],
                ['column' => 'slug', 'type' => 'text'],
            ],
        ]);
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
     * @return list<string>
     */
    private function readColumns(): array
    {
        $spec = SpecStore::read($this->serviceSlug, 'post');
        $this->assertIsArray($spec);
        $fields = $spec['fields'];
        $this->assertIsArray($fields);
        $cols = [];
        foreach ($fields as $field) {
            if (is_array($field) && is_string($field['column'] ?? null)) {
                $cols[] = $field['column'];
            }
        }

        return $cols;
    }

    public function test_partial_reorder_preserves_trailing_fields(): void
    {
        $result = (new ReorderFieldsTool)->execute([
            'service' => $this->serviceSlug,
            'resource' => 'post',
            'order' => ['title', 'id'],
        ]);

        $this->assertSame(['id', 'title', 'body', 'slug'], $result['before']);
        $this->assertSame(['title', 'id', 'body', 'slug'], $result['after']);
        $this->assertSame(['title', 'id', 'body', 'slug'], $this->readColumns());
    }

    public function test_strict_requires_exhaustive_order(): void
    {
        $result = (new ReorderFieldsTool)->execute([
            'service' => $this->serviceSlug,
            'resource' => 'post',
            'order' => ['title', 'id'],
            'strict' => true,
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('INCOMPLETE_ORDER', $result['code']);
        $missing = $result['missing'];
        $this->assertIsArray($missing);
        $this->assertContains('body', $missing);
        $this->assertContains('slug', $missing);
        $this->assertSame(['id', 'title', 'body', 'slug'], $this->readColumns());
    }

    public function test_duplicate_in_order_rejected(): void
    {
        $result = (new ReorderFieldsTool)->execute([
            'service' => $this->serviceSlug,
            'resource' => 'post',
            'order' => ['title', 'title'],
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('VALIDATION', $result['code']);
    }

    public function test_unknown_column_in_order_returns_field_not_found(): void
    {
        $result = (new ReorderFieldsTool)->execute([
            'service' => $this->serviceSlug,
            'resource' => 'post',
            'order' => ['title', 'never_existed'],
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('FIELD_NOT_FOUND', $result['code']);
    }

    public function test_strict_with_full_order_succeeds(): void
    {
        $result = (new ReorderFieldsTool)->execute([
            'service' => $this->serviceSlug,
            'resource' => 'post',
            'order' => ['slug', 'body', 'title', 'id'],
            'strict' => true,
        ]);

        $this->assertSame(['slug', 'body', 'title', 'id'], $result['after']);
        $this->assertSame(['slug', 'body', 'title', 'id'], $this->readColumns());
    }
}
