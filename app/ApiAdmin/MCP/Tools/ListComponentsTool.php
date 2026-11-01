<?php

declare(strict_types=1);

namespace App\ApiAdmin\MCP\Tools;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use MoonShine\UI\Components\ActionButton;
use MoonShine\UI\Components\Collapse;
use MoonShine\UI\Components\FormBuilder;
use MoonShine\UI\Components\Heading;
use MoonShine\UI\Components\Layout\Box;
use MoonShine\UI\Components\Layout\Column;
use MoonShine\UI\Components\Layout\Divider;
use MoonShine\UI\Components\Layout\Flex;
use MoonShine\UI\Components\Layout\Grid;
use MoonShine\UI\Components\Layout\LineBreak;
use MoonShine\UI\Components\Table\TableBuilder;
use MoonShine\UI\Components\Tabs;
use MoonShine\UI\Components\Tabs\Tab;
use stdClass;

/**
 * MCP Tool: list_components
 *
 * Advertises every UI component the generator is willing to emit as a
 * layout node in `create_resource`'s `layout.form.tree`. Covers the box
 * primitives shipped by MoonShine 5 (`MoonShine\UI\Components\*`) plus
 * anything the caller generated via `create_component` (scanned from
 * `app/ApiAdmin/Components/Custom/` + the `api-admin.custom_components`
 * config map).
 *
 * Entries missing at runtime (e.g. a MoonShine release that dropped a
 * component) are silently skipped — the list reflects what is actually
 * loadable now, not a frozen catalog.
 */
final class ListComponentsTool implements ToolContract
{
    /**
     * Built-in component catalog, keyed by the name the agent will pass
     * in `layout.form.tree[].type`. Every entry is reflected at runtime
     * via `class_exists` so we stay honest when a class disappears.
     *
     * @var array<string, array{class: class-string, category: 'layout'|'decoration'|'table'|'form'|'action', children_allowed: bool}>
     */
    private const array BUILT_IN = [
        'Box' => ['class' => Box::class, 'category' => 'layout', 'children_allowed' => true],
        'Tabs' => ['class' => Tabs::class, 'category' => 'layout', 'children_allowed' => true],
        'Tab' => ['class' => Tab::class, 'category' => 'layout', 'children_allowed' => true],
        'Grid' => ['class' => Grid::class, 'category' => 'layout', 'children_allowed' => true],
        'Column' => ['class' => Column::class, 'category' => 'layout', 'children_allowed' => true],
        'Collapse' => ['class' => Collapse::class, 'category' => 'layout', 'children_allowed' => true],
        'Flex' => ['class' => Flex::class, 'category' => 'layout', 'children_allowed' => true],
        'LineBreak' => ['class' => LineBreak::class, 'category' => 'decoration', 'children_allowed' => false],
        'Divider' => ['class' => Divider::class, 'category' => 'decoration', 'children_allowed' => false],
        'Heading' => ['class' => Heading::class, 'category' => 'decoration', 'children_allowed' => false],
        'FormBuilder' => ['class' => FormBuilder::class, 'category' => 'form', 'children_allowed' => true],
        'TableBuilder' => ['class' => TableBuilder::class, 'category' => 'table', 'children_allowed' => true],
        'ActionButton' => ['class' => ActionButton::class, 'category' => 'action', 'children_allowed' => false],
    ];

    /**
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return [
            'name' => 'list_components',
            'description' => 'List every UI component available for `layout.form.tree` — built-in MoonShine box primitives + caller-generated custom components (see create_component). Read-only.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => new stdClass,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{
     *     success: true,
     *     count: int,
     *     components: list<array{name: string, class: class-string, category: string, children_allowed: bool, is_custom: bool}>,
     * }
     */
    public function execute(array $params): array
    {
        $components = [];

        foreach (self::BUILT_IN as $name => $meta) {
            if (! class_exists($meta['class'])) {
                Log::warning('list_components: built-in missing at runtime — skipping', [
                    'name' => $name,
                    'class' => $meta['class'],
                ]);

                continue;
            }

            $components[] = [
                'name' => $name,
                'class' => $meta['class'],
                'category' => $meta['category'],
                'children_allowed' => $meta['children_allowed'],
                'is_custom' => false,
            ];
        }

        foreach ($this->customComponents() as $entry) {
            $components[] = $entry;
        }

        Log::debug('list_components: built', ['count' => count($components)]);

        return [
            'success' => true,
            'count' => count($components),
            'components' => $components,
        ];
    }

    /**
     * @return list<array{name: string, class: class-string, category: string, children_allowed: bool, is_custom: true}>
     */
    private function customComponents(): array
    {
        $result = [];
        $seen = [];

        // Shape mirrors api-admin.custom_fields: bare class-string or
        // a full entry array. Keyed by the agent-facing component name.
        $configRaw = config('api-admin.custom_components', []);
        if (is_array($configRaw)) {
            foreach ($configRaw as $name => $value) {
                if (! is_string($name)) {
                    continue;
                }
                if ($name === '') {
                    continue;
                }
                if (is_string($value) && $value !== '' && class_exists($value)) {
                    $result[] = [
                        'name' => $name,
                        'class' => $value,
                        'category' => 'custom',
                        'children_allowed' => true,
                        'is_custom' => true,
                    ];
                    $seen[$name] = true;
                } elseif (is_array($value) && isset($value['class']) && is_string($value['class']) && class_exists($value['class'])) {
                    $category = is_string($value['category'] ?? null) ? $value['category'] : 'custom';
                    $childrenAllowed = is_bool($value['children_allowed'] ?? null)
                        ? $value['children_allowed']
                        : true;
                    $result[] = [
                        'name' => $name,
                        'class' => $value['class'],
                        'category' => $category,
                        'children_allowed' => $childrenAllowed,
                        'is_custom' => true,
                    ];
                    $seen[$name] = true;
                }
            }
        }

        // Filesystem fallback — pick up PHP files under
        // app/ApiAdmin/Components/Custom/ even if the author forgot
        // to register them in config. This is a safety net so
        // `create_component` → visibility works before
        // composer dump-autoload runs.
        $dir = base_path('app/ApiAdmin/Components/Custom');
        if (File::isDirectory($dir)) {
            foreach (File::files($dir) as $file) {
                $name = $file->getFilenameWithoutExtension();
                if ($name === '') {
                    continue;
                }
                if (isset($seen[$name])) {
                    continue;
                }
                $fqcn = 'App\\ApiAdmin\\Components\\Custom\\'.$name;
                if (! class_exists($fqcn)) {
                    continue;
                }
                $result[] = [
                    'name' => $name,
                    'class' => $fqcn,
                    'category' => 'custom',
                    'children_allowed' => true,
                    'is_custom' => true,
                ];
                $seen[$name] = true;
            }
        }

        return $result;
    }
}
