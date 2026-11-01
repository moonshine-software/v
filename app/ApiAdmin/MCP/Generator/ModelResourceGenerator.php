<?php

declare(strict_types=1);

namespace App\ApiAdmin\MCP\Generator;

use App\ApiAdmin\MCP\AtomicFileWriter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use MoonShine\UI\Fields\Checkbox;
use MoonShine\UI\Fields\Color;
use MoonShine\UI\Fields\Date;
use MoonShine\UI\Fields\Email;
use MoonShine\UI\Fields\File as MoonShineFile;
use MoonShine\UI\Fields\ID;
use MoonShine\UI\Fields\Image;
use MoonShine\UI\Fields\Json;
use MoonShine\UI\Fields\Number;
use MoonShine\UI\Fields\Password;
use MoonShine\UI\Fields\Select;
use MoonShine\UI\Fields\Switcher;
use MoonShine\UI\Fields\Text;
use MoonShine\UI\Fields\Textarea;
use MoonShine\UI\Fields\Url;
use NoDiscard;
use RuntimeException;

/**
 * Code generator for local Eloquent-backed MoonShine resources.
 *
 * This is intentionally separate from the REST ResourceGenerator: local
 * resources are native ModelResource/MultiPanelResource classes and must not
 * carry endpoint, response mapping, or upstream service configuration.
 */
final class ModelResourceGenerator
{
    /**
     * @var array<string, array{class: class-string, alias: string}>
     */
    private const array FIELD_TYPE_MAP = [
        'id' => ['class' => ID::class, 'alias' => 'ID'],
        'text' => ['class' => Text::class, 'alias' => 'Text'],
        'textarea' => ['class' => Textarea::class, 'alias' => 'Textarea'],
        'number' => ['class' => Number::class, 'alias' => 'Number'],
        'email' => ['class' => Email::class, 'alias' => 'Email'],
        'password' => ['class' => Password::class, 'alias' => 'Password'],
        'date' => ['class' => Date::class, 'alias' => 'Date'],
        'datetime' => ['class' => Date::class, 'alias' => 'Date'],
        'checkbox' => ['class' => Checkbox::class, 'alias' => 'Checkbox'],
        'switcher' => ['class' => Switcher::class, 'alias' => 'Switcher'],
        'select' => ['class' => Select::class, 'alias' => 'Select'],
        'image' => ['class' => Image::class, 'alias' => 'Image'],
        'file' => ['class' => MoonShineFile::class, 'alias' => 'File'],
        'json' => ['class' => Json::class, 'alias' => 'Json'],
        'color' => ['class' => Color::class, 'alias' => 'Color'],
        'slug' => ['class' => Text::class, 'alias' => 'Text'],
        'url' => ['class' => Url::class, 'alias' => 'Url'],
    ];

    /**
     * @param array<string, mixed> $params
     *
     * @return array{
     *     file: string,
     *     class: string,
     *     className: string,
     *     pages: array{index: string, form: string, detail?: string},
     * }
     */
    #[NoDiscard]
    public function generate(array $params): array
    {
        $resourceName = $this->stringParam($params['name'] ?? null, 'resource');
        $modelClass = $this->modelClass($params['model'] ?? null);
        $panel = $this->stringParam($params['panel'] ?? null, $this->stringParam($params['service'] ?? null, 'admin'));
        $title = $this->stringParam($params['title'] ?? null, Str::title(str_replace(['-', '_'], ' ', $resourceName)));
        $group = $this->optionalString($params['group'] ?? null);
        $icon = $this->optionalString($params['icon'] ?? null);
        $includeDetail = ($params['detail'] ?? true) !== false;
        /** @var list<array<string, mixed>> $fields */
        $fields = is_array($params['fields'] ?? null) ? array_values(array_filter($params['fields'], is_array(...))) : [];
        /** @var list<string> $searchFields */
        $searchFields = is_array($params['search'] ?? null) ? array_values(array_filter($params['search'], is_string(...))) : [];

        $resourceBaseName = Str::studly($resourceName);
        $className = $resourceBaseName.'Resource';
        $indexPageClass = $resourceBaseName.'IndexPage';
        $formPageClass = $resourceBaseName.'FormPage';
        $detailPageClass = $resourceBaseName.'DetailPage';
        $basePath = $this->localResourcesPath();
        $namespace = $this->baseNamespace().'\\'.$resourceBaseName;
        $pagesNamespace = $namespace.'\\Pages';
        $resourceDir = $basePath.'/'.$resourceBaseName;
        $pagesDir = $resourceDir.'/Pages';
        $filePath = $resourceDir.'/'.$className.'.php';

        Log::debug('ModelResourceGenerator: generating', [
            'resource' => $resourceName,
            'model' => $modelClass,
            'panel' => $panel,
            'fields_count' => count($fields),
        ]);

        AtomicFileWriter::ensureDirectoryExists($pagesDir);

        [$indexFields, $indexUses] = $this->buildFields($this->resolvePageFields($params, $fields, 'index'), 'index');
        [$formFields, $formUses] = $this->buildFields($this->resolvePageFields($params, $fields, 'form'), 'form');
        [$detailFields, $detailUses] = $this->buildFields($this->resolvePageFields($params, $fields, 'detail'), 'detail');

        $resourceContent = $this->renderResource(
            namespace: $namespace,
            modelClass: $modelClass,
            className: $className,
            indexPageClass: $indexPageClass,
            formPageClass: $formPageClass,
            detailPageClass: $detailPageClass,
            includeDetail: $includeDetail,
            panel: $panel,
            title: $title,
            column: $this->displayColumn($params, $fields),
            searchFields: $searchFields,
            group: $group,
            icon: $icon,
            actions: $params['actions'] ?? null,
        );

        // Optional stub sections render empty; drop the gaps they leave.
        $resourceContent = (string) preg_replace("/\n\s*\n(\s*\n)*}\n?$/", "\n}\n", $resourceContent);
        AtomicFileWriter::put($filePath, $resourceContent);

        $indexPath = $pagesDir.'/'.$indexPageClass.'.php';
        AtomicFileWriter::put($indexPath, $this->renderPage(
            stub: 'model-index-page.stub',
            namespace: $pagesNamespace,
            resourceClass: $namespace.'\\'.$className,
            modelClass: $modelClass,
            resourceShort: $className,
            modelShort: $this->classBaseName($modelClass),
            className: $indexPageClass,
            fields: $indexFields,
            fieldUses: $indexUses,
        ));

        $formPath = $pagesDir.'/'.$formPageClass.'.php';
        AtomicFileWriter::put($formPath, $this->renderPage(
            stub: 'model-form-page.stub',
            namespace: $pagesNamespace,
            resourceClass: $namespace.'\\'.$className,
            modelClass: $modelClass,
            resourceShort: $className,
            modelShort: $this->classBaseName($modelClass),
            className: $formPageClass,
            fields: $this->indentLines($formFields, 16),
            fieldUses: $formUses,
            rulesMethod: $this->buildRulesMethod($params['rules'] ?? null),
        ));

        $pages = [
            'index' => $indexPath,
            'form' => $formPath,
        ];

        if ($includeDetail) {
            $detailPath = $pagesDir.'/'.$detailPageClass.'.php';
            AtomicFileWriter::put($detailPath, $this->renderPage(
                stub: 'model-detail-page.stub',
                namespace: $pagesNamespace,
                resourceClass: $namespace.'\\'.$className,
                modelClass: $modelClass,
                resourceShort: $className,
                modelShort: $this->classBaseName($modelClass),
                className: $detailPageClass,
                fields: $detailFields,
                fieldUses: $detailUses,
            ));
            $pages['detail'] = $detailPath;
        }

        Log::info('ModelResourceGenerator: generated', [
            'file' => $filePath,
            'class' => $namespace.'\\'.$className,
            'pages' => array_values($pages),
        ]);

        return [
            'file' => $filePath,
            'class' => $namespace.'\\'.$className,
            'className' => $className,
            'pages' => $pages,
        ];
    }

    private function stringParam(mixed $value, string $default): string
    {
        if (! is_string($value)) {
            return $default;
        }

        $value = trim($value);

        return $value !== '' ? $value : $default;
    }

    private function optionalString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @return class-string<Model>
     */
    private function modelClass(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException('Missing model class for local MoonShine resource.');
        }

        $class = ltrim(trim($value), '\\');
        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            Log::warning('ModelResourceGenerator: missing or invalid model class', [
                'model' => $class,
            ]);

            throw new RuntimeException("Model class '{$class}' does not exist or is not an Eloquent model.");
        }

        /** @var class-string<Model> $class */
        return $class;
    }

    private function localResourcesPath(): string
    {
        $path = config('api-admin.local_resources_path', app_path('MoonShine/Resources'));

        return is_string($path) && $path !== '' ? $path : app_path('MoonShine/Resources');
    }

    private function baseNamespace(): string
    {
        $namespace = config('api-admin.local_resources_namespace', 'App\\MoonShine\\Resources');

        return is_string($namespace) && $namespace !== '' ? trim($namespace, '\\') : 'App\\MoonShine\\Resources';
    }

    /**
     * @param list<array<string, mixed>> $fields
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private function buildFields(array $fields, string $page): array
    {
        $lines = [];
        $uses = [];

        foreach ($fields as $field) {
            $type = is_string($field['type'] ?? null) ? $field['type'] : 'text';
            $column = is_string($field['column'] ?? null) ? $field['column'] : '';
            if ($column === '') {
                continue;
            }

            $label = $this->stringParam($field['label'] ?? null, Str::title(str_replace('_', ' ', $column)));
            $mapping = self::FIELD_TYPE_MAP[$type] ?? self::FIELD_TYPE_MAP['text'];
            $uses[$mapping['class']] = $mapping['alias'];

            $line = "            {$mapping['alias']}::make(".$this->phpString($label).', '.$this->phpString($column).')';

            if ($type === 'datetime') {
                $line .= "->format('Y-m-d H:i:s')";
            }

            if ($type === 'select' && isset($field['options']) && is_array($field['options'])) {
                $line .= '->options('.$this->phpExportShort($field['options']).')';
            }

            foreach ($this->fieldMethods($field['methods'] ?? null, $page) as $method) {
                $line .= '->'.$method.'()';
            }

            $lines[] = $line.',';
        }

        return [implode("\n", $lines), $uses];
    }

    /**
     * @return list<string>
     */
    private function fieldMethods(mixed $methods, string $page): array
    {
        if (! is_array($methods)) {
            return [];
        }

        $allowed = [
            'badge' => true,
            'copy' => true,
            'disabled' => true,
            'eye' => true,
            'locked' => true,
            'nullable' => true,
            'readonly' => true,
            'reactive' => true,
            'required' => true,
            'sortable' => true,
        ];

        $raw = array_is_list($methods)
            ? $methods
            : array_merge(
                is_array($methods[$page] ?? null) ? $methods[$page] : [],
                is_array($methods['shared'] ?? null) ? $methods['shared'] : [],
            );

        $out = [];
        foreach ($raw as $method) {
            if (! is_string($method)) {
                continue;
            }
            if (! isset($allowed[$method])) {
                continue;
            }
            $out[$method] = $method;
        }

        return array_values($out);
    }

    /**
     * @param array<string, mixed> $params
     * @param list<array<string, mixed>> $fields
     *
     * @return list<array<string, mixed>>
     */
    private function resolvePageFields(array $params, array $fields, string $page): array
    {
        $key = $page.'_fields';
        $override = $params[$key] ?? null;

        if (is_array($override) && $override !== []) {
            $normalized = [];
            foreach ($override as $entry) {
                if (is_array($entry)) {
                    /** @var array<string, mixed> $typed */
                    $typed = $entry;
                    $normalized[] = $typed;
                }
            }

            return $normalized;
        }

        return $this->filterFieldsForPage($fields, $page);
    }

    /**
     * @param list<array<string, mixed>> $fields
     *
     * @return list<array<string, mixed>>
     */
    private function filterFieldsForPage(array $fields, string $page): array
    {
        return array_filter($fields, static function (array $field) use ($page): bool {
            $hideOn = $field['hide_on'] ?? null;
            if (is_string($hideOn)) {
                $hideOn = [$hideOn];
            }

            return ! is_array($hideOn) || ! in_array($page, $hideOn, true);
        })
            |> array_values(...);
    }

    /**
     * @param array<string, mixed> $params
     * @param list<array<string, mixed>> $fields
     */
    private function displayColumn(array $params, array $fields): string
    {
        $column = $this->optionalString($params['column'] ?? null);
        if ($column !== null) {
            return $column;
        }

        foreach ($fields as $field) {
            $candidate = $field['column'] ?? null;
            if (is_string($candidate) && ! in_array($candidate, ['id', 'created_at', 'updated_at'], true)) {
                return $candidate;
            }
        }

        return 'id';
    }

    /**
     * @param list<string> $searchFields
     */
    private function buildSearchMethod(array $searchFields): string
    {
        if ($searchFields === []) {
            return '';
        }

        $fields = implode(",\n            ", array_map(
            $this->phpString(...),
            $searchFields,
        ));

        return <<<PHP

    /**
     * @return list<string>
     */
    #[Override]
    protected function search(): array
    {
        return [
            {$fields},
        ];
    }
PHP;
    }

    private function buildActiveActionsMethod(mixed $actions): string
    {
        if (! is_array($actions) || $actions === []) {
            return '';
        }

        $allowedMap = [
            'create' => 'CREATE',
            'view' => 'VIEW',
            'update' => 'UPDATE',
            'delete' => 'DELETE',
            'mass_delete' => 'MASS_DELETE',
        ];

        $cases = [];
        foreach ($actions as $raw) {
            if (! is_string($raw)) {
                continue;
            }

            $key = strtolower($raw);
            if (isset($allowedMap[$key])) {
                $cases[$key] = 'Action::'.$allowedMap[$key];
            }
        }

        if ($cases === []) {
            return '';
        }

        $casesBlock = '            '.implode(",\n            ", $cases).',';

        return <<<PHP

    /**
     * @return ListOf<Action>
     */
    #[Override]
    protected function activeActions(): ListOf
    {
        return new ListOf(Action::class, [
{$casesBlock}
        ]);
    }
PHP;
    }

    private function buildRulesMethod(mixed $rules): string
    {
        if (! is_array($rules) || $rules === []) {
            return '';
        }

        $clean = [];
        foreach ($rules as $column => $rule) {
            if (! is_string($column)) {
                continue;
            }
            if (preg_match('/^[a-zA-Z_]\w*$/', $column) !== 1) {
                continue;
            }
            if (is_string($rule) && $rule !== '') {
                $clean[$column] = $rule;
            } elseif (is_array($rule) && array_is_list($rule)) {
                $parts = array_filter($rule, is_string(...))
                    |> array_values(...);
                if ($parts !== []) {
                    $clean[$column] = $parts;
                }
            }
        }

        if ($clean === []) {
            return '';
        }

        $body = $this->formatRulesArrayBody($clean, 12);

        return <<<PHP

    /**
     * @return array<string, string|list<string>>
     */
    #[Override]
    protected function rules(DataWrapperContract \$item): array
    {
        return [
{$body}
        ];
    }
PHP;
    }

    /**
     * @param array<string, string|list<string>> $rules
     */
    private function formatRulesArrayBody(array $rules, int $indent): string
    {
        $pad = str_repeat(' ', $indent);
        $lines = [];

        foreach ($rules as $column => $rule) {
            if (is_string($rule)) {
                $lines[] = $pad.$this->phpString($column).' => '.$this->phpString($rule).',';
                continue;
            }

            $lines[] = $pad.$this->phpString($column).' => '.$this->phpExportShort($rule).',';
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<string> $searchFields
     */
    private function renderResource(
        string $namespace,
        string $modelClass,
        string $className,
        string $indexPageClass,
        string $formPageClass,
        string $detailPageClass,
        bool $includeDetail,
        string $panel,
        string $title,
        string $column,
        array $searchFields,
        ?string $group,
        ?string $icon,
        mixed $actions,
    ): string {
        $attributes = [];
        $attributeUses = [];

        if ($icon !== null) {
            $attributeUses[] = 'use MoonShine\\Support\\Attributes\\Icon;';
            $attributes[] = '#[Icon('.$this->phpString($icon).')]';
        }

        if ($group !== null) {
            $attributeUses[] = 'use MoonShine\\MenuManager\\Attributes\\Group;';
            $attributes[] = '#[Group('.$this->phpString($group).')]';
        }

        $pages = [
            $indexPageClass.'::class',
            $formPageClass.'::class',
        ];
        if ($includeDetail) {
            $pages[] = $detailPageClass.'::class';
        }

        $pagesBlock = '            '.implode(",\n            ", $pages).',';
        $actionsMethod = $this->buildActiveActionsMethod($actions);

        return str_replace([
            '{{ namespace }}',
            '{{ modelClass }}',
            '{{ modelShort }}',
            '{{ indexPageClass }}',
            '{{ formPageClass }}',
            '{{ detailPageUse }}',
            '{{ detailPageGeneric }}',
            '{{ attributeUses }}',
            '{{ actionUses }}',
            '{{ overrideUse }}',
            '{{ attributes }}',
            '{{ className }}',
            '{{ modelProperty }}',
            '{{ panelProperty }}',
            '{{ titleProperty }}',
            '{{ columnProperty }}',
            '{{ pagesMethod }}',
            '{{ searchMethod }}',
            '{{ actionsMethod }}',
        ], [
            $namespace,
            $modelClass,
            $this->classBaseName($modelClass),
            $indexPageClass,
            $formPageClass,
            $includeDetail ? "use {$namespace}\\Pages\\{$detailPageClass};\n" : '',
            $includeDetail ? $detailPageClass : 'null',
            $attributeUses === [] ? '' : implode("\n", $attributeUses)."\n",
            $actionsMethod === '' ? '' : "use MoonShine\\Support\\Enums\\Action;\nuse MoonShine\\Support\\ListOf;\n",
            "use Override;\n",
            $attributes === [] ? '' : implode("\n", $attributes)."\n",
            $className,
            "    #[Override]\n    protected string \$model = {$this->classBaseName($modelClass)}::class;\n",
            "    #[Override]\n    protected string \$panel = ".$this->phpString($panel).";\n",
            '    protected string $title = '.$this->phpString($title).";\n",
            "    #[Override]\n    protected string \$column = ".$this->phpString($column).";\n",
            <<<PHP
    /**
     * @return list<class-string>
     */
    #[Override]
    protected function pages(): array
    {
        return [
{$pagesBlock}
        ];
    }
PHP,
            $this->buildSearchMethod($searchFields),
            $actionsMethod,
        ], $this->stub('model-resource.stub'));
    }

    /**
     * @param array<string, string> $fieldUses
     */
    private function renderPage(
        string $stub,
        string $namespace,
        string $resourceClass,
        string $modelClass,
        string $resourceShort,
        string $modelShort,
        string $className,
        string $fields,
        array $fieldUses,
        string $rulesMethod = '',
    ): string {
        return str_replace([
            '{{ namespace }}',
            '{{ modelClass }}',
            '{{ modelShort }}',
            '{{ resourceClass }}',
            '{{ resourceShort }}',
            '{{ className }}',
            '{{ fields }}',
            '{{ fieldUses }}',
            '{{ rulesMethod }}',
        ], [
            $namespace,
            $modelClass,
            $modelShort,
            $resourceClass,
            $resourceShort,
            $className,
            $fields,
            $this->formatUseStatements($fieldUses),
            $rulesMethod,
        ], $this->stub($stub));
    }

    /**
     * @param array<string, string> $uses
     */
    private function formatUseStatements(array $uses): string
    {
        ksort($uses);

        return implode('', array_map(
            static fn (string $class): string => "use {$class};\n",
            array_keys($uses),
        ));
    }

    private function stub(string $name): string
    {
        $path = dirname(__DIR__, 4).'/stubs/api-admin/'.$name;
        if (! File::isFile($path)) {
            Log::error('ModelResourceGenerator: missing stub', [
                'stub' => $name,
                'path' => $path,
            ]);

            throw new RuntimeException("Missing model resource stub '{$name}'.");
        }

        return File::get($path);
    }

    private function classBaseName(string $fqcn): string
    {
        $position = strrpos($fqcn, '\\');

        return $position === false ? $fqcn : substr($fqcn, $position + 1);
    }

    private function phpString(string $value): string
    {
        return var_export($value, true);
    }

    /**
     * @param array<int|string, mixed> $value
     */
    private function phpExportShort(array $value): string
    {
        return str_replace("\n", '', var_export($value, true));
    }

    private function indentLines(string $text, int $spaces): string
    {
        if ($text === '') {
            return $text;
        }

        $pad = str_repeat(' ', $spaces);

        return preg_replace('/^(?=.)/m', $pad, $text) ?? $text;
    }
}
