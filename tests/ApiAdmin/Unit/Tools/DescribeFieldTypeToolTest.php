<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Unit\Tools;

use App\ApiAdmin\MCP\Tools\DescribeFieldTypeTool;
use App\ApiAdmin\Providers\ApiAdminServiceProvider;
use MoonShine\Laravel\Providers\MoonShineServiceProvider;
use MoonShine\MultiPanels\Providers\MultiPanelServiceProvider;
use MoonShine\UI\Fields\Password;
use MoonShine\UI\Fields\Select;
use Orchestra\Testbench\TestCase;

final class DescribeFieldTypeToolTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            MoonShineServiceProvider::class,
            MultiPanelServiceProvider::class,
            ApiAdminServiceProvider::class,
        ];
    }

    public function test_schema_shape_is_stable(): void
    {
        $schema = DescribeFieldTypeTool::schema();

        $this->assertSame('describe_field_type', $schema['name']);
        $inputSchema = $schema['inputSchema'];
        $this->assertIsArray($inputSchema);
        $this->assertSame(['type'], $inputSchema['required']);
    }

    public function test_missing_type_returns_validation_error(): void
    {
        $tool = new DescribeFieldTypeTool;

        $result = $tool->execute([]);

        $this->assertFalse($result['success']);
        $this->assertSame('VALIDATION', $result['code']);
    }

    public function test_unknown_type_returns_field_type_unknown(): void
    {
        $tool = new DescribeFieldTypeTool;

        $result = $tool->execute(['type' => 'definitely_not_a_field_type']);

        $this->assertFalse($result['success']);
        $this->assertSame('FIELD_TYPE_UNKNOWN', $result['code']);
        $this->assertArrayHasKey('hint', $result);
    }

    public function test_password_reports_raw_hint_and_reflects_class(): void
    {
        $tool = new DescribeFieldTypeTool;

        $result = $tool->execute(['type' => 'password']);

        $this->assertTrue($result['success']);
        $this->assertSame(Password::class, $result['class']);
        $this->assertSame('Password', $result['alias']);
        $this->assertSame('scalar', $result['group']);
        $this->assertFalse($result['is_custom']);
        $this->assertIsString($result['parent_class']);

        $this->assertIsArray($result['schema_hints']);
        $this->assertArrayHasKey('raw', $result['schema_hints']);

        // `raw` must appear as a chainable method on Password (it's the
        // whole reason generator can auto-emit ->raw()).
        $methodNames = array_column($result['available_methods'], 'name');
        $this->assertContains('raw', $methodNames, 'Password must expose ->raw() as a chainable method');
    }

    public function test_select_exposes_options_searchable_multiple_and_accepts_condition_flags(): void
    {
        $tool = new DescribeFieldTypeTool;

        $result = $tool->execute(['type' => 'select']);

        $this->assertTrue($result['success']);
        $this->assertSame(Select::class, $result['class']);

        $byName = [];
        foreach ($result['available_methods'] as $m) {
            $byName[$m['name']] = $m;
        }

        foreach (['options', 'searchable', 'multiple'] as $knob) {
            $this->assertArrayHasKey($knob, $byName, "Select should expose ->{$knob}()");
        }

        // searchable/multiple/required accept Closure|bool — flagged.
        $this->assertTrue($byName['searchable']['accepts_condition']);
        $this->assertTrue($byName['multiple']['accepts_condition']);

        // signature rendering is non-empty + includes paren pair
        $this->assertStringContainsString('(', $byName['options']['signature']);
        $this->assertStringContainsString(')', $byName['options']['signature']);
    }

    public function test_schema_hints_is_empty_object_when_no_curated_hints(): void
    {
        $tool = new DescribeFieldTypeTool;

        // `text` has no curated hints entry; we still want a stable empty
        // schema_hints payload so the agent sees the key either way.
        $result = $tool->execute(['type' => 'text']);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('schema_hints', $result);
    }
}
