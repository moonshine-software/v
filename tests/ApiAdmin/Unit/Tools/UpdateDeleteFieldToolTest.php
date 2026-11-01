<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Unit\Tools;

use App\ApiAdmin\MCP\Tools\DeleteFieldTool;
use App\ApiAdmin\MCP\Tools\UpdateFieldTool;
use App\ApiAdmin\Providers\ApiAdminServiceProvider;
use Illuminate\Support\Facades\File;
use MoonShine\Laravel\Providers\MoonShineServiceProvider;
use MoonShine\MultiPanels\Providers\MultiPanelServiceProvider;
use Orchestra\Testbench\TestCase;

final class UpdateDeleteFieldToolTest extends TestCase
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
    }

    protected function tearDown(): void
    {
        File::put(base_path('config/api-admin.php'), $this->configSnapshot);

        $dir = base_path('app/ApiAdmin/Fields/Custom');
        if (File::isDirectory($dir)) {
            foreach (File::files($dir) as $file) {
                if (str_starts_with($file->getFilename(), 'Test')) {
                    File::delete($file->getPathname());
                }
            }
        }

        $stubDependentsDir = base_path('.ai-factory/specs/test_dep_svc');
        if (File::isDirectory($stubDependentsDir)) {
            File::deleteDirectory($stubDependentsDir);
        }

        parent::tearDown();
    }

    public function test_update_field_refuses_when_field_missing(): void
    {
        $result = (new UpdateFieldTool)->execute([
            'name' => 'TestNotThere',
            'extends' => 'text',
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('FIELD_NOT_FOUND', $result['code']);
    }

    public function test_delete_field_requires_confirm(): void
    {
        $result = (new DeleteFieldTool)->execute(['name' => 'TestAnything']);

        $this->assertFalse($result['success']);
        $this->assertSame('CONFIRM_REQUIRED', $result['code']);
    }

    public function test_delete_field_surfaces_in_use_dependents(): void
    {
        // Create field + write a dependent spec sidecar.
        File::put(base_path('app/ApiAdmin/Fields/Custom/TestRichText.php'), <<<'PHP'
<?php

namespace App\ApiAdmin\Fields\Custom;

use MoonShine\UI\Fields\Text;

final class TestRichText extends Text {}
PHP);

        $specDir = base_path('.ai-factory/specs/test_dep_svc');
        File::ensureDirectoryExists($specDir);
        $encoded = json_encode([
            'endpoint' => '/posts',
            'fields' => [
                ['column' => 'id', 'type' => 'id'],
                ['column' => 'body', 'type' => 'test_rich_text'],
            ],
        ]);
        File::put($specDir.'/post.json', $encoded === false ? '{}' : $encoded);

        $result = (new DeleteFieldTool)->execute([
            'name' => 'TestRichText',
            'confirm' => true,
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('FIELD_IN_USE', $result['code']);
        $dependents = $result['dependents'];
        $this->assertIsArray($dependents);
        $this->assertNotEmpty($dependents);

        // Clean up — force-delete so the field doesn't leak to next test.
        (new DeleteFieldTool)->execute([
            'name' => 'TestRichText',
            'confirm' => true,
            'force' => true,
        ]);
    }

    public function test_delete_field_force_overrides_in_use(): void
    {
        File::put(base_path('app/ApiAdmin/Fields/Custom/TestForceDelete.php'), <<<'PHP'
<?php

namespace App\ApiAdmin\Fields\Custom;

use MoonShine\UI\Fields\Text;

final class TestForceDelete extends Text {}
PHP);

        $specDir = base_path('.ai-factory/specs/test_dep_svc');
        File::ensureDirectoryExists($specDir);
        $encoded = json_encode([
            'endpoint' => '/a',
            'fields' => [['column' => 'x', 'type' => 'test_force_delete']],
        ]);
        File::put($specDir.'/a.json', $encoded === false ? '{}' : $encoded);

        $result = (new DeleteFieldTool)->execute([
            'name' => 'TestForceDelete',
            'confirm' => true,
            'force' => true,
        ]);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['forced']);
        $this->assertFileDoesNotExist(base_path('app/ApiAdmin/Fields/Custom/TestForceDelete.php'));
    }
}
