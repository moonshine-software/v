<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Unit\Tools;

use App\ApiAdmin\MCP\Generator\ResourceGenerator;
use App\ApiAdmin\MCP\Tools\ListFieldsTool;
use App\ApiAdmin\Providers\ApiAdminServiceProvider;
use MoonShine\Laravel\Providers\MoonShineServiceProvider;
use MoonShine\MultiPanels\Providers\MultiPanelServiceProvider;
use MoonShine\UI\Fields\Select;
use MoonShine\UI\Fields\Text;
use Orchestra\Testbench\TestCase;

/**
 * Covers the `list_fields` MCP tool and its source of truth
 * ({@see ResourceGenerator::fieldTypeCatalog()}).
 */
final class ListFieldsToolTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            MoonShineServiceProvider::class,
            MultiPanelServiceProvider::class,
            ApiAdminServiceProvider::class,
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Reset custom_fields between tests — config() is application-scoped
        // but Orchestra reuses it; explicit reset keeps tests order-independent.
        config(['api-admin.custom_fields' => []]);
    }

    public function test_schema_shape_is_stable(): void
    {
        $schema = ListFieldsTool::schema();

        $this->assertSame('list_fields', $schema['name']);
        $this->assertArrayHasKey('inputSchema', $schema);
        $this->assertIsString($schema['description']);
    }

    public function test_lists_all_built_in_fields_with_group_metadata(): void
    {
        $tool = new ListFieldsTool;

        $result = $tool->execute([]);

        $this->assertTrue($result['success']);
        $fields = $result['fields'];

        $byType = [];
        foreach ($fields as $entry) {
            $byType[$entry['type']] = $entry;
        }

        // Every documented built-in type is reachable via the catalog.
        foreach (['id', 'text', 'textarea', 'number', 'email', 'password', 'date', 'datetime', 'checkbox', 'switcher', 'select', 'image', 'file', 'json', 'color', 'slug', 'url', 'belongs_to', 'has_many', 'has_one', 'belongs_to_many'] as $type) {
            $this->assertArrayHasKey($type, $byType, "Built-in type {$type} missing from list_fields output");
            $this->assertFalse($byType[$type]['is_custom'], "Built-in {$type} must not be marked is_custom");
            $this->assertContains($byType[$type]['group'], ['scalar', 'relation', 'media']);
            $this->assertNotSame('', $byType[$type]['description']);
        }

        // Group assignment smoke: scalar for text, relation for belongs_to, media for image.
        $this->assertSame('scalar', $byType['text']['group']);
        $this->assertSame('relation', $byType['belongs_to']['group']);
        $this->assertSame('media', $byType['image']['group']);

        $this->assertSame(count($fields), $result['count']);
    }

    public function test_merges_custom_fields_from_config_with_is_custom_flag(): void
    {
        // Shape 1: bare class-string — alias derived from basename.
        config(['api-admin.custom_fields' => [
            'my_color_swatch' => Select::class,
            // Shape 2: full entry — group + description + explicit alias.
            'my_rich_text' => [
                'class' => Text::class,
                'alias' => 'MyRichText',
                'group' => 'scalar',
                'description' => 'Wysiwyg-style text with toolbar.',
            ],
        ]]);

        $tool = new ListFieldsTool;

        $result = $tool->execute([]);

        $byType = [];
        foreach ($result['fields'] as $entry) {
            $byType[$entry['type']] = $entry;
        }

        $this->assertArrayHasKey('my_color_swatch', $byType);
        $this->assertTrue($byType['my_color_swatch']['is_custom']);
        $this->assertSame(Select::class, $byType['my_color_swatch']['class']);
        $this->assertSame('Select', $byType['my_color_swatch']['alias']);
        $this->assertSame('scalar', $byType['my_color_swatch']['group']);

        $this->assertArrayHasKey('my_rich_text', $byType);
        $this->assertTrue($byType['my_rich_text']['is_custom']);
        $this->assertSame('MyRichText', $byType['my_rich_text']['alias']);
        $this->assertSame('Wysiwyg-style text with toolbar.', $byType['my_rich_text']['description']);

        // Built-ins still present — custom does not shadow them unless type key collides.
        $this->assertArrayHasKey('text', $byType);
        $this->assertFalse($byType['text']['is_custom']);
    }

    public function test_field_type_catalog_shape_is_stable(): void
    {
        $catalog = ResourceGenerator::fieldTypeCatalog();

        foreach ($catalog as $type => $entry) {
            $this->assertArrayHasKey('class', $entry, "Catalog[{$type}] missing class");
            $this->assertArrayHasKey('alias', $entry, "Catalog[{$type}] missing alias");
            $this->assertArrayHasKey('group', $entry, "Catalog[{$type}] missing group");
            $this->assertArrayHasKey('description', $entry, "Catalog[{$type}] missing description");
            $this->assertArrayHasKey('is_custom', $entry, "Catalog[{$type}] missing is_custom");
        }
    }
}
