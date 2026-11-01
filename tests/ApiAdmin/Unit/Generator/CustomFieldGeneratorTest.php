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
use Tests\ApiAdmin\Concerns\UsesIsolatedProjectRoot;

final class CustomFieldGeneratorTest extends TestCase
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
    }

    protected function setUp(): void
    {
        parent::setUp();

        config(['api-admin.custom_fields' => []]);
    }

    protected function tearDown(): void
    {
        $this->removeIsolatedProjectRoot();

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

    public function test_config_stays_valid_across_several_fields_and_restores_after_deleting_them(): void
    {
        $original = File::get(config_path('api-admin.php'));
        $generator = new CustomFieldGenerator;

        $generator->generate(['name' => 'TestFirst', 'extends' => 'text']);
        $generator->generate(['name' => 'TestSecond', 'extends' => 'number', 'alias' => "It's"]);
        $generator->generate(['name' => 'TestFirst', 'extends' => 'textarea']);

        $fields = $this->freshApiAdminConfig()['custom_fields'];
        $this->assertSame([
            'test_first' => ['class' => 'App\\ApiAdmin\\Fields\\Custom\\TestFirst', 'alias' => 'TestFirst'],
            'test_second' => ['class' => 'App\\ApiAdmin\\Fields\\Custom\\TestSecond', 'alias' => "It's"],
        ], $fields);

        $this->assertTrue($generator->delete('TestFirst'));
        $this->assertSame(['test_second'], array_keys((array) $this->freshApiAdminConfig()['custom_fields']));

        $this->assertTrue($generator->delete('TestSecond'));
        $this->assertSame($original, File::get(config_path('api-admin.php')));
    }

    public function test_unknown_parent_type_throws(): void
    {
        $this->expectException(RuntimeException::class);

        (new CustomFieldGenerator)->generate(['name' => 'TestBad', 'extends' => 'definitely_not_a_field']);
    }
}
