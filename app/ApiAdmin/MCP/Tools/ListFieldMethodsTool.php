<?php

declare(strict_types=1);

namespace App\ApiAdmin\MCP\Tools;

use App\ApiAdmin\MCP\Generator\ResourceGenerator;
use Illuminate\Support\Facades\Log;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionUnionType;

/**
 * MCP Tool: list_field_methods
 *
 * Union of every chainable method across every field type currently in
 * the catalog. Each entry reports which types expose it — so the agent
 * can answer "does Select support `sortable`?" without reflecting on
 * every class itself. Optional `type` parameter narrows the output to
 * one field's chain.
 *
 * A lightweight name-based `category` is attached as a hint
 * (visibility / validation / data / ui); it's intentionally fuzzy, so
 * methods that do not match any rule drop the key entirely rather than
 * lie by picking a default bucket.
 */
final class ListFieldMethodsTool implements ToolContract
{
    /**
     * Name-prefix / exact-match patterns mapped to a category bucket.
     * Ordered: first match wins. Keep narrow — when nothing matches,
     * the category key is omitted (see plan: "drop category if fuzzy
     * rather than lie").
     *
     * @var list<array{match: string, mode: 'prefix'|'equals', category: 'visibility'|'validation'|'data'|'ui'}>
     */
    private const array CATEGORY_RULES = [
        ['match' => 'hideOn', 'mode' => 'prefix', 'category' => 'visibility'],
        ['match' => 'showOn', 'mode' => 'prefix', 'category' => 'visibility'],
        ['match' => 'onlyOn', 'mode' => 'prefix', 'category' => 'visibility'],
        ['match' => 'columnSelection', 'mode' => 'prefix', 'category' => 'visibility'],
        ['match' => 'sticky', 'mode' => 'prefix', 'category' => 'visibility'],
        ['match' => 'required', 'mode' => 'prefix', 'category' => 'validation'],
        ['match' => 'nullable', 'mode' => 'prefix', 'category' => 'validation'],
        ['match' => 'disabled', 'mode' => 'equals', 'category' => 'validation'],
        ['match' => 'readonly', 'mode' => 'equals', 'category' => 'validation'],
        ['match' => 'valueKey', 'mode' => 'equals', 'category' => 'data'],
        ['match' => 'labelKey', 'mode' => 'equals', 'category' => 'data'],
        ['match' => 'options', 'mode' => 'equals', 'category' => 'data'],
        ['match' => 'searchable', 'mode' => 'equals', 'category' => 'data'],
        ['match' => 'multiple', 'mode' => 'equals', 'category' => 'data'],
        ['match' => 'sortable', 'mode' => 'equals', 'category' => 'data'],
        ['match' => 'default', 'mode' => 'equals', 'category' => 'data'],
        ['match' => 'raw', 'mode' => 'equals', 'category' => 'data'],
        ['match' => 'rawMode', 'mode' => 'equals', 'category' => 'data'],
        ['match' => 'dependsOn', 'mode' => 'equals', 'category' => 'data'],
    ];

    /**
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return [
            'name' => 'list_field_methods',
            'description' => 'Union of chainable methods across every field type (or scoped to one type via the optional `type` parameter). Each entry shows which field types expose it and whether it accepts a Closure|bool condition. Hosted mode exposes only the safe declarative generation allowlist; callbacks, custom views and storage operations are excluded. Read-only.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'type' => [
                        'type' => 'string',
                        'description' => 'Optional — restrict to one field type (e.g. "select"). Omit for union across all fields.',
                    ],
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{
     *     success: true,
     *     type_scope: string|null,
     *     count: int,
     *     methods: list<array{name: string, field_types: list<string>, accepts_condition: bool, category?: 'visibility'|'validation'|'data'|'ui'}>,
     * }|array{
     *     success: false,
     *     error: string,
     *     code: string,
     *     hint?: string,
     * }
     */
    public function execute(array $params): array
    {
        $scopeType = isset($params['type']) && is_string($params['type']) && $params['type'] !== ''
            ? $params['type']
            : null;

        $catalog = ResourceGenerator::fieldTypeCatalog();

        if ($scopeType !== null && ! isset($catalog[$scopeType])) {
            return [
                'success' => false,
                'error' => "Unknown field type: {$scopeType}",
                'code' => 'FIELD_TYPE_UNKNOWN',
                'hint' => 'Call list_fields to see every accepted type.',
            ];
        }

        $scanTypes = $scopeType !== null ? [$scopeType] : array_keys($catalog);

        /** @var array<string, array{field_types: array<string, true>, class: class-string, first_method: ReflectionMethod}> $agg */
        $agg = [];
        foreach ($scanTypes as $type) {
            $class = $catalog[$type]['class'];

            if (! class_exists($class)) {
                Log::warning('list_field_methods: catalog references missing class — skipping', [
                    'type' => $type,
                    'class' => $class,
                ]);

                continue;
            }

            $reflection = new ReflectionClass($class);

            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->isStatic()) {
                    continue;
                }
                if ($method->isConstructor()) {
                    continue;
                }
                if ($method->isDestructor()) {
                    continue;
                }
                $name = $method->getName();
                if (! ResourceGenerator::isFieldMethodAllowed($name)) {
                    continue;
                }
                if (str_starts_with($name, '__')) {
                    continue;
                }
                if (! $this->returnTypeIsChainable($method, $reflection->getName())) {
                    continue;
                }

                $agg[$name] ??= [
                    'field_types' => [],
                    'class' => $class,
                    'first_method' => $method,
                ];
                $agg[$name]['field_types'][$type] = true;
            }
        }

        $methods = [];
        foreach ($agg as $name => $info) {
            $entry = [
                'name' => $name,
                'field_types' => array_keys($info['field_types']),
                'accepts_condition' => ResourceGenerator::methodAcceptsConditionArg($info['class'], $name),
            ];

            $category = $this->categorize($name);
            if ($category !== null) {
                $entry['category'] = $category;
            }

            $methods[] = $entry;
        }

        usort($methods, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        Log::debug('list_field_methods: built', [
            'type_scope' => $scopeType,
            'count' => count($methods),
        ]);

        return [
            'success' => true,
            'type_scope' => $scopeType,
            'count' => count($methods),
            'methods' => $methods,
        ];
    }

    /**
     * @return 'visibility'|'validation'|'data'|'ui'|null
     */
    private function categorize(string $name): ?string
    {
        foreach (self::CATEGORY_RULES as $rule) {
            $match = match ($rule['mode']) {
                'prefix' => str_starts_with($name, $rule['match']),
                'equals' => $name === $rule['match'],
            };

            if ($match) {
                return $rule['category'];
            }
        }

        return null;
    }

    private function returnTypeIsChainable(ReflectionMethod $method, string $ownerClass): bool
    {
        $type = $method->getReturnType();
        if (! $type instanceof ReflectionNamedType
            && ! $type instanceof ReflectionUnionType
            && ! $type instanceof ReflectionIntersectionType
        ) {
            return false;
        }

        return $this->typeIsChainable($type, $ownerClass);
    }

    private function typeIsChainable(
        ReflectionNamedType|ReflectionUnionType|ReflectionIntersectionType $type,
        string $ownerClass,
    ): bool {
        if ($type instanceof ReflectionNamedType) {
            $name = $type->getName();

            return in_array($name, ['self', 'static', 'this', $ownerClass], true)
                || is_subclass_of($ownerClass, $name);
        }

        if ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $inner) {
                if ($inner instanceof ReflectionNamedType && $this->typeIsChainable($inner, $ownerClass)) {
                    return true;
                }
            }
        }

        return false;
    }
}
