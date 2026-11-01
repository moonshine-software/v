<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Unit\Generator;

use App\ApiAdmin\MCP\Generator\CustomFieldGenerator;
use App\ApiAdmin\MCP\Generator\ResourceGenerator;
use App\ApiAdmin\Providers\ApiAdminServiceProvider;
use Illuminate\Support\Facades\File;
use MoonShine\Laravel\Providers\MoonShineServiceProvider;
use MoonShine\MultiPanels\Providers\MultiPanelServiceProvider;
use Orchestra\Testbench\TestCase;
use RuntimeException;

final class CustomFieldGeneratorTest extends TestCase
{
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
        // Orchestra Testbench spins up a stub Laravel app in
        // vendor/orchestra/testbench-core/laravel, where base_path +
        // config_path both resolve. Point them at the real project so
        // the generator edits the actual config/api-admin.php and emits
        // the custom field under app/ApiAdmin/Fields/Custom (tests clean
        // up their own files on tearDown).
        $projectRoot = dirname(__DIR__, 4);
        $app->setBasePath($projectRoot);
    }

    /** @var list<string> */
    private array $configSnapshot = [];

    private string $configPath = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->configPath = base_path('config/api-admin.php');
        // Snapshot so tearDown can restore — generator mutates the config file.
        $this->configSnapshot = [File::get($this->configPath)];

        config(['api-admin.custom_fields' => []]);
    }

    protected function tearDown(): void
    {
        if ($this->configPath !== '' && isset($this->configSnapshot[0])) {
            File::put($this->configPath, $this->configSnapshot[0]);
        }

        $customDir = base_path('app/ApiAdmin/Fields/Custom');
        if (File::isDirectory($customDir)) {
            foreach (File::files($customDir) as $file) {
                if (str_starts_with($file->getFilename(), 'Test')) {
                    File::delete($file->getPathname());
                }
            }
        }

        parent::tearDown();
    }

    public function test_generates_custom_field_and_registers_type_key(): void
    {
        $result = (new CustomFieldGenerator)->generate([
            'name' => 'TestMoneyInput',
            'extends' => 'number',
        ]);

        $this->assertFileExists($result['file']);
        $content = File::get($result['file']);
        $this->assertStringContainsString('final class TestMoneyInput extends Number', $content);
        $this->assertStringContainsString('use MoonShine\\UI\\Fields\\Number;', $content);

        // Type key registered in the catalog via config merge.
        $catalog = ResourceGenerator::fieldTypeCatalog();
        $this->assertArrayHasKey('test_money_input', $catalog);
        $this->assertTrue($catalog['test_money_input']['is_custom']);
        $this->assertSame($result['class'], $catalog['test_money_input']['class']);
    }

    public function test_regenerating_same_name_is_idempotent(): void
    {
        $gen = new CustomFieldGenerator;

        $first = $gen->generate(['name' => 'TestIdempotent', 'extends' => 'text']);
        $second = $gen->generate(['name' => 'TestIdempotent', 'extends' => 'text']);

        $this->assertSame($first['file'], $second['file']);

        // Config should not accumulate duplicate entries.
        $configBody = File::get(base_path('config/api-admin.php'));
        $occurrences = substr_count($configBody, "'test_idempotent' =>");
        $this->assertSame(1, $occurrences, "Expected 'test_idempotent' registered exactly once, got {$occurrences}");
    }

    public function test_delete_removes_file_and_config_entry(): void
    {
        $gen = new CustomFieldGenerator;
        $gen->generate(['name' => 'TestDeletable', 'extends' => 'text']);

        $this->assertTrue($gen->delete('TestDeletable'));

        $file = base_path('app/ApiAdmin/Fields/Custom/TestDeletable.php');
        $this->assertFileDoesNotExist($file);
        $catalog = ResourceGenerator::fieldTypeCatalog();
        $this->assertArrayNotHasKey('test_deletable', $catalog);
    }

    public function test_unknown_parent_type_throws(): void
    {
        $this->expectException(RuntimeException::class);

        (new CustomFieldGenerator)->generate(['name' => 'TestBad', 'extends' => 'definitely_not_a_field']);
    }
}
