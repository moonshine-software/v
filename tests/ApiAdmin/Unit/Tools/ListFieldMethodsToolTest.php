<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Unit\Tools;

use App\ApiAdmin\MCP\Tools\ListFieldMethodsTool;
use App\ApiAdmin\Providers\ApiAdminServiceProvider;
use MoonShine\Laravel\Providers\MoonShineServiceProvider;
use MoonShine\MultiPanels\Providers\MultiPanelServiceProvider;
use Orchestra\Testbench\TestCase;

final class ListFieldMethodsToolTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            MoonShineServiceProvider::class,
            MultiPanelServiceProvider::class,
            ApiAdminServiceProvider::class,
        ];
    }

    /**
     * @return array<string, array{field_types: list<string>, accepts_condition: bool, category?: string}>
     */
    private function methodMap(ListFieldMethodsTool $tool, ?string $type = null): array
    {
        $params = $type === null ? [] : ['type' => $type];
        $result = $tool->execute($params);
        $this->assertTrue($result['success']);

        $map = [];
        foreach ($result['methods'] as $entry) {
            $map[$entry['name']] = $entry;
        }

        return $map;
    }

    public function test_schema_shape_is_stable(): void
    {
        $schema = ListFieldMethodsTool::schema();

        $this->assertSame('list_field_methods', $schema['name']);
        $inputSchema = $schema['inputSchema'];
        $this->assertIsArray($inputSchema);
        $properties = $inputSchema['properties'];
        $this->assertIsArray($properties);
        $this->assertArrayHasKey('type', $properties);
    }

    public function test_unknown_scope_type_returns_error(): void
    {
        $tool = new ListFieldMethodsTool;

        $result = $tool->execute(['type' => 'definitely_not_a_field']);

        $this->assertFalse($result['success']);
        $this->assertSame('FIELD_TYPE_UNKNOWN', $result['code']);
    }

    public function test_sortable_appears_for_text_and_select(): void
    {
        $tool = new ListFieldMethodsTool;

        $map = $this->methodMap($tool);

        $this->assertArrayHasKey('sortable', $map);
        $this->assertContains('text', $map['sortable']['field_types']);
        $this->assertContains('select', $map['sortable']['field_types']);

        // `sortable` is a data knob — it should land in the `data` bucket.
        $this->assertSame('data', $map['sortable']['category'] ?? null);
    }

    public function test_options_only_appears_for_select_family(): void
    {
        $tool = new ListFieldMethodsTool;

        $map = $this->methodMap($tool);

        $this->assertArrayHasKey('options', $map);
        // `options` is defined on Select + WithOptions trait users. It
        // should NOT appear on plain scalar fields like text/textarea.
        $this->assertContains('select', $map['options']['field_types']);
        $this->assertNotContains('text', $map['options']['field_types']);
        $this->assertNotContains('textarea', $map['options']['field_types']);
    }

    public function test_raw_only_appears_for_password(): void
    {
        $tool = new ListFieldMethodsTool;

        $map = $this->methodMap($tool);

        $this->assertArrayHasKey('raw', $map);
        $this->assertContains('password', $map['raw']['field_types']);
        $this->assertNotContains('text', $map['raw']['field_types']);
        $this->assertNotContains('number', $map['raw']['field_types']);
    }

    public function test_scoped_output_restricts_to_single_field(): void
    {
        $tool = new ListFieldMethodsTool;

        $map = $this->methodMap($tool, 'password');

        foreach ($map as $entry) {
            $this->assertSame(['password'], $entry['field_types']);
        }

        $this->assertArrayHasKey('raw', $map);
    }

    public function test_methods_without_category_rule_omit_the_key(): void
    {
        $tool = new ListFieldMethodsTool;

        $map = $this->methodMap($tool);
        $foundOmission = array_any($map, fn (array $entry): bool => ! array_key_exists('category', $entry));
        $this->assertTrue($foundOmission, 'Expected at least one method to have no category key');
    }
}
