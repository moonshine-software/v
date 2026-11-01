<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Unit\Tools;

use App\ApiAdmin\MCP\McpServer;
use App\ApiAdmin\MCP\Tools\DeleteFieldTool;
use App\ApiAdmin\MCP\Tools\UpdateFieldTool;
use App\ApiAdmin\Providers\ApiAdminServiceProvider;
use Illuminate\Support\Facades\File;
use MoonShine\Laravel\Providers\MoonShineServiceProvider;
use MoonShine\MultiPanels\Providers\MultiPanelServiceProvider;
use Orchestra\Testbench\TestCase;
use Tests\ApiAdmin\Concerns\UsesIsolatedProjectRoot;

final class UpdateDeleteFieldToolTest extends TestCase
{
    use UsesIsolatedProjectRoot;

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
        $this->useIsolatedProjectRoot($app);
        $app['config']->set('api-admin.specs_path', base_path('.ai-factory/specs'));
    }

    protected function tearDown(): void
    {
        $this->removeIsolatedProjectRoot();

        parent::tearDown();
    }

    public function test_field_tools_keep_the_config_valid_through_the_full_lifecycle(): void
    {
        $server = new McpServer;
        $original = File::get(config_path('api-admin.php'));

        foreach ([
            ['create_field', ['name' => 'TestFirst', 'extends' => 'text']],
            ['create_field', ['name' => 'TestSecond', 'extends' => 'text']],
            ['update_field', ['name' => 'TestFirst', 'extends' => 'textarea']],
            ['delete_field', ['name' => 'TestFirst', 'confirm' => true]],
        ] as [$tool, $params]) {
            $result = $server->executeTool($tool, $params);
            $this->assertTrue($result['success'], $tool.': '.json_encode($result));
            $this->assertIsArray($this->freshApiAdminConfig()['custom_fields'], $tool);
        }

        $this->assertSame(['test_second'], array_keys((array) $this->freshApiAdminConfig()['custom_fields']));

        $this->assertTrue($server->executeTool('delete_field', ['name' => 'TestSecond', 'confirm' => true])['success']);
        $this->assertSame($original, File::get(config_path('api-admin.php')));
    }

    public function test_generation_is_refused_and_rolled_back_when_the_config_is_invalid(): void
    {
        $broken = "<?php\n\nreturn [\n    'custom_fields' => [\n    ],\n    ],\n];\n";
        File::put(config_path('api-admin.php'), $broken);

        $result = (new McpServer)->executeTool('create_field', ['name' => 'TestRefused', 'extends' => 'text']);

        $this->assertFalse($result['success']);
        $this->assertIsString($result['error']);
        $this->assertStringContainsString('invalid PHP', $result['error']);
        $this->assertSame($broken, File::get(config_path('api-admin.php')));
        $this->assertFileDoesNotExist(base_path('app/ApiAdmin/Fields/Custom/TestRefused.php'));
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
