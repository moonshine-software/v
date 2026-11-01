<?php

declare(strict_types=1);

namespace App\ApiAdmin\MCP\Tools;

use App\ApiAdmin\MCP\Generator\ResourceGenerator;
use Illuminate\Support\Facades\Log;
use ReflectionClass;
use ReflectionException;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionUnionType;
use stdClass;

/**
 * MCP Tool: describe_field_type
 *
 * Reports every chainable method available on a given field `type`
 * (plus curated `schema_hints` for the most common spec-level tweaks).
 * Agents call this after `list_fields` to pick a valid `methods[...]`
 * chain for a field they are about to declare.
 *
 * "Chainable" means the method returns `self`, `static`, or `$this` —
 * that is what makes `Field::make(...)->sortable()->required()` work.
 * Methods returning anything else (e.g. a built form snapshot) are
 * dropped; they are not safe to emit as part of a generator chain.
 */
final class DescribeFieldTypeTool implements ToolContract
{
    /**
     * Curated spec-level tweak hints, keyed by field type. Not exhaustive
     * — `available_methods` is the source of truth; this is just the
     * “common knobs” shortlist so an agent does not have to grep a
     * 100-method reflection dump to find `options` or `searchable`.
     *
     * @var array<string, array<string, string>>
     */
    private const array SCHEMA_HINTS_BY_TYPE = [
        'password' => [
            'raw' => 'Auto-applied by the generator. Sends the RAW value to upstream (no Hash::make). Do not add to methods manually.',
        ],
        'select' => [
            'options' => 'Static key-value map. Pass via fields[].options = {value: label, ...}.',
            'searchable' => 'Enable live-search inside the dropdown.',
            'multiple' => 'Allow multiple selection (emits an array on submit).',
        ],
        'image' => [
            'dir' => 'Upload target directory (default: images).',
            'disk' => 'Filesystem disk (default: public).',
        ],
        'file' => [
            'dir' => 'Upload target directory (default: files).',
            'disk' => 'Filesystem disk (default: public).',
        ],
        'belongs_to' => [
            'endpoint' => 'Remote list endpoint for the options dropdown.',
            'valueKey' => 'Payload key used as the FK id (default: id).',
            'labelKey' => 'Payload key used as the visible label (default: name).',
        ],
        'has_many' => [
            'endpoint' => 'Remote child-list endpoint — must include {parent_id}.',
            'resource' => 'Generated relation resource class (auto-wired when a nested spec is provided).',
            'creatable' => 'Render the inline add-row button.',
            'editable' => 'Render the inline edit button.',
            'removable' => 'Render the inline delete button (MoonShine removable() convention).',
        ],
        'has_one' => [
            'endpoint' => 'Remote child endpoint — must include {parent_id}.',
            'resource' => 'Generated relation resource class.',
        ],
        'belongs_to_many' => [
            'attachEndpoint' => 'Sync endpoint (default: <resource>/{parent_id}/<column>).',
            'valueKey' => 'Pivot value key (default: id).',
            'labelKey' => 'Pivot label key (default: name).',
        ],
    ];

    /**
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return [
            'name' => 'describe_field_type',
            'description' => 'Describe the UI class a field type maps to: parent class, every chainable method (with signature + accepts-condition flag), and curated schema hints. Call after list_fields to pick valid method names for a field chain.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'type' => [
                        'type' => 'string',
                        'description' => 'Field type string (built-in or custom) — see list_fields for valid values.',
                    ],
                ],
                'required' => ['type'],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{success: true, type: string, class: class-string, alias: string, group: 'scalar'|'relation'|'media', is_custom: bool, parent_class: string|null, available_methods: list<array{name: string, signature: string, accepts_condition: bool}>, schema_hints: array<string, string>|stdClass}|array{success: false, error: string, code: string, hint?: string}
     */
    public function execute(array $params): array
    {
        $type = isset($params['type']) && is_string($params['type']) ? $params['type'] : '';
        if ($type === '') {
            return [
                'success' => false,
                'error' => 'Missing required parameter: type',
                'code' => 'VALIDATION',
            ];
        }

        $catalog = ResourceGenerator::fieldTypeCatalog();
        if (! isset($catalog[$type])) {
            Log::warning('describe_field_type: unknown type', ['type' => $type]);

            return [
                'success' => false,
                'error' => "Unknown field type: {$type}",
                'code' => 'FIELD_TYPE_UNKNOWN',
                'hint' => 'Call list_fields to see every accepted type (built-in + custom).',
            ];
        }

        $entry = $catalog[$type];
        $class = $entry['class'];

        // The catalog only publishes class-strings that autoloaded at
        // registration time (see fieldTypeCatalog), so ReflectionClass
        // cannot throw here — class_exists keeps PHPStan honest about
        // the class-string narrowing without a dead try/catch.
        if (! class_exists($class)) {
            Log::error('describe_field_type: catalog references missing class', [
                'type' => $type,
                'class' => $class,
            ]);

            return [
                'success' => false,
                'error' => "Cannot reflect field class: {$class}",
                'code' => 'REFLECTION_FAILED',
            ];
        }

        $reflection = new ReflectionClass($class);

        $methods = $this->collectChainableMethods($reflection, $class);

        $parentClass = $reflection->getParentClass();
        $parentName = $parentClass === false ? null : $parentClass->getName();

        Log::debug('describe_field_type: built', [
            'type' => $type,
            'class' => $class,
            'methods' => count($methods),
        ]);

        return [
            'success' => true,
            'type' => $type,
            'class' => $class,
            'alias' => $entry['alias'],
            'group' => $entry['group'],
            'is_custom' => $entry['is_custom'],
            'parent_class' => $parentName,
            'available_methods' => $methods,
            'schema_hints' => self::SCHEMA_HINTS_BY_TYPE[$type] ?? new stdClass,
        ];
    }

    /**
     * @param ReflectionClass<object> $reflection
     * @param class-string $fieldClass
     *
     * @return list<array{name: string, signature: string, accepts_condition: bool}>
     */
    private function collectChainableMethods(ReflectionClass $reflection, string $fieldClass): array
    {
        $results = [];
        $seen = [];

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
            // Magic methods (__get / __call / __toString / ...) are not
            // chainable API surface — filter on the well-known `__` prefix.
            if (str_starts_with($name, '__')) {
                continue;
            }

            if (isset($seen[$name])) {
                continue;
            }

            if (! $this->returnTypeIsChainable($method, $reflection->getName())) {
                continue;
            }

            $seen[$name] = true;
            $results[] = [
                'name' => $name,
                'signature' => $this->renderSignature($method),
                'accepts_condition' => ResourceGenerator::methodAcceptsConditionArg($fieldClass, $name),
            ];
        }

        usort($results, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $results;
    }

    private function returnTypeIsChainable(ReflectionMethod $method, string $ownerClass): bool
    {
        $returnType = $method->getReturnType();
        if (! $returnType instanceof ReflectionNamedType
            && ! $returnType instanceof ReflectionUnionType
            && ! $returnType instanceof ReflectionIntersectionType
        ) {
            return false;
        }

        return $this->typeIsChainable($returnType, $ownerClass);
    }

    private function typeIsChainable(
        ReflectionNamedType|ReflectionUnionType|ReflectionIntersectionType $type,
        string $ownerClass,
    ): bool {
        if ($type instanceof ReflectionNamedType) {
            $name = $type->getName();

            // self / static / $this are the canonical MoonShine chain-return
            // marker. Also accept the owning class's FQCN (some libs spell
            // it out) as well as common parents that still preserve
            // chainability (Conditionable returns $this typed as self).
            return in_array($name, ['self', 'static', 'this', $ownerClass], true)
                || is_subclass_of($ownerClass, $name);
        }

        if ($type instanceof ReflectionUnionType) {
            return array_any($type->getTypes(), fn (ReflectionNamedType|ReflectionIntersectionType $inner): bool => $inner instanceof ReflectionNamedType && $this->typeIsChainable($inner, $ownerClass));
        }

        // Intersection types are rare here; treat as non-chainable.
        return false;
    }

    private function renderSignature(ReflectionMethod $method): string
    {
        $params = array_map(
            $this->renderParameter(...),
            $method->getParameters(),
        );

        $return = $method->getReturnType();
        $returnText = '';
        if ($return instanceof ReflectionNamedType
            || $return instanceof ReflectionUnionType
            || $return instanceof ReflectionIntersectionType
        ) {
            $returnText = ': '.$this->renderType($return);
        }

        return $method->getName().'('.implode(', ', $params).')'.$returnText;
    }

    private function renderParameter(ReflectionParameter $param): string
    {
        $type = $param->getType();
        $typeText = '';
        if ($type instanceof ReflectionNamedType
            || $type instanceof ReflectionUnionType
            || $type instanceof ReflectionIntersectionType
        ) {
            $typeText = $this->renderType($type).' ';
        }

        $rest = ($param->isVariadic() ? '...' : '').'$'.$param->getName();
        if ($param->isDefaultValueAvailable()) {
            try {
                $default = $param->getDefaultValue();
                $rest .= ' = '.$this->renderDefault($default);
            } catch (ReflectionException) {
                $rest .= ' = ?';
            }
        }

        return $typeText.$rest;
    }

    private function renderType(
        ReflectionNamedType|ReflectionUnionType|ReflectionIntersectionType $type,
    ): string {
        if ($type instanceof ReflectionNamedType) {
            return ($type->allowsNull() && $type->getName() !== 'mixed' ? '?' : '').$type->getName();
        }

        if ($type instanceof ReflectionUnionType) {
            $parts = [];
            foreach ($type->getTypes() as $inner) {
                if ($inner instanceof ReflectionNamedType) {
                    $parts[] = $inner->getName();
                }
            }

            return implode('|', $parts);
        }

        $parts = [];
        foreach ($type->getTypes() as $inner) {
            if ($inner instanceof ReflectionNamedType) {
                $parts[] = $inner->getName();
            }
        }

        return implode('&', $parts);
    }

    private function renderDefault(mixed $value): string
    {
        if (is_array($value)) {
            return $value === [] ? '[]' : '[...]';
        }
        if (is_string($value)) {
            return "'".addslashes($value)."'";
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value === null) {
            return 'null';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return '?';
    }
}
