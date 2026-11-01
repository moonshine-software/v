<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Unit\Tools;

use App\ApiAdmin\MCP\Tools\ListComponentsTool;
use App\ApiAdmin\Providers\ApiAdminServiceProvider;
use MoonShine\Laravel\Providers\MoonShineServiceProvider;
use MoonShine\MultiPanels\Providers\MultiPanelServiceProvider;
use MoonShine\UI\Components\Layout\Box;
use Orchestra\Testbench\TestCase;

final class ListComponentsToolTest extends TestCase
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

        config(['api-admin.custom_components' => []]);
    }

    public function test_schema_shape_is_stable(): void
    {
        $schema = ListComponentsTool::schema();

        $this->assertSame('list_components', $schema['name']);
    }

    public function test_built_in_components_are_listed(): void
    {
        $tool = new ListComponentsTool;

        $result = $tool->execute([]);

        $byName = [];
        foreach ($result['components'] as $entry) {
            $byName[$entry['name']] = $entry;
        }

        foreach (['Box', 'Tabs', 'Tab', 'Grid', 'Column', 'Collapse', 'Flex', 'LineBreak', 'Divider', 'Heading', 'FormBuilder', 'TableBuilder', 'ActionButton'] as $name) {
            $this->assertArrayHasKey($name, $byName, "Built-in component {$name} missing");
            $this->assertFalse($byName[$name]['is_custom']);
        }

        $this->assertSame(Box::class, $byName['Box']['class']);
        $this->assertTrue($byName['Box']['children_allowed']);
        $this->assertFalse($byName['LineBreak']['children_allowed']);
        $this->assertSame('layout', $byName['Box']['category']);
        $this->assertSame('decoration', $byName['LineBreak']['category']);
    }

    public function test_custom_component_from_config_is_flagged(): void
    {
        // Reuse Box as a stand-in custom class — we just need a
        // loadable class-string. The important part is is_custom: true.
        config(['api-admin.custom_components' => [
            'InfoBanner' => Box::class,
        ]]);

        $tool = new ListComponentsTool;

        $result = $tool->execute([]);
        $byName = [];
        foreach ($result['components'] as $entry) {
            $byName[$entry['name']] = $entry;
        }

        $this->assertArrayHasKey('InfoBanner', $byName);
        $this->assertTrue($byName['InfoBanner']['is_custom']);
        $this->assertSame(Box::class, $byName['InfoBanner']['class']);
        $this->assertSame('custom', $byName['InfoBanner']['category']);
    }

    public function test_custom_component_full_entry_overrides_category(): void
    {
        config(['api-admin.custom_components' => [
            'MyHero' => [
                'class' => Box::class,
                'category' => 'decoration',
                'children_allowed' => false,
            ],
        ]]);

        $tool = new ListComponentsTool;

        $result = $tool->execute([]);
        $byName = [];
        foreach ($result['components'] as $entry) {
            $byName[$entry['name']] = $entry;
        }

        $this->assertArrayHasKey('MyHero', $byName);
        $this->assertSame('decoration', $byName['MyHero']['category']);
        $this->assertFalse($byName['MyHero']['children_allowed']);
    }

    public function test_count_matches_components_array(): void
    {
        $tool = new ListComponentsTool;

        $result = $tool->execute([]);

        $this->assertSame(count($result['components']), $result['count']);
    }
}
