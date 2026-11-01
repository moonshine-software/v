<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Unit\Tools;

use App\ApiAdmin\MCP\Tools\CreateComponentTool;
use App\ApiAdmin\MCP\Tools\DeleteComponentTool;
use App\ApiAdmin\MCP\Tools\ListComponentsTool;
use App\ApiAdmin\MCP\Tools\UpdateComponentTool;
use App\ApiAdmin\Providers\ApiAdminServiceProvider;
use Illuminate\Support\Facades\File;
use MoonShine\Laravel\Providers\MoonShineServiceProvider;
use MoonShine\MultiPanels\Providers\MultiPanelServiceProvider;
use Orchestra\Testbench\TestCase;

final class ComponentToolsTest extends TestCase
{
    private string $configSnapshot = '';

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
        $projectRoot = dirname(__DIR__, 4);
        $app->setBasePath($projectRoot);
        $app['config']->set('api-admin.specs_path', base_path('.ai-factory/specs'));
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->configSnapshot = File::get(base_path('config/api-admin.php'));
        config(['api-admin.custom_components' => []]);
    }

    protected function tearDown(): void
    {
        File::put(base_path('config/api-admin.php'), $this->configSnapshot);

        $classDir = base_path('app/ApiAdmin/Components/Custom');
        if (File::isDirectory($classDir)) {
            foreach (File::files($classDir) as $f) {
                if (str_starts_with($f->getFilename(), 'Test')) {
                    File::delete($f->getPathname());
                }
            }
        }

        $viewDir = base_path('resources/views/api-admin/components/custom');
        if (File::isDirectory($viewDir)) {
            foreach (File::files($viewDir) as $f) {
                if (str_starts_with($f->getFilename(), 'test-')) {
                    File::delete($f->getPathname());
                }
            }
        }

        parent::tearDown();
    }

    public function test_create_component_writes_class_and_view(): void
    {
        $result = (new CreateComponentTool)->execute([
            'name' => 'TestBanner',
            'extends' => 'Box',
            'view_content' => '    <h1>Test Banner</h1>',
        ]);

        $this->assertTrue($result['success'], (string) json_encode($result));
        $this->assertIsString($result['file']);
        $this->assertIsString($result['view']);
        $this->assertFileExists($result['file']);
        $this->assertFileExists($result['view']);
        $this->assertStringContainsString('<h1>Test Banner</h1>', File::get($result['view']));
    }

    public function test_create_component_surfaces_through_list_components(): void
    {
        (new CreateComponentTool)->execute([
            'name' => 'TestInfoBox',
        ]);

        $listed = (new ListComponentsTool)->execute([]);

        $names = array_column($listed['components'], 'name');
        $this->assertContains('TestInfoBox', $names);
    }

    public function test_update_component_requires_existing(): void
    {
        $result = (new UpdateComponentTool)->execute([
            'name' => 'TestDoesNotExist',
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('COMPONENT_NOT_FOUND', $result['code']);
    }

    public function test_delete_component_requires_confirm(): void
    {
        $result = (new DeleteComponentTool)->execute(['name' => 'Whatever']);

        $this->assertFalse($result['success']);
        $this->assertSame('CONFIRM_REQUIRED', $result['code']);
    }

    public function test_delete_component_flags_in_use_from_spec_tree(): void
    {
        (new CreateComponentTool)->execute(['name' => 'TestUsedBanner']);

        $specDir = base_path('.ai-factory/specs/test_cmp_dep');
        File::ensureDirectoryExists($specDir);
        $encoded = json_encode([
            'endpoint' => '/x',
            'fields' => [],
            'layout' => [
                'form' => [
                    'tree' => [
                        'type' => 'Box',
                        'children' => [
                            ['type' => 'TestUsedBanner'],
                        ],
                    ],
                ],
            ],
        ]);
        File::put($specDir.'/x.json', $encoded === false ? '{}' : $encoded);

        $result = (new DeleteComponentTool)->execute([
            'name' => 'TestUsedBanner',
            'confirm' => true,
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('COMPONENT_IN_USE', $result['code']);

        (new DeleteComponentTool)->execute([
            'name' => 'TestUsedBanner',
            'confirm' => true,
            'force' => true,
        ]);

        File::deleteDirectory($specDir);
    }
}
