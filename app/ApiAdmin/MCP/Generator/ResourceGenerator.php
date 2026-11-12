<?php

declare(strict_types=1);

namespace App\ApiAdmin\MCP\Generator;

use App\ApiAdmin\Fields\RestBelongsTo;
use App\ApiAdmin\Fields\RestBelongsToMany;
use App\ApiAdmin\Fields\RestHasMany;
use App\ApiAdmin\Fields\RestHasOne;
use App\ApiAdmin\MCP\AtomicFileWriter;
use App\ApiAdmin\MCP\ServiceRegistry;
use App\ApiAdmin\MCP\SpecStore\SpecStore;
use App\ApiAdmin\ServiceConfig\ResponseMapping;
use App\ApiAdmin\Validation\HostedValidationRules;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use MoonShine\UI\Components\Collapse;
use MoonShine\UI\Components\Layout\Column;
use MoonShine\UI\Components\Layout\Grid;
use MoonShine\UI\Components\Tabs;
use MoonShine\UI\Components\Tabs\Tab;
use MoonShine\UI\Fields\Checkbox;
use MoonShine\UI\Fields\Color;
use MoonShine\UI\Fields\Date;
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
use ReflectionException;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionUnionType;
use RuntimeException;

/**
 * Code generator for REST resources.
 *
 * Generates PHP resource files from field definitions provided by the MCP create_resource tool.
 * Follows a service-partitioned, directory-per-resource layout:
 *   Resources/
 *     Blog/                         (service)
 *       Post/                       (resource)
 *         PostResource.php
 *         Pages/
 *           PostIndexPage.php
 *           PostFormPage.php
 *           PostDetailPage.php
 *       Comment/
 *         ...
 *     Shop/
 *       Product/
 *         ...
 *
 * The service directory prevents class-name collisions when multiple services
 * expose an entity with the same name (e.g. Blog\Post and Cms\Post).
 */
final class ResourceGenerator
{
    /**
     * Built-in field type map: type key → {class, alias, group, description}.
     *
     * Internal source of truth for the generator. External callers (and
     * introspection MCP tools) should read the enriched view via
     * {@see self::fieldTypeCatalog()}, which layers user-declared
     * `api-admin.custom_fields` on top and stamps every entry with
     * `is_custom: bool`. Keeping this const private prevents consumers
     * from accidentally bypassing the custom-field merge.
     *
     * @var array<string, array{class: class-string, alias: string, group: 'scalar'|'relation'|'media', description: string}>
     */
    private const array FIELD_TYPE_MAP = [
        'id' => ['class' => ID::class, 'alias' => 'ID', 'group' => 'scalar', 'description' => 'Primary identifier (read-only in forms).'],
        'text' => ['class' => Text::class, 'alias' => 'Text', 'group' => 'scalar', 'description' => 'Single-line text input.'],
        'textarea' => ['class' => Textarea::class, 'alias' => 'Textarea', 'group' => 'scalar', 'description' => 'Multi-line text input.'],
        'number' => ['class' => Number::class, 'alias' => 'Number', 'group' => 'scalar', 'description' => 'Numeric input.'],
        'email' => ['class' => Text::class, 'alias' => 'Text', 'group' => 'scalar', 'description' => 'Email-formatted text input (aliased to Text).'],
        'password' => ['class' => Password::class, 'alias' => 'Password', 'group' => 'scalar', 'description' => 'Masked input; generator auto-appends ->raw() so upstream hashes (no double-hash).'],
        'date' => ['class' => Date::class, 'alias' => 'Date', 'group' => 'scalar', 'description' => 'Date picker.'],
        'datetime' => ['class' => Date::class, 'alias' => 'Date', 'group' => 'scalar', 'description' => 'Date + time picker (Date field in datetime mode).'],
        'checkbox' => ['class' => Checkbox::class, 'alias' => 'Checkbox', 'group' => 'scalar', 'description' => 'Boolean checkbox.'],
        'switcher' => ['class' => Switcher::class, 'alias' => 'Switcher', 'group' => 'scalar', 'description' => 'Boolean toggle switch.'],
        'select' => ['class' => Select::class, 'alias' => 'Select', 'group' => 'scalar', 'description' => 'Dropdown with static `options` or remote endpoint.'],
        'image' => ['class' => Image::class, 'alias' => 'Image', 'group' => 'media', 'description' => 'Image upload with thumbnail preview.'],
        'file' => ['class' => \MoonShine\UI\Fields\File::class, 'alias' => 'File', 'group' => 'media', 'description' => 'Generic file upload.'],
        'json' => ['class' => Json::class, 'alias' => 'Json', 'group' => 'scalar', 'description' => 'Structured JSON editor (array of key/value rows).'],
        'color' => ['class' => Color::class, 'alias' => 'Color', 'group' => 'scalar', 'description' => 'Color picker.'],
        // MoonShine has no dedicated Slug field; alias to Text for UI parity.
        'slug' => ['class' => Text::class, 'alias' => 'Text', 'group' => 'scalar', 'description' => 'URL-safe slug (aliased to Text, no auto-transform).'],
        'url' => ['class' => Url::class, 'alias' => 'Url', 'group' => 'scalar', 'description' => 'URL input with href preview.'],
        'belongs_to' => ['class' => RestBelongsTo::class, 'alias' => 'RestBelongsTo', 'group' => 'relation', 'description' => 'Parent REST relation (single FK lookup).'],
        'has_many' => ['class' => RestHasMany::class, 'alias' => 'RestHasMany', 'group' => 'relation', 'description' => 'Child REST relation (many inline rows).'],
        'has_one' => ['class' => RestHasOne::class, 'alias' => 'RestHasOne', 'group' => 'relation', 'description' => 'Child REST relation (single inline form).'],
        'belongs_to_many' => ['class' => RestBelongsToMany::class, 'alias' => 'RestBelongsToMany', 'group' => 'relation', 'description' => 'Pivot REST relation (many-to-many via attach/sync).'],
    ];

    /**
     * Public catalog of usable `type` strings for the MCP `create_resource`
     * schema + introspection tools. Merges built-in types with the caller's
     * `api-admin.custom_fields` map (user-generated field classes) and
     * stamps every entry with `is_custom: bool` so agents can tell them
     * apart without a separate probe.
     *
     * The shape mirrors {@see self::FIELD_TYPE_MAP} so the internal
     * generator code can continue to read `$catalog[$type]['class']`
     * and `['alias']` identically; the extra keys are passthrough-safe.
     *
     * Custom entries default to `group: 'scalar'` and a generic
     * description — they can override via a full-shape array value
     * instead of a bare class-string, see the `api-admin.custom_fields`
     * config key for the accepted shapes.
     *
     * @return array<string, array{class: class-string, alias: string, group: 'scalar'|'relation'|'media', description: string, is_custom: bool}>
     */
    #[NoDiscard]
    public static function fieldTypeCatalog(): array
    {
        $catalog = [];
        foreach (self::FIELD_TYPE_MAP as $type => $entry) {
            if (config('api-admin.hosted.mode', 'local') === 'hosted' && in_array($type, ['file', 'image'], true)) {
                continue;
            }
            $catalog[$type] = $entry + ['is_custom' => false];
        }

        if (config('api-admin.hosted.mode', 'local') === 'hosted') {
            return $catalog;
        }

        $customRaw = config('api-admin.custom_fields', []);
        if (! is_array($customRaw)) {
            return $catalog;
        }

        foreach ($customRaw as $type => $value) {
            if (! is_string($type)) {
                continue;
            }
            if ($type === '') {
                continue;
            }
            // Accept two shapes: bare class-string, or full entry array
            // (same keys as FIELD_TYPE_MAP entries). Bare class-string is
            // the ergonomic default; full shape is the escape hatch for
            // non-scalar group assignment or custom description. Entries
            // whose class cannot be autoloaded are dropped — publishing
            // a broken type would surface as a reflection error deep in
            // the generator later, far from the config entry.
            if (is_string($value) && class_exists($value)) {
                $class = $value;
                $alias = self::classBaseName($class);
                $group = 'scalar';
                $description = 'Custom field registered via api-admin.custom_fields.';
            } elseif (is_array($value) && isset($value['class']) && is_string($value['class']) && class_exists($value['class'])) {
                $class = $value['class'];
                $alias = is_string($value['alias'] ?? null) && $value['alias'] !== ''
                    ? $value['alias']
                    : self::classBaseName($class);
                $groupRaw = is_string($value['group'] ?? null) ? $value['group'] : 'scalar';
                $group = in_array($groupRaw, ['scalar', 'relation', 'media'], true) ? $groupRaw : 'scalar';
                $description = is_string($value['description'] ?? null) && $value['description'] !== ''
                    ? $value['description']
                    : 'Custom field registered via api-admin.custom_fields.';
            } else {
                continue;
            }

            $catalog[$type] = [
                'class' => $class,
                'alias' => $alias,
                'group' => $group,
                'description' => $description,
                'is_custom' => true,
            ];
        }

        return $catalog;
    }

    private static function classBaseName(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');

        return $pos === false ? $fqcn : substr($fqcn, $pos + 1);
    }

    /** @var list<string> */
    private const array HOSTED_FIELD_METHODS = [
        'required', 'disabled', 'readonly', 'nullable', 'multiple', 'vertical',
        'searchable', 'tabMode', 'sortable', 'reactive', 'placeholder', 'default',
        'min', 'max', 'step', 'minLength', 'maxLength', 'pattern', 'mask',
        'format', 'inputFormat', 'onValue', 'offValue', 'options', 'raw',
        'creatable', 'editable', 'removable', 'fullCrud',
    ];

    /** @return list<string> */
    public static function hostedValidationRules(): array
    {
        return HostedValidationRules::names();
    }

    private function validateHostedFieldType(string $type): void
    {
        if (config('api-admin.hosted.mode', 'local') === 'hosted' && ! isset(self::fieldTypeCatalog()[$type])) {
            throw new InvalidArgumentException('Hosted generation supports built-in scalar and REST relation fields only. Use an external URL field for media.');
        }
    }

    public static function isFieldMethodAllowed(string $name): bool
    {
        return config('api-admin.hosted.mode', 'local') !== 'hosted'
            || in_array($name, self::HOSTED_FIELD_METHODS, true);
    }

    private function validateClassName(string $name): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_-]*$/D', $name) !== 1
            || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', Str::studly($name)) !== 1) {
            throw new InvalidArgumentException('Service and generated resource names must be valid identifiers.');
        }
    }

    private const string BASE_NAMESPACE = 'App\\MoonShine\\ApiAdmin\\Resources';

    /**
     * Generate a REST resource PHP file.
     *
     * @param array<string, mixed> $params Raw JSON-RPC payload; required keys service/name/endpoint/fields validated by the tool layer.
     *
     * @return array{
     *     file: string,
     *     class: string,
     *     className: string,
     *     pages: array<string, string>,
     *     relationResources: array<string, array{file: string, class: string, className: string}>,
     * }
     */
    #[NoDiscard]
    public function generate(array $params): array
    {
        $serviceName = is_string($params['service'] ?? null) ? $params['service'] : '';
        $resourceName = is_string($params['name'] ?? null) ? $params['name'] : '';
        $this->validateClassName($serviceName);
        $this->validateClassName($resourceName);
        $endpoint = is_string($params['endpoint'] ?? null) ? $params['endpoint'] : '';
        /** @var list<array<string, mixed>> $fields */
        $fields = is_array($params['fields'] ?? null) ? array_values($params['fields']) : [];
        $panel = is_string($params['panel'] ?? null) ? $params['panel'] : $serviceName.'-admin';
        $title = is_string($params['title'] ?? null) ? $params['title'] : Str::title(str_replace('_', ' ', $resourceName));
        // Group placement in the panel sidebar. null (default) = flat list
        // under the panel; string = wrap the menu item in a MenuGroup with
        // that label. The prior behavior auto-grouped every resource under
        // a group named after the service, which duplicated the panel name
        // in single-service panels (Blog Admin → «Blog» → resources).
        // Callers who want multi-group layouts pass e.g. group: "Catalog"
        // for products/categories and group: "Billing" for invoices.
        $group = $params['group'] ?? null;
        // MoonShine-native menu icon via #[Icon(...)] attribute on the
        // resource class. Name uses Heroicons outline set (e.g. "users",
        // "clipboard-document-list") — MoonShine's MenuItem pulls the
        // icon from the attribute automatically when rendering the
        // sidebar. Omitting it keeps the vanilla MoonShine fallback icon.
        $icon = is_string($params['icon'] ?? null) ? $params['icon'] : null;
        /** @var list<string> $searchFields */
        $searchFields = is_array($params['search'] ?? null) ? array_values(array_filter($params['search'], is_string(...))) : [];
        /** @var list<array<string, mixed>> $filterFields */
        $filterFields = is_array($params['filters'] ?? null) ? array_values(array_filter($params['filters'], is_array(...))) : [];

        // Auto-detect relation-only resources by the presence of `{parent_id}`
        // (or any `{...}`) placeholder in the endpoint. Caller can still force
        // the kind explicitly via `kind` param.
        $kind = is_string($params['kind'] ?? null) ? $params['kind'] : (str_contains($endpoint, '{') ? 'relation' : 'main');
        $responseMappingPropertyCode = $this->buildResponseMappingProperty($params['response_mapping'] ?? null);

        $serviceBaseName = Str::studly($serviceName);
        $registry = new ServiceRegistry;
        // Regenerating "posts" must rewrite an existing Post, not add a second resource.
        $resourceBaseName = $registry->resourceBaseName($serviceName, $resourceName);
        $className = $resourceBaseName.'Resource';
        $resourcesPath = $registry->resourcesPath();
        $serviceNamespace = $this->baseNamespaceForPath($resourcesPath).'\\'.$serviceBaseName;
        $resourceNamespace = $serviceNamespace.'\\'.$resourceBaseName;

        Log::info('ResourceGenerator: generating', [
            'class' => $className,
            'service' => $serviceName,
            'endpoint' => $endpoint,
            'fields_count' => count($fields),
        ]);

        // Generate relation sub-resources first (has_many, has_one with sub-fields)
        $relationResources = $this->generateRelationResources($fields, $serviceName, $serviceBaseName, $endpoint);

        // Build field code for pages. MoonShine 4+ has no per-field
        // visibility helpers (hideOnForm / hideOnCreate / hideOnUpdate) —
        // the supported way to hide a field from a page is to omit it from
        // that page's fields() method. `hide_on: ["form", "index", "detail"]`
        // in the field spec lets the MCP caller express that declaratively.
        // CRUD modifiers (creatable/editable/removable) only make sense on
        // the form page — index/detail pages render relation fields
        // read-only, so we strip them there.
        // Per-page field arrays let callers diverge the field list completely
        // for index/form/detail. `<page>_fields` REPLACES (not merges with)
        // the canonical `fields[]` for that page. Fallback to `fields[]`
        // filtered by `hide_on` keeps back-compat for specs that never
        // declared a per-page override.
        $indexFields = $this->resolvePageFields($params, $fields, 'index');
        $formFields = $this->resolvePageFields($params, $fields, 'form');
        $detailFields = $this->resolvePageFields($params, $fields, 'detail');

        [$indexFieldCode, $useStatements] = $this->buildFields($indexFields, $serviceName, $endpoint, $relationResources, withCrudButtons: false, page: 'index');
        [$formFieldCode, $formUseMerge, $formByColumn] = $this->buildFields($formFields, $serviceName, $endpoint, $relationResources, withCrudButtons: true, page: 'form');
        [$detailFieldCode, $detailUseMerge] = $this->buildFields($detailFields, $serviceName, $endpoint, $relationResources, withCrudButtons: false, page: 'detail');

        // Union of use statements — any page may need imports the others don't.
        $useStatements = array_merge($useStatements, $formUseMerge, $detailUseMerge);

        // Form layout: default = single Box wrap. `layout.form` can swap it
        // for a preset (tabs / two-column / ...) that groups fields into
        // labelled sections. Extra imports (Grid / Column / Tabs / Tab) are
        // merged into the page's use-statement set so the generated file
        // parses cleanly.
        $formLayoutImports = [];
        $layoutRoot = is_array($params['layout'] ?? null) ? $params['layout'] : null;
        $formLayoutSpecRaw = is_array($layoutRoot['form'] ?? null) ? $layoutRoot['form'] : null;
        /** @var array<string, mixed>|null $formLayoutSpec */
        $formLayoutSpec = $formLayoutSpecRaw;
        $formLayoutCode = $this->buildFormLayout(
            $formFieldCode,
            $formByColumn,
            $formLayoutSpec,
            $formLayoutImports,
        );
        $formExtraUseStatements = $formLayoutImports;
        $searchCode = $this->buildSearchFields($searchFields);
        $filterUseStatements = [];
        $filterCode = $this->buildFilterFields($filterFields, $serviceName, $filterUseStatements);
        $rulesSpec = is_array($params['rules'] ?? null) ? $params['rules'] : null;
        $rulesMethodCode = $this->buildRulesMethod($rulesSpec);
        $mutationsSpec = is_array($params['mutations'] ?? null) ? $params['mutations'] : null;
        $mutationsPropertyCode = $this->buildMutationsProperty($mutationsSpec);
        $actionsMethodCode = $this->buildActiveActionsMethod($params['actions'] ?? null);
        $defaultQueryPropertyCode = $this->buildDefaultQueryProperty($params['default_query'] ?? null);
        $listRequest = is_array($params['list_request'] ?? null) ? $params['list_request'] : [];
        $indexRequestPropertyCode = $this->buildIndexRequestProperty(
            $listRequest['method'] ?? ($params['index_method'] ?? null),
            $listRequest['body'] ?? ($params['index_body'] ?? null),
        );
        $detailStrategyOverrideCode = $this->buildDetailStrategyOverride(
            $params['detail_strategy'] ?? null,
            $params['detail_list_key_param'] ?? null,
        );
        $groupMethodCode = $this->buildGroupMethod($group);
        [$iconUseCode, $iconAttributeCode] = $this->buildIconAttribute($icon);

        // Write main resource (metadata only — no fields)
        $serviceDir = $resourcesPath.'/'.$serviceBaseName;
        $resourceDir = $serviceDir.'/'.$resourceBaseName;
        AtomicFileWriter::ensureDirectoryExists($resourceDir);

        // Pick the right stub based on resource kind.
        // - main: MultiPanelRestResource with panel + group — shows up in a panel menu.
        // - relation: AbstractRestResource without panel/group — only reachable through
        //   RestHasOne/RestHasMany on its parent. Writing the main stub here for a
        //   relation-only resource was the cause of the 500/404 on /blog-admin/resource/author-resource/*.
        $stubFile = $kind === 'relation' ? 'relation-resource.stub' : 'resource.stub';
        $resourceStub = File::get(dirname(__DIR__, 4).'/stubs/api-admin/'.$stubFile);
        $resourceContent = strtr($resourceStub, array_combine([
            '{{ namespace }}',
            '{{ className }}',
            '{{ resourceBaseName }}',
            '{{ panel }}',
            '{{ title }}',
            '{{ serviceName }}',
            '{{ groupMethod }}',
            '{{ iconUse }}',
            '{{ iconAttribute }}',
            '{{ endpoint }}',
            '{{ searchFields }}',
            '{{ actionsMethod }}',
            '{{ mutationsProperty }}',
            '{{ defaultQueryProperty }}',
            '{{ indexRequestProperty }}',
            '{{ detailStrategyOverrideMethod }}',
            '{{ responseMappingProperty }}',
        ], [
            $resourceNamespace,
            $className,
            $resourceBaseName,
            $this->escapePhpString($panel),
            $this->escapePhpString($title),
            $this->escapePhpString($serviceName),
            $groupMethodCode,
            $iconUseCode,
            $iconAttributeCode,
            $this->escapePhpString($endpoint),
            $searchCode,
            $actionsMethodCode,
            $mutationsPropertyCode,
            $defaultQueryPropertyCode,
            $indexRequestPropertyCode,
            $detailStrategyOverrideCode,
            $responseMappingPropertyCode,
        ]));

        $this->guardAgainstUnreplacedPlaceholders($resourceContent, 'resource.stub');

        $filePath = $resourceDir.'/'.$className.'.php';
        AtomicFileWriter::put($filePath, $resourceContent);

        // Generate the three pages (Index / Form / Detail) carrying the field definitions
        $pageFiles = $this->generatePages(
            resourceDir: $resourceDir,
            resourceBaseName: $resourceBaseName,
            resourceNamespace: $resourceNamespace,
            className: $className,
            indexFields: $indexFieldCode,
            formLayout: $formLayoutCode,
            formExtraUseStatements: $formExtraUseStatements,
            detailFields: $detailFieldCode,
            filterFields: $filterCode,
            fieldUseStatements: $useStatements,
            filterUseStatements: $filterUseStatements,
            rulesMethod: $rulesMethodCode,
        );

        // Sidecar spec file — the exact params that produced this output.
        // Lets get_resource return a round-trippable spec so agents can
        // do partial updates (read → merge → update) instead of having
        // to restate the full field list on every tweak. Sidecars live
        // under .ai-factory/specs/<service>/<resource>.json, versioned
        // with the working tree so regeneration via services-regen
        // keeps them in lockstep.
        $this->writeSpecSidecar($serviceName, $resourceName, $params);

        Log::info('ResourceGenerator: generated', [
            'file' => $filePath,
            'class' => $resourceNamespace.'\\'.$className,
        ]);

        return [
            'file' => $filePath,
            'class' => $resourceNamespace.'\\'.$className,
            'className' => $className,
            'pages' => $pageFiles,
            'relationResources' => $relationResources,
        ];
    }

    private function baseNamespaceForPath(string $resourcesPath): string
    {
        $path = str_replace('\\', '/', $resourcesPath);
        $configuredBase = config('api-admin.resources_path', app_path('MoonShine/ApiAdmin/Resources'));
        $basePath = str_replace(
            '\\',
            '/',
            is_string($configuredBase) ? $configuredBase : app_path('MoonShine/ApiAdmin/Resources'),
        );

        if ($path === $basePath) {
            return self::BASE_NAMESPACE;
        }

        if (str_starts_with($path, $basePath.'/')) {
            $relative = trim(substr($path, strlen($basePath) + 1), '/');
        } else {
            $marker = '/MoonShine/ApiAdmin/Resources/';
            $position = strpos($path, $marker);
            $relative = $position === false ? '' : trim(substr($path, $position + strlen($marker)), '/');
        }

        if ($relative === '') {
            return self::BASE_NAMESPACE;
        }

        $segments = array_filter(
            explode('/', $relative),
            static fn (string $segment): bool => $segment !== '',
        )
            |> array_values(...);

        return self::BASE_NAMESPACE.'\\'.implode('\\', array_map(
            Str::studly(...),
            $segments,
        ));
    }

    /**
     * @param array<string, string> $formExtraUseStatements
     * @param array<string, string> $fieldUseStatements
     * @param array<string, string> $filterUseStatements
     *
     * @return array<string, string>
     */
    private function generatePages(
        string $resourceDir,
        string $resourceBaseName,
        string $resourceNamespace,
        string $className,
        string $indexFields,
        string $formLayout,
        array $formExtraUseStatements,
        string $detailFields,
        string $filterFields,
        array $fieldUseStatements,
        array $filterUseStatements,
        string $rulesMethod = '',
    ): array {
        $pagesDir = $resourceDir.'/Pages';
        AtomicFileWriter::ensureDirectoryExists($pagesDir);

        $pagesNamespace = $resourceNamespace.'\\Pages';

        /** @var array<string, string> $indexMerged */
        $indexMerged = array_merge($fieldUseStatements, $filterUseStatements);
        $indexUseStatements = $this->buildUseStatements($indexMerged);
        $formUseStatements = $this->buildUseStatements(
            array_merge($fieldUseStatements, $formExtraUseStatements),
        );
        $detailUseStatements = $this->buildUseStatements($fieldUseStatements);

        $indexPath = $this->writePage(
            stub: dirname(__DIR__, 4).'/stubs/api-admin/index-page.stub',
            outputPath: $pagesDir."/{$resourceBaseName}IndexPage.php",
            replacements: [
                '{{ namespace }}' => $pagesNamespace,
                '{{ className }}' => "{$resourceBaseName}IndexPage",
                '{{ resourceNamespace }}' => $resourceNamespace,
                '{{ resourceClass }}' => $className,
                '{{ useStatements }}' => $indexUseStatements,
                '{{ indexFields }}' => $indexFields,
                '{{ filterFields }}' => $filterFields,
            ],
        );

        // Form page builds its top-level layout (Box / Grid+Column / Tabs)
        // in the generator, not the stub — the stub only has the return[…]
        // wrapper and drops formLayout into it. Default layout is still a
        // single Box::make([...]) — stays identical to pre-preset output.
        $formPath = $this->writePage(
            stub: dirname(__DIR__, 4).'/stubs/api-admin/form-page.stub',
            outputPath: $pagesDir."/{$resourceBaseName}FormPage.php",
            replacements: [
                '{{ namespace }}' => $pagesNamespace,
                '{{ className }}' => "{$resourceBaseName}FormPage",
                '{{ resourceNamespace }}' => $resourceNamespace,
                '{{ resourceClass }}' => $className,
                '{{ useStatements }}' => $formUseStatements,
                '{{ formLayout }}' => $formLayout,
                '{{ rulesMethod }}' => $rulesMethod,
            ],
        );

        $detailPath = $this->writePage(
            stub: dirname(__DIR__, 4).'/stubs/api-admin/detail-page.stub',
            outputPath: $pagesDir."/{$resourceBaseName}DetailPage.php",
            replacements: [
                '{{ namespace }}' => $pagesNamespace,
                '{{ className }}' => "{$resourceBaseName}DetailPage",
                '{{ resourceNamespace }}' => $resourceNamespace,
                '{{ resourceClass }}' => $className,
                '{{ useStatements }}' => $detailUseStatements,
                '{{ detailFields }}' => $detailFields,
            ],
        );

        return [
            'index' => $indexPath,
            'form' => $formPath,
            'detail' => $detailPath,
        ];
    }

    /**
     * @param array<string, string> $replacements
     */
    private function writePage(string $stub, string $outputPath, array $replacements): string
    {
        $content = strtr(File::get($stub), $replacements);

        $this->guardAgainstUnreplacedPlaceholders($content, basename($stub));

        AtomicFileWriter::put($outputPath, $content);

        return $outputPath;
    }

    /**
     * Persist the submitted spec alongside the generated code.
     *
     * Thin delegate to {@see SpecStore::write()}. Both create_resource
     * (full overwrite) and the partial-delta tools (add_field/etc.) go
     * through the same sidecar I/O so JSON shape can never diverge.
     *
     * @param array<string, mixed> $params
     */
    private function writeSpecSidecar(string $service, string $resource, array $params): void
    {
        SpecStore::write($service, $resource, $params);
    }

    /**
     * Resolve the on-disk path for a resource spec sidecar.
     *
     * Kept as a public static delegate so existing callers
     * (ResourceActionResolver, GetResourceTool, FetchResourceItemsTool)
     * continue to work unchanged. New code should call
     * {@see SpecStore::path()} directly.
     */
    public static function specSidecarPath(string $service, string $resource): string
    {
        return SpecStore::path($service, $resource);
    }

    /**
     * Refuse to write a file that still contains `{{ something }}`-style
     * stub slots. Most likely cause: the running MCP process has opcache
     * -cached an older ResourceGenerator that doesn't know about a
     * placeholder added to the stub file. Writing the file anyway produces
     * a class that fails PHP's parser the moment it's autoloaded — and
     * once that happens, registerGeneratedResources() blows up on every
     * request, blocking future regenerations via the same MCP process.
     * Early-throwing here keeps the file that was on disk prior to the
     * call intact, so the admin panel stays functional and the agent gets
     * a clear actionable error.
     */
    private function guardAgainstUnreplacedPlaceholders(string $content, string $stubLabel): void
    {
        if (preg_match_all('/\{\{\s*[a-zA-Z_]\w*\s*\}\}/', $content, $matches) < 1) {
            return;
        }

        $unique = $matches[0]
            |> array_unique(...)
            |> array_values(...);
        $placeholders = implode(', ', $unique);

        throw new RuntimeException(
            "ResourceGenerator: stub '{$stubLabel}' still contains unreplaced placeholder(s) {$placeholders} "
            .'after substitution. This usually means the running MCP process has an opcache-cached '
            .'older ResourceGenerator that was not aware of a new stub slot. Reconnect the MCP server '
            .'(kill the `php artisan api-admin:mcp-server` process so the client respawns it) so '
            .'Laravel rebootstraps with the current code, then retry.',
        );
    }

    /**
     * Build field definitions and collect use statements.
     *
     * @param array<int, array<string, mixed>> $fields
     * @param array<string, array{class: string, className: string}> $relationResources
     * @param bool $withCrudButtons Emit creatable/editable/removable for has_one/has_many
     *                              relations. Form pages need them; index/detail pages
     *                              render the relation read-only and should omit them.
     *
     * @return array{string, array<string, string>, array<string, string>} [fieldCode, useStatements, byColumn]
     */
    private function buildFields(array $fields, string $serviceName, string $resourceEndpoint = '', array $relationResources = [], bool $withCrudButtons = true, string $page = 'form'): array
    {
        /** @var list<string> $lines */
        $lines = [];
        /** @var array<string, string> $byColumn */
        $byColumn = [];
        /** @var array<string, string> $useStatements */
        $useStatements = [];
        $catalog = self::fieldTypeCatalog();

        foreach ($fields as $field) {
            $type = is_string($field['type'] ?? null) ? $field['type'] : 'text';
            $this->validateHostedFieldType($type);
            $column = is_string($field['column'] ?? null) ? $field['column'] : '';
            $label = is_string($field['label'] ?? null) ? $field['label'] : Str::title(str_replace('_', ' ', $column));
            $methods = $field['methods'] ?? [];
            $columnLiteral = $this->escapePhpString($column);
            $labelLiteral = $this->escapePhpString($label);

            $mapping = $catalog[$type] ?? $catalog['text'];
            $useStatements[$mapping['class']] = $mapping['alias'];

            // For has_many/has_one with a generated resource, pass resource class to constructor
            $hasRelationResource = in_array($type, ['has_many', 'has_one']) && isset($relationResources[$column]);

            if ($hasRelationResource) {
                $relResourceClass = $relationResources[$column]['className'];
                $relResourceFqcn = $relationResources[$column]['class'];
                $useStatements[$relResourceFqcn] = $relResourceClass;
                $line = "            {$mapping['alias']}::make('{$labelLiteral}', '{$columnLiteral}', resource: {$relResourceClass}::class)";
            } else {
                $line = "            {$mapping['alias']}::make('{$labelLiteral}', '{$columnLiteral}')";
            }

            // API-admin resources are not Eloquent-backed — they POST the raw
            // password to an upstream service that does its own hashing. The
            // native Password field defaults to hashing via Hasher::make on
            // apply, which would double-hash and lock users out. `->raw()`
            // (available since MoonShine 4) flips the field into raw-passthrough
            // mode. Emit it unconditionally for `type: password`.
            if ($type === 'password') {
                $line .= '->raw()';
            }

            // Method-chain emission, page-aware. `methods` accepts two shapes:
            //   flat: ["required", "sortable"]       — same on create & update
            //   split: {"create": [...], "update": [...]} — differs per mode
            //
            // On the form page, split methods emit a conditional form so the
            // UI asterisk (and friends) only appears in the right mode —
            // e.g. `->required($this->getItem() === null)` for create-only.
            // On index/detail, the same UI methods rarely apply anyway; we
            // just emit the union so nothing breaks.
            [$sharedMethods, $createOnlyMethods, $updateOnlyMethods] =
                $this->normaliseFieldMethods($methods, $mapping['class']);

            foreach ($sharedMethods as $m) {
                $line .= "->{$m['name']}({$m['args']})";
            }

            if ($page === 'form') {
                foreach ($createOnlyMethods as $m) {
                    $line .= $this->buildConditionalMethodCall(
                        $mapping['class'],
                        $m,
                        '$this->getItem() === null',
                    );
                }
                foreach ($updateOnlyMethods as $m) {
                    $line .= $this->buildConditionalMethodCall(
                        $mapping['class'],
                        $m,
                        '$this->getItem() !== null',
                    );
                }
            } else {
                // Non-form pages: render union of both modes unconditionally,
                // de-duplicated so we don't emit the same method twice.
                $seen = [];
                foreach ([...$createOnlyMethods, ...$updateOnlyMethods] as $m) {
                    if (isset($seen[$m['name']])) {
                        continue;
                    }
                    $seen[$m['name']] = true;
                    $line .= "->{$m['name']}({$m['args']})";
                }
            }

            // Static options for select-like fields. Pass `'options' => [k => v, ...]`
            // in the field definition to render a Select with predefined choices —
            // useful for enum-style columns (status, role, etc.) where the values
            // come from the application, not an API endpoint.
            if (isset($field['options']) && is_array($field['options']) && $type === 'select') {
                $line .= '->options('.$this->phpExportShort($field['options']).')';
            }

            // Add relation-specific configuration
            if (in_array($type, ['belongs_to', 'has_many', 'has_one', 'belongs_to_many'])) {
                $relEndpoint = is_string($field['endpoint'] ?? null) ? $field['endpoint'] : '';
                $valueKey = is_string($field['value_key'] ?? null) ? $field['value_key'] : 'id';
                $labelKey = is_string($field['label_key'] ?? null) ? $field['label_key'] : 'name';
                $targetService = $this->normalizeRelationTargetService($field, $serviceName);
                $relatedResource = $this->resolveRelatedResource($field, $serviceName);

                // Inline child resources use nested routes; explicitly linked resources own their routes.
                if ($relatedResource === null && in_array($type, ['has_many', 'has_one']) && $relEndpoint !== '') {
                    $relEndpoint = $this->ensureParentIdPlaceholder($relEndpoint, $resourceEndpoint, $column);
                }

                if ($targetService !== null) {
                    Log::debug('ResourceGenerator: accepted relation target service', [
                        'column' => $column,
                        'source_service' => $serviceName,
                        'target_service' => $targetService,
                    ]);
                    $line .= "\n                ->targetService('".addslashes($targetService)."')";
                } elseif (isset($field['target_service']) || isset($field['service'])) {
                    Log::debug('ResourceGenerator: relation target service uses same-service fallback', [
                        'column' => $column,
                        'source_service' => $serviceName,
                    ]);
                }

                if ($relEndpoint !== '') {
                    $line .= "\n                ->endpoint('".$this->escapePhpString($relEndpoint)."')";
                }
                if ($relatedResource !== null) {
                    $line .= "\n                ->relatedResource(\\{$relatedResource}::class)";
                }

                // Static query params riding every fetch this field does
                // (`?for_entity=project`, `?scope=global`, ...). Lives on
                // the field rather than default_query because the right
                // scope can differ per referring resource.
                if (isset($field['endpoint_query']) && is_array($field['endpoint_query']) && $field['endpoint_query'] !== []) {
                    $clean = [];
                    foreach ($field['endpoint_query'] as $k => $v) {
                        if (is_string($k) && $k !== '' && (is_string($v) || is_int($v) || is_float($v) || is_bool($v) || $v === null)) {
                            $clean[$k] = $v;
                        }
                    }
                    if ($clean !== []) {
                        $line .= "\n                ->endpointQuery(".$this->phpExportShort($clean).')';
                    }
                }

                // depends_on wires CREATE-mode cascade. Field becomes reactive and
                // MoonShine re-renders the async-search URL with fresh sibling
                // values on every form change. The driver sibling (e.g. project_id
                // select) must also be marked `reactive: true` for change events
                // to fire.
                if (isset($field['depends_on']) && is_array($field['depends_on']) && $field['depends_on'] !== []) {
                    $dependsClean = [];
                    foreach ($field['depends_on'] as $depCol) {
                        if (is_string($depCol) && $depCol !== '') {
                            $dependsClean[] = $depCol;
                        }
                    }
                    if ($dependsClean !== []) {
                        $line .= "\n                ->dependsOn(".$this->phpExportShort($dependsClean).')';
                    }
                }

                // For belongs_to_many, generate attachEndpoint from resource endpoint + column
                if ($type === 'belongs_to_many') {
                    $attachEndpoint = is_string($field['attach_endpoint'] ?? null)
                        ? $field['attach_endpoint']
                        : rtrim($resourceEndpoint, '/').'/{parent_id}/'.$column;
                    $line .= "\n                ->attachEndpoint('".$this->escapePhpString($attachEndpoint)."')";
                }

                // For has_many/has_one with a relation resource, enable CRUD buttons.
                // Naming follows MoonShine's removable() convention.
                if ($withCrudButtons && in_array($type, ['has_many', 'has_one'], true) && ($hasRelationResource || $relatedResource !== null)) {
                    $line .= "\n                ->creatable()\n                ->editable()\n                ->removable()";
                }

                if (is_string($field['value_key'] ?? null) && in_array($type, ['belongs_to', 'belongs_to_many'], true)) {
                    $line .= "->valueKey('".$this->escapePhpString($valueKey)."')";
                }

                if ($labelKey !== 'name') {
                    $line .= "->labelKey('".$this->escapePhpString($labelKey)."')";
                }
            }

            $lines[] = $line.',';

            // Track every field line by its column so the layout renderer
            // (tabs / two-column / etc. presets) can pick fields per group.
            // Keyed collisions are unlikely — column is already unique per
            // field spec, but we don't guard here because the caller has
            // already been validated upstream.
            if ($column !== '') {
                $byColumn[$column] = $line.',';
            }
        }

        return [implode("\n", $lines), $useStatements, $byColumn];
    }

    /**
     * Generate separate resource classes for has_many/has_one relations with sub-fields.
     *
     * @param array<int, array<string, mixed>> $fields
     *
     * @return array<string, array{file: string, class: string, className: string}>
     */
    private function generateRelationResources(array $fields, string $serviceName, string $serviceBaseName, string $parentEndpoint): array
    {
        $resources = [];
        $resourcesPath = (new ServiceRegistry)->resourcesPath();
        $serviceNamespace = $this->baseNamespaceForPath($resourcesPath).'\\'.$serviceBaseName;
        $serviceDir = $resourcesPath.'/'.$serviceBaseName;

        foreach ($fields as $field) {
            $type = is_string($field['type'] ?? null) ? $field['type'] : 'text';
            $this->validateHostedFieldType($type);
            $column = is_string($field['column'] ?? null) ? $field['column'] : '';
            $subFields = is_array($field['fields'] ?? null) ? $field['fields'] : [];
            $targetService = $this->normalizeRelationTargetService($field, $serviceName);
            if (! in_array($type, ['has_many', 'has_one'])) {
                continue;
            }
            if (array_key_exists('related_resource', $field)) {
                continue;
            }
            if ($subFields === []) {
                continue;
            }
            if ($targetService !== null) {
                Log::warning('ResourceGenerator: cross-service relation resources are not generated', [
                    'column' => $column,
                    'source_service' => $serviceName,
                    'target_service' => $targetService,
                ]);

                continue;
            }

            $this->validateClassName($column);
            $resourceBaseName = Str::studly(Str::singular($column));
            $className = $resourceBaseName.'Resource';
            $title = Str::title(str_replace('_', ' ', Str::singular($column)));
            $resourceNamespace = $serviceNamespace.'\\'.$resourceBaseName;

            $resourceDir = $serviceDir.'/'.$resourceBaseName;
            $filePath = $resourceDir.'/'.$className.'.php';

            // If the target resource already exists — e.g. the developer created
            // it first as a standalone `main` resource (endpoint /authors) and
            // now wires has_one/has_many to it from a parent — don't overwrite.
            // Just reuse the existing class so the parent field gets the right
            // `resource: AuthorResource::class` reference.
            if (File::isFile($filePath)) {
                Log::info('ResourceGenerator: reusing existing relation resource', [
                    'column' => $column,
                    'class' => $resourceNamespace.'\\'.$className,
                    'file' => $filePath,
                ]);

                $resources[$column] = [
                    'file' => $filePath,
                    'class' => $resourceNamespace.'\\'.$className,
                    'className' => $className,
                ];

                continue;
            }

            // Build per-page field lines (index = read-only list, form = inputs, detail = read-only)
            $indexFieldLines = [];
            $formFieldLines = [];
            $detailFieldLines = [];
            $useStatements = [];

            $useStatements[ID::class] = 'ID';
            $indexFieldLines[] = "            ID::make('Id', 'id'),";
            $detailFieldLines[] = "            ID::make('Id', 'id'),";

            $subCatalog = self::fieldTypeCatalog();
            foreach ($subFields as $sub) {
                if (! is_array($sub)) {
                    continue;
                }
                $subType = is_string($sub['type'] ?? null) ? $sub['type'] : 'text';
                $this->validateHostedFieldType($subType);
                $subColumn = is_string($sub['column'] ?? null) ? $sub['column'] : '';
                $subLabel = is_string($sub['label'] ?? null) ? $sub['label'] : Str::title(str_replace('_', ' ', $subColumn));
                $subColumn = $this->escapePhpString($subColumn);
                $subLabel = $this->escapePhpString($subLabel);
                $subMapping = $subCatalog[$subType] ?? $subCatalog['text'];
                $useStatements[$subMapping['class']] = $subMapping['alias'];

                $indexFieldLines[] = "            {$subMapping['alias']}::make('{$subLabel}', '{$subColumn}'),";
                $detailFieldLines[] = "            {$subMapping['alias']}::make('{$subLabel}', '{$subColumn}'),";

                // Form: use Textarea for 'body'-like fields, required by default
                $formAlias = $subMapping['alias'];
                if ($subType === 'text' && in_array($subColumn, ['body', 'content', 'description', 'text'])) {
                    $useStatements[Textarea::class] = 'Textarea';
                    $formAlias = 'Textarea';
                }
                $formFieldLines[] = "            {$formAlias}::make('{$subLabel}', '{$subColumn}')->required(),";
            }

            // Determine endpoint for the relation resource
            $relEndpoint = is_string($field['endpoint'] ?? null) ? $field['endpoint'] : '';
            if ($relEndpoint !== '') {
                $relEndpoint = $this->ensureParentIdPlaceholder($relEndpoint, $parentEndpoint, $column);
            } else {
                $relEndpoint = rtrim($parentEndpoint, '/').'/{parent_id}/'.$column;
            }

            // Write relation resource (metadata only)
            AtomicFileWriter::ensureDirectoryExists($resourceDir);

            $resourceStub = File::get(dirname(__DIR__, 4).'/stubs/api-admin/relation-resource.stub');
            $resourceContent = strtr($resourceStub, array_combine([
                '{{ namespace }}',
                '{{ className }}',
                '{{ resourceBaseName }}',
                '{{ title }}',
                '{{ serviceName }}',
                '{{ endpoint }}',
                '{{ actionsMethod }}',
                '{{ mutationsProperty }}',
                '{{ defaultQueryProperty }}',
                '{{ indexRequestProperty }}',
                '{{ detailStrategyOverrideMethod }}',
                '{{ responseMappingProperty }}',
            ], [
                $resourceNamespace,
                $className,
                $resourceBaseName,
                $this->escapePhpString($title),
                $this->escapePhpString($serviceName),
                $this->escapePhpString($relEndpoint),
                '',
                '',
                '',
                '',
                '',
                '',
            ]));

            AtomicFileWriter::put($filePath, $resourceContent);

            // Relation-resource pages never use layout presets — the
            // relation is driven by the parent resource's form. Default
            // single-Box wrap is all we need here.
            $relationFormLayout = $this->wrapLinesInBox(implode("\n", $formFieldLines));

            // Write pages (Index / Form / Detail) carrying fields
            $this->generatePages(
                resourceDir: $resourceDir,
                resourceBaseName: $resourceBaseName,
                resourceNamespace: $resourceNamespace,
                className: $className,
                indexFields: implode("\n", $indexFieldLines),
                formLayout: $relationFormLayout,
                formExtraUseStatements: [],
                detailFields: implode("\n", $detailFieldLines),
                filterFields: '',
                fieldUseStatements: $useStatements,
                filterUseStatements: [],
            );

            Log::info('ResourceGenerator: generated relation resource', [
                'class' => $resourceNamespace.'\\'.$className,
                'for_column' => $column,
            ]);

            $resources[$column] = [
                'file' => $filePath,
                'class' => $resourceNamespace.'\\'.$className,
                'className' => $className,
            ];
        }

        return $resources;
    }

    /**
     * Build search fields array code.
     *
     * @param array<int, string> $searchFields
     */
    private function buildSearchFields(array $searchFields): string
    {
        if ($searchFields === []) {
            return '';
        }

        return implode(', ', array_map(
            fn (string $field): string => "'".$this->escapePhpString($field)."'",
            $searchFields,
        ));
    }

    /**
     * Build filter field definitions.
     *
     * Relation filters (belongs_to / belongs_to_many) need the SAME
     * endpoint / value_key / label_key / endpoint_query chain that the
     * regular index field gets — otherwise loadOptions() sees an empty
     * `apiEndpoint` and the filter dropdown silently renders with no
     * options even though the underlying API has data. Classic symptom:
     * "the select is empty in filters although the projects/users
     * definitely exist". We replicate the relevant chain here rather
     * than folding filters into buildFields() because filters don't
     * carry the form-only extras (crud buttons, outside rendering,
     * per-mode methods) and keeping the two paths lean catches schema
     * drift earlier.
     *
     * @param array<int, array<string, mixed>> $filterFields
     * @param array<string, string> &$useStatements
     */
    private function buildFilterFields(array $filterFields, string $serviceName, array &$useStatements): string
    {
        if ($filterFields === []) {
            return '';
        }

        $lines = [];
        $catalog = self::fieldTypeCatalog();

        foreach ($filterFields as $filter) {
            $type = is_string($filter['type'] ?? null) ? $filter['type'] : 'text';
            $this->validateHostedFieldType($type);
            $column = is_string($filter['column'] ?? null) ? $filter['column'] : '';
            $label = is_string($filter['label'] ?? null) ? $filter['label'] : Str::title(str_replace('_', ' ', $column));

            $mapping = $catalog[$type] ?? $catalog['text'];
            $useStatements[$mapping['class']] = $mapping['alias'];

            $columnLiteral = $this->escapePhpString($column);
            $labelLiteral = $this->escapePhpString($label);
            $line = "            {$mapping['alias']}::make('{$labelLiteral}', '{$columnLiteral}')";

            // Static options for select-like filters. Without this, a
            // `Select` filter renders as an empty dropdown even when the
            // caller declared enum-style choices — and the MCP caller had
            // to work around the gap by marking the column plain text.
            if ($type === 'select' && isset($filter['options']) && is_array($filter['options'])) {
                $line .= '->options('.$this->phpExportShort($filter['options']).')';
            }

            // Relation filters need the same HTTP wiring as regular
            // relation fields or the options dropdown is silently empty.
            if (in_array($type, ['belongs_to', 'belongs_to_many', 'has_many', 'has_one'], true)) {
                $targetService = $this->normalizeRelationTargetService($filter, $serviceName);
                if ($targetService !== null) {
                    Log::debug('ResourceGenerator: accepted relation filter target service', [
                        'column' => $column,
                        'source_service' => $serviceName,
                        'target_service' => $targetService,
                    ]);
                    $line .= "->targetService('".addslashes($targetService)."')";
                } elseif (isset($filter['target_service']) || isset($filter['service'])) {
                    Log::debug('ResourceGenerator: relation filter target service uses same-service fallback', [
                        'column' => $column,
                        'source_service' => $serviceName,
                    ]);
                }

                $relEndpoint = is_string($filter['endpoint'] ?? null) ? $filter['endpoint'] : '';
                if ($relEndpoint !== '') {
                    $line .= "->endpoint('".addslashes($relEndpoint)."')";
                }
                $relatedResource = $this->resolveRelatedResource($filter, $serviceName);
                if ($relatedResource !== null) {
                    $line .= "->relatedResource(\\{$relatedResource}::class)";
                }

                $valueKey = is_string($filter['value_key'] ?? null) ? $filter['value_key'] : 'id';
                if (is_string($filter['value_key'] ?? null) && in_array($type, ['belongs_to', 'belongs_to_many'], true)) {
                    $line .= "->valueKey('".addslashes($valueKey)."')";
                }

                $labelKey = is_string($filter['label_key'] ?? null) ? $filter['label_key'] : 'name';
                if ($labelKey !== 'name') {
                    $line .= "->labelKey('".addslashes($labelKey)."')";
                }

                if (isset($filter['endpoint_query']) && is_array($filter['endpoint_query']) && $filter['endpoint_query'] !== []) {
                    $line .= '->endpointQuery('.$this->phpExportShort($filter['endpoint_query']).')';
                }
            }

            $lines[] = $line.',';
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $spec
     */
    private function normalizeRelationTargetService(array $spec, string $sourceService): ?string
    {
        $raw = $spec['target_service'] ?? $spec['service'] ?? null;
        if (! is_string($raw)) {
            return null;
        }

        $targetService = trim($raw);
        if ($targetService === '' || $targetService === $sourceService) {
            return null;
        }

        return $targetService;
    }

    /** @param array<string, mixed> $spec */
    private function resolveRelatedResource(array $spec, string $sourceService): ?string
    {
        if (! array_key_exists('related_resource', $spec)) {
            return null;
        }
        $name = $spec['related_resource'];
        if (! is_string($name)) {
            throw new InvalidArgumentException('related_resource must be a registered resource name.');
        }
        $this->validateClassName($name);
        $service = $this->normalizeRelationTargetService($spec, $sourceService) ?? $sourceService;
        $this->validateClassName($service);
        $registry = new ServiceRegistry;
        if ($registry->findResource($service, $name) === null) {
            throw new InvalidArgumentException("Related resource '{$service}/{$name}' must be generated first.");
        }
        $baseName = $registry->resourceBaseName($service, $name);

        return $this->baseNamespaceForPath($registry->resourcesPath()).'\\'.Str::studly($service).'\\'.$baseName.'\\'.$baseName.'Resource';
    }

    /**
     * Ensure has_many/has_one endpoint uses {parent_id} placeholder instead of hardcoded IDs.
     *
     * Input like "/posts/1/comments" becomes "/posts/{parent_id}/comments".
     * Input already containing "{parent_id}" is returned as-is.
     */
    private function ensureParentIdPlaceholder(string $endpoint, string $resourceEndpoint, string $column): string
    {
        if (str_contains($endpoint, '{parent_id}')) {
            return $endpoint;
        }

        $escaped = preg_quote(rtrim($resourceEndpoint, '/'), '#');
        $replaced = preg_replace(
            "#^{$escaped}/\d+/#",
            rtrim($resourceEndpoint, '/').'/{parent_id}/',
            $endpoint,
        );

        if ($replaced !== null && $replaced !== $endpoint) {
            return $replaced;
        }

        return rtrim($resourceEndpoint, '/').'/{parent_id}/'.$column;
    }

    /**
     * Compact PHP-array literal for inline emission (e.g. Select options).
     */
    /**
     * Build a `protected function activeActions(): ListOf` method body for
     * the Resource class. Given an allowlist of action names
     * (`create`, `view`, `update`, `delete`, `mass_delete`) this emits only
     * the matching `Action::*` enum cases, which hides the corresponding
     * buttons and throws 403 on the HTTP layer.
     *
     * Used for APIs that don't support the full CRUD surface — e.g.
     * task-manager returns 405 on `DELETE /tasks/{id}`, so the resource
     * should advertise `actions: ["create","view","update"]` and the
     * delete button never renders.
     */
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
                $cases[] = '\\MoonShine\\Support\\Enums\\Action::'.$allowedMap[$key];
            }
        }

        if ($cases === []) {
            return '';
        }

        $casesBlock = '            '.implode(",\n            ", $cases).',';

        return <<<PHP


    /**
     * @return \\MoonShine\\Support\\ListOf<\\MoonShine\\Support\\Enums\\Action>
     */
    protected function activeActions(): \\MoonShine\\Support\\ListOf
    {
        return new \\MoonShine\\Support\\ListOf(\\MoonShine\\Support\\Enums\\Action::class, [
{$casesBlock}
        ]);
    }
PHP;
    }

    /**
     * Resolve the field list for a given page.
     *
     * Per-page override key (`index_fields` / `form_fields` /
     * `detail_fields`), when present and non-empty, REPLACES the
     * canonical `fields[]` for that page — `hide_on` becomes irrelevant
     * because the override is the complete list. Fallback path filters
     * `fields[]` by `hide_on`, preserving the back-compat behavior for
     * specs that never declared an override.
     *
     * @param array<string, mixed> $params
     * @param array<int, array<string, mixed>> $fields canonical fields[]
     *
     * @return array<int, array<string, mixed>>
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
     * Drop fields whose `hide_on` list includes the target page identifier.
     * Accepts "form" | "index" | "detail" — anything else is ignored so
     * typos don't silently hide the field from every page.
     *
     * @param array<int, array<string, mixed>> $fields
     *
     * @return array<int, array<string, mixed>>
     */
    private function filterFieldsForPage(array $fields, string $page): array
    {
        return array_filter($fields, static function (array $field) use ($page): bool {
            if (! isset($field['hide_on'])) {
                return true;
            }

            $hideOn = $field['hide_on'];
            if (is_string($hideOn)) {
                $hideOn = [$hideOn];
            }

            if (! is_array($hideOn)) {
                return true;
            }

            return ! in_array($page, $hideOn, true);
        })
            |> array_values(...);
    }

    /**
     * Build a `protected array $defaultQuery = [...]` property declaration.
     * Accepts {index: {...}, detail: {...}} and keeps only those two
     * buckets — each sub-map must be string→(scalar|list<scalar>).
     * Invalid entries are silently dropped so agents can't emit broken
     * property literals (the validator would catch it anyway, but it's
     * cheaper to filter here).
     */
    private function buildDefaultQueryProperty(mixed $defaultQuery): string
    {
        if (! is_array($defaultQuery) || $defaultQuery === []) {
            return '';
        }

        $normalized = [];

        foreach (['index', 'detail'] as $bucket) {
            if (! isset($defaultQuery[$bucket])) {
                continue;
            }
            if (! is_array($defaultQuery[$bucket])) {
                continue;
            }
            $clean = [];
            foreach ($defaultQuery[$bucket] as $k => $v) {
                if (! is_string($k)) {
                    continue;
                }
                if ($k === '') {
                    continue;
                }
                if (is_string($v) || is_int($v) || is_float($v) || is_bool($v) || $v === null) {
                    $clean[$k] = $v;
                    continue;
                }

                if (is_array($v) && array_is_list($v)) {
                    $list = array_filter(
                        $v,
                        static fn ($item): bool => is_string($item) || is_int($item) || is_float($item),
                    )
                        |> array_values(...);
                    if ($list !== []) {
                        $clean[$k] = $list;
                    }
                }
            }

            if ($clean !== []) {
                $normalized[$bucket] = $clean;
            }
        }

        if ($normalized === []) {
            return '';
        }

        $body = $this->exportArrayIndented($normalized, 2);

        return <<<PHP


    /**
     * @var array<string, array<string, mixed>>
     */
    protected array \$defaultQuery = {$body};
PHP;
    }

    private function buildResponseMappingProperty(mixed $mapping): string
    {
        if ($mapping === null || $mapping === []) {
            return '';
        }

        if (! is_array($mapping)) {
            throw new InvalidArgumentException('response_mapping must be an object.');
        }

        $defaults = new ResponseMapping()->toArray();
        $enums = [
            'filter_format' => ['nested', 'flat'],
            'pagination_type' => ['length_aware', 'cursor', 'link_header', 'none'],
            'detail_strategy' => ['endpoint', 'list'],
        ];
        foreach ($mapping as $key => $value) {
            if (! is_string($key) || ! array_key_exists($key, $defaults)) {
                throw new InvalidArgumentException('Unknown response_mapping key.');
            }
            if (is_bool($defaults[$key]) ? ! is_bool($value) : ! is_string($value)) {
                throw new InvalidArgumentException("Invalid response_mapping.{$key} value type.");
            }
            if (isset($enums[$key]) && ! in_array($value, $enums[$key], true)) {
                throw new InvalidArgumentException("Invalid response_mapping.{$key} value.");
            }
        }

        $body = $this->exportArrayIndented($mapping, 2);

        return <<<PHP


    /**
     * @var array<string, bool|string>
     */
    protected array \$responseMapping = {$body};
PHP;
    }

    /**
     * Build resource-level index request overrides.
     *
     * `index_method` defaults to GET, so we only emit code for POST. `index_body`
     * is a static JSON body merged into POST list requests and is also omitted
     * unless POST is active.
     */
    private function buildIndexRequestProperty(mixed $method, mixed $body): string
    {
        if (! is_string($method) || strtoupper($method) !== 'POST') {
            return '';
        }

        $parts = [
            <<<'PHP'


    protected string $indexMethod = 'POST';
PHP,
        ];

        if (is_array($body)) {
            $clean = $this->normalizeStaticBody($body);
            if ($clean !== []) {
                $exported = $this->exportArrayIndented($clean, 2);
                $parts[] = <<<PHP


    /**
     * @var array<string, mixed>
     */
    protected array \$indexBody = {$exported};
PHP;
            }
        }

        return implode('', $parts);
    }

    /**
     * @param array<array-key, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function normalizeStaticBody(array $body): array
    {
        $clean = [];

        foreach ($body as $key => $value) {
            if (! is_string($key)) {
                continue;
            }

            if ($key === '') {
                continue;
            }

            if (is_string($value) || is_int($value) || is_float($value) || is_bool($value) || $value === null) {
                $clean[$key] = $value;
                continue;
            }

            if (is_array($value) && array_is_list($value)) {
                $list = array_filter(
                    $value,
                    static fn ($item): bool => is_string($item) || is_int($item) || is_float($item) || is_bool($item) || $item === null,
                )
                    |> array_values(...);
                $clean[$key] = $list;
            }
        }

        return $clean;
    }

    /**
     * Build a `protected array $mutations` property declaration for the
     * Resource class from the caller-provided mutations spec. Returns an
     * empty string when no spec was passed — the stub then collapses the
     * placeholder to nothing, and the resource inherits the CRUD default
     * performCreate / performUpdate from AbstractRestResource.
     *
     * Accepted shape (mirrors the AbstractRestResource::$mutations docblock):
     *
     *   [
     *     'create' => ['method' => 'POST', 'path' => '/x', 'body_from' => [...], 'body' => [...]],
     *     'update' => [
     *       ['method' => 'POST', 'path' => '/x/{id}/y', 'body' => ['k' => '{col}'], 'when_present' => 'col', 'ignore_status' => [409]],
     *       ...
     *     ],
     *   ]
     */
    private function buildMutationsProperty(mixed $mutations): string
    {
        if (! is_array($mutations) || $mutations === []) {
            return '';
        }

        $normalized = [];

        if (isset($mutations['create']) && is_array($mutations['create'])) {
            /** @var array<string, mixed> $createSpec */
            $createSpec = $mutations['create'];
            $spec = $this->normalizeMutationSpec($createSpec, allowWhen: false);
            if ($spec !== []) {
                $normalized['create'] = $spec;
            }
        }

        if (isset($mutations['update']) && is_array($mutations['update'])) {
            $list = [];
            foreach ($mutations['update'] as $item) {
                if (! is_array($item)) {
                    continue;
                }
                /** @var array<string, mixed> $itemSpec */
                $itemSpec = $item;
                $spec = $this->normalizeMutationSpec($itemSpec, allowWhen: true);
                if ($spec !== []) {
                    $list[] = $spec;
                }
            }
            if ($list !== []) {
                $normalized['update'] = $list;
            }
        }

        if ($normalized === []) {
            return '';
        }

        $body = $this->exportArrayIndented($normalized, 2);

        return <<<PHP


    /**
     * @var array<string, mixed>
     */
    protected array \$mutations = {$body};
PHP;
    }

    /**
     * Normalize an incoming mutation spec — keep only known keys, coerce to
     * safe types. Refuses specs without a usable `path` or `method` so we
     * don't emit a generator-side no-op into user code.
     *
     * @param array<string, mixed> $spec
     *
     * @return array<string, mixed>
     */
    private function normalizeMutationSpec(array $spec, bool $allowWhen): array
    {
        $out = [];

        $method = isset($spec['method']) && is_string($spec['method']) ? strtoupper($spec['method']) : 'POST';
        if (! in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $method = 'POST';
        }
        $out['method'] = $method;

        if (isset($spec['path']) && is_string($spec['path']) && $spec['path'] !== '') {
            $out['path'] = $spec['path'];
        }

        if (isset($spec['body']) && is_array($spec['body'])) {
            $filtered = [];
            foreach ($spec['body'] as $k => $v) {
                if (! is_string($k)) {
                    continue;
                }
                if (is_string($v) || is_int($v) || is_float($v) || is_bool($v) || $v === null) {
                    $filtered[$k] = $v;
                }
            }
            if ($filtered !== []) {
                $out['body'] = $filtered;
            }
        }

        if (isset($spec['body_from']) && is_array($spec['body_from'])) {
            $cols = [];
            foreach ($spec['body_from'] as $col) {
                if (is_string($col) && preg_match('/^[a-zA-Z_]\w*$/', $col)) {
                    $cols[] = $col;
                }
            }
            if ($cols !== []) {
                $out['body_from'] = $cols;
            }
        }

        if ($allowWhen && isset($spec['when_present']) && is_string($spec['when_present']) && $spec['when_present'] !== '') {
            $out['when_present'] = $spec['when_present'];
        }

        if (isset($spec['ignore_status']) && is_array($spec['ignore_status'])) {
            $codes = [];
            foreach ($spec['ignore_status'] as $code) {
                if (is_int($code) && $code >= 100 && $code < 600) {
                    $codes[] = $code;
                }
            }
            if ($codes !== []) {
                $out['ignore_status'] = $codes;
            }
        }

        return $out;
    }

    /**
     * Pretty-print a PHP array literal with given base indent (in 4-space
     * units). Used for the $mutations property so the generated file stays
     * human-readable.
     *
     * @param array<int|string, mixed> $value
     */
    private function exportArrayIndented(array $value, int $indentUnits): string
    {
        $pad = str_repeat('    ', $indentUnits);
        $inner = str_repeat('    ', $indentUnits + 1);

        if ($value === []) {
            return "[\n".$pad.']';
        }

        $isList = array_is_list($value);
        $parts = [];

        foreach ($value as $k => $v) {
            $prefix = $isList ? '' : "'".addslashes((string) $k)."' => ";

            if (is_array($v)) {
                $parts[] = $inner.$prefix.$this->exportArrayIndented($v, $indentUnits + 1);
            } elseif (is_string($v)) {
                $parts[] = $inner.$prefix."'".addslashes($v)."'";
            } elseif (is_bool($v)) {
                $parts[] = $inner.$prefix.($v ? 'true' : 'false');
            } elseif ($v === null) {
                $parts[] = $inner.$prefix.'null';
            } elseif (is_scalar($v)) {
                $parts[] = $inner.$prefix.$v;
            } else {
                // Non-scalar, non-array values get a PHP-literal null to keep
                // the emitted code syntactically valid.
                $parts[] = $inner.$prefix.'null';
            }
        }

        return "[\n".implode(",\n", $parts).",\n".$pad.']';
    }

    /**
     * Emit both halves of the menu icon wiring:
     *   - `use MoonShine\Support\Attributes\Icon;` import (when the
     *     icon was requested — we don't force an unused import otherwise).
     *   - `#[Icon('heroicon-name')]` attribute directly above the class
     *     declaration.
     *
     * MoonShine's MenuItem reads the attribute via reflection, so the
     * native sidebar machinery picks it up without any extra wiring
     * on the resource class. Heroicons (outline) is the default set;
     * MoonShine accepts a trailing `true` + path arg for raw SVGs but
     * we don't expose that yet — keeps the MCP surface tight.
     *
     * Returns [useStatement, attribute] as two strings. Both empty when
     * no icon was requested, which collapses the stub's dedicated lines
     * into blank lines, which keeps the stub layout identical with and
     * without an icon.
     *
     * @return array{0: string, 1: string}
     */
    private function buildIconAttribute(mixed $icon): array
    {
        if (! is_string($icon) || $icon === '') {
            return ['', ''];
        }

        // Keep the icon name flexible — heroicon slugs use kebab-case,
        // but custom icon sets may legitimately contain underscores or
        // slashes (path-style). Drop anything with quotes / newlines
        // because it would escape out of the emitted attribute literal.
        if (preg_match('/["\r\n]/', $icon) === 1) {
            return ['', ''];
        }

        $escaped = str_replace(['\\', "'"], ['\\\\', "\\'"], $icon);

        return [
            'use MoonShine\\Support\\Attributes\\Icon;',
            "#[Icon('{$escaped}')]",
        ];
    }

    /**
     * Emit a `getGroup(): ?string` method body only when a group label
     * is actually provided. An empty / null spec returns no method —
     * MultiPanelRestResource's base `getGroup()` returns null, so the
     * resource ends up as a flat menu entry under its panel. Passing
     * a string produces a classic grouped entry (`MenuGroup::make("X")`
     * wraps the item at panelMenu() time).
     *
     * Backslash + single quote are escaped so a label containing them
     * doesn't break the emitted PHP. Non-string input is treated as
     * "no group" rather than erroring out — keeps the generator lenient
     * against loose MCP input.
     */
    private function buildGroupMethod(mixed $group): string
    {
        if (! is_string($group) || $group === '') {
            return '';
        }

        $literal = str_replace(['\\', "'"], ['\\\\', "\\'"], $group);

        return <<<PHP


    public function getGroup(): ?string
    {
        return '{$literal}';
    }
PHP;
    }

    /**
     * Build resource-level detail_strategy override methods.
     *
     * Only emits code when the caller actually opted into a resource-level
     * override — keeps service-level defaults intact for every other
     * resource that didn't ask for special treatment. Supports:
     *
     *  - `detail_strategy: "endpoint" | "list"` → emits
     *    `getDetailStrategyOverride()` returning the literal.
     *  - `detail_list_key_param: "<name>"` → emits
     *    `getDetailListKeyParamOverride()` returning the literal.
     *
     * Both methods are present on AbstractRestResource with `return null`
     * defaults, so we override only when needed.
     */
    private function buildDetailStrategyOverride(mixed $strategy, mixed $keyParam): string
    {
        $parts = [];

        if (is_string($strategy) && in_array($strategy, ['endpoint', 'list'], true)) {
            $parts[] = <<<PHP


    protected function getDetailStrategyOverride(): ?string
    {
        return '{$strategy}';
    }
PHP;
        }

        if (is_string($keyParam) && $keyParam !== '' && preg_match('/^[a-zA-Z_]\w*$/', $keyParam)) {
            $parts[] = <<<PHP


    protected function getDetailListKeyParamOverride(): ?string
    {
        return '{$keyParam}';
    }
PHP;
        }

        return implode('', $parts);
    }

    /**
     * Build the form page's top-level layout string.
     *
     * This is the code that goes between `return [` and `];` in the
     * generated FormPage::fields() method — i.e. one or more component
     * entries (Box / Grid / Tabs / …) already indented to fit at 12 spaces
     * (inside `return [ <here> ];` in the stub, which sits at method body
     * indent +4).
     *
     * Behavior:
     *  - `$layoutSpec === null` → a single `Box::make([...all fields...])`.
     *    Identical to the pre-preset output; zero caller migration needed.
     *  - `$layoutSpec['type'] === 'tabs'` → `Tabs::make([Tab::make(label,
     *    [Box::make([ group-fields ])]), ...])`. Unlisted columns are
     *    appended to the last tab so nothing silently disappears.
     *  - `$layoutSpec['type'] === 'two-column'` → `Grid::make([Column::make(
     *    [Box::make([ group-fields ])], span), ...])`. `span` on each group
     *    defaults to 6 (equal halves); total should sum to 12.
     *  - `$layoutSpec['type'] === 'box'` or unknown → falls back to default.
     *
     * Imports needed by the preset (Grid / Column / Tabs / Tab) are added
     * to `$outImports` as `[fqcn => alias]` so the caller can merge them
     * into the form page's use-statement set.
     *
     * @param string $flatFieldsCode All field lines joined with "\n", one field per line with trailing comma. Default fallback.
     * @param array<string, string> $byColumn column => single field line (with trailing comma), as returned by buildFields.
     * @param array<string, mixed>|null $layoutSpec
     * @param array<string, string> $outImports
     */
    private function buildFormLayout(string $flatFieldsCode, array $byColumn, mixed $layoutSpec, array &$outImports): string
    {
        // Recursive AST: an arbitrary nested tree of Grid / Column / Box / Tabs /
        // Collapse nodes with `fields` leaves. Wins over `type`/`groups` presets
        // when present. Columns not mentioned anywhere in the tree are appended
        // after it in a trailing Box so no field silently vanishes.
        if (is_array($layoutSpec) && is_array($layoutSpec['tree'] ?? null)) {
            /** @var array<string, mixed> $tree */
            $tree = $layoutSpec['tree'];

            return $this->buildLayoutTreeRoot($tree, $byColumn, $outImports);
        }

        $type = is_array($layoutSpec) && is_string($layoutSpec['type'] ?? null)
            ? $layoutSpec['type']
            : 'box';

        /** @var list<mixed> $groups */
        $groups = is_array($layoutSpec['groups'] ?? null) ? array_values($layoutSpec['groups']) : [];

        if ($type === 'tabs' && $groups !== []) {
            return $this->buildTabsLayout($groups, $byColumn, $outImports);
        }

        if ($type === 'two-column' && $groups !== []) {
            return $this->buildTwoColumnLayout($groups, $byColumn, $outImports);
        }

        // Default: single Box wrapping every field (original behavior).
        return $this->wrapLinesInBox($flatFieldsCode);
    }

    /**
     * Render the user-provided layout tree from the form layer (12-space indent —
     * matches the contract the form-page stub expects for `{{ formLayout }}`).
     *
     * Unclaimed columns are appended after the tree in an auto-Box so the generator
     * preserves the "no field silently dropped" invariant.
     *
     * @param array<string, mixed> $tree Root node of the tree.
     * @param array<string, string> $byColumn Column → rendered field line (12-indent).
     * @param array<string, string> $outImports use-statement accumulator.
     */
    private function buildLayoutTreeRoot(array $tree, array $byColumn, array &$outImports): string
    {
        $claimed = [];
        $rendered = $this->buildLayoutNode($tree, $byColumn, $outImports, 12, $claimed);

        $unclaimed = array_diff(array_keys($byColumn), array_keys($claimed))
            |> array_values(...);
        if ($unclaimed === []) {
            return $rendered;
        }

        Log::info('ResourceGenerator: layout tree left columns unclaimed — appending fallback Box', [
            'columns' => $unclaimed,
        ]);

        $fallbackLines = $this->renderGroupLines($unclaimed, $byColumn, 16);

        return $rendered."\n            Box::make([\n{$fallbackLines}\n            ]),";
    }

    /**
     * Render one node of the layout AST at the given indent. Recursive.
     *
     * @param array<string, mixed> $node
     * @param array<string, string> $byColumn
     * @param array<string, string> $outImports
     * @param array<string, true> $claimed Map of column → true once rendered (in/out).
     */
    private function buildLayoutNode(array $node, array $byColumn, array &$outImports, int $indent, array &$claimed): string
    {
        $type = is_string($node['type'] ?? null) ? $node['type'] : 'box';
        $pad = str_repeat(' ', $indent);

        if ($type === 'fields') {
            $columns = is_array($node['columns'] ?? null) ? $node['columns'] : [];
            $validColumns = [];
            foreach ($columns as $col) {
                if (is_string($col) && isset($byColumn[$col])) {
                    $validColumns[] = $col;
                    $claimed[$col] = true;
                }
            }

            return $this->renderGroupLines($validColumns, $byColumn, $indent);
        }

        $children = is_array($node['children'] ?? null) ? $node['children'] : [];
        $renderedChildren = [];
        foreach ($children as $child) {
            if (is_array($child)) {
                /** @var array<string, mixed> $child */
                $renderedChildren[] = $this->buildLayoutNode($child, $byColumn, $outImports, $indent + 4, $claimed);
            }
        }
        $inner = implode("\n", array_filter($renderedChildren, static fn (string $s): bool => $s !== ''));

        return match ($type) {
            'grid' => $this->wrapContainer($outImports, Grid::class, 'Grid', $inner, $pad),
            'column' => $this->wrapColumn($outImports, $inner, $pad, $node),
            'tabs' => $this->wrapContainer($outImports, Tabs::class, 'Tabs', $inner, $pad),
            'tab' => $this->wrapLabeled($outImports, Tab::class, 'Tab', $inner, $pad, $node),
            'collapse' => $this->wrapLabeled($outImports, Collapse::class, 'Collapse', $inner, $pad, $node),
            // Box is pre-imported by the form-page stub — emit inline without adding
            // to outImports (would duplicate the `use` line and break PHP syntax).
            default => "{$pad}Box::make([\n{$inner}\n{$pad}]),",
        };
    }

    /**
     * @param array<string, string> $outImports
     */
    private function wrapContainer(array &$outImports, string $fqcn, string $short, string $inner, string $pad): string
    {
        $outImports[$fqcn] = $short;

        return "{$pad}{$short}::make([\n{$inner}\n{$pad}]),";
    }

    /**
     * @param array<string, string> $outImports
     * @param array<string, mixed> $node
     */
    private function wrapLabeled(array &$outImports, string $fqcn, string $short, string $inner, string $pad, array $node): string
    {
        $outImports[$fqcn] = $short;
        $label = is_string($node['label'] ?? null) && $node['label'] !== ''
            ? $this->escapePhpString($node['label'])
            : 'Section';

        return "{$pad}{$short}::make('{$label}', [\n{$inner}\n{$pad}]),";
    }

    /**
     * @param array<string, string> $outImports
     * @param array<string, mixed> $node
     */
    private function wrapColumn(array &$outImports, string $inner, string $pad, array $node): string
    {
        $outImports[Column::class] = 'Column';
        $span = is_int($node['span'] ?? null) && $node['span'] > 0 && $node['span'] <= 12
            ? $node['span']
            : null;

        $suffix = $span !== null ? "->columnSpan({$span})" : '';

        return "{$pad}Column::make([\n{$inner}\n{$pad}]){$suffix},";
    }

    /**
     * Wrap a block of already-rendered field lines in a single
     * `Box::make([ ... ])` at the fields()-return indent. Used by the
     * default (no preset) path and by relation-resource pages.
     */
    private function wrapLinesInBox(string $lines): string
    {
        // 16-space indent: fields() body is 8 (method body), inside return [
        // is 12, inside Box::make([...]) is 16. The input lines already have
        // 12 (from buildFields). Shift by +4 to reach 16, then wrap.
        $shifted = $this->indentLines($lines, 4);

        return "            Box::make([\n{$shifted}\n            ]),";
    }

    /**
     * @param array<int, mixed> $groups
     * @param array<string, string> $byColumn
     * @param array<string, string> $outImports
     */
    private function buildTabsLayout(array $groups, array $byColumn, array &$outImports): string
    {
        $outImports[Tabs::class] = 'Tabs';
        $outImports[Tab::class] = 'Tab';

        [$groupAssignments, $remaining] = $this->partitionColumnsByGroups($groups, $byColumn);

        $tabBodies = [];
        $lastIndex = count($groupAssignments) - 1;
        foreach ($groupAssignments as $i => $assignment) {
            $columns = $assignment['columns'];
            // Spill leftovers into the last tab so nothing silently drops.
            if ($i === $lastIndex) {
                $columns = array_merge($columns, $remaining);
            }

            $label = $this->escapePhpString($assignment['label']);
            $boxBody = $this->renderGroupLines($columns, $byColumn, 24);

            $tabBodies[] = "                Tab::make('{$label}', [\n                    Box::make([\n{$boxBody}\n                    ]),\n                ]),";
        }

        $inner = implode("\n", $tabBodies);

        return "            Tabs::make([\n{$inner}\n            ]),";
    }

    /**
     * @param array<int, mixed> $groups
     * @param array<string, string> $byColumn
     * @param array<string, string> $outImports
     */
    private function buildTwoColumnLayout(array $groups, array $byColumn, array &$outImports): string
    {
        $outImports[Grid::class] = 'Grid';
        $outImports[Column::class] = 'Column';

        [$groupAssignments, $remaining] = $this->partitionColumnsByGroups($groups, $byColumn);

        $colBodies = [];
        $lastIndex = count($groupAssignments) - 1;
        foreach ($groupAssignments as $i => $assignment) {
            $columns = $assignment['columns'];
            if ($i === $lastIndex) {
                $columns = array_merge($columns, $remaining);
            }

            $span = is_int($assignment['span']) && $assignment['span'] > 0 && $assignment['span'] <= 12
                ? $assignment['span']
                : 6;
            $boxBody = $this->renderGroupLines($columns, $byColumn, 24);

            $colBodies[] = "                Column::make([\n                    Box::make([\n{$boxBody}\n                    ]),\n                ])->columnSpan({$span}),";
        }

        $inner = implode("\n", $colBodies);

        return "            Grid::make([\n{$inner}\n            ]),";
    }

    /**
     * Walk the groups spec and resolve which columns go where. Returns
     * both the per-group assignment and the list of columns that were not
     * mentioned anywhere (the caller appends these to the last group so
     * they are not silently lost).
     *
     * @param array<int, mixed> $groups
     * @param array<string, string> $byColumn
     *
     * @return array{0: list<array{label: string, columns: list<string>, span: int|null}>, 1: list<string>}
     */
    private function partitionColumnsByGroups(array $groups, array $byColumn): array
    {
        $claimed = [];
        $assignments = [];

        foreach ($groups as $i => $group) {
            if (! is_array($group)) {
                continue;
            }

            $label = is_string($group['label'] ?? null) && $group['label'] !== ''
                ? $group['label']
                : 'Section '.($i + 1);
            $columns = is_array($group['columns'] ?? null) ? $group['columns'] : [];
            $span = is_int($group['span'] ?? null) ? $group['span'] : null;

            $validColumns = [];
            foreach ($columns as $col) {
                if (! is_string($col)) {
                    continue;
                }
                if (! isset($byColumn[$col])) {
                    Log::warning('ResourceGenerator: layout references unknown column', [
                        'column' => $col,
                        'group' => $label,
                    ]);
                    continue;
                }
                $validColumns[] = $col;
                $claimed[$col] = true;
            }

            $assignments[] = [
                'label' => $label,
                'columns' => $validColumns,
                'span' => $span,
            ];
        }

        if ($assignments === []) {
            // Caller checked for non-empty groups already, but guard anyway.
            $assignments[] = ['label' => 'Section 1', 'columns' => [], 'span' => null];
        }

        $remaining = array_diff(array_keys($byColumn), array_keys($claimed))
            |> array_values(...);

        return [$assignments, $remaining];
    }

    /**
     * @param list<string> $columns
     * @param array<string, string> $byColumn
     */
    private function renderGroupLines(array $columns, array $byColumn, int $indent): string
    {
        $lines = [];
        $pad = str_repeat(' ', $indent);

        foreach ($columns as $col) {
            if (! isset($byColumn[$col])) {
                continue;
            }

            // buildFields emitted each line at 12-space indent. Strip the
            // leading 12 and re-pad to the group's indent so the preset
            // wrappers look clean regardless of caller depth.
            $rawLine = ltrim($byColumn[$col]);
            $lines[] = $pad.$rawLine;
        }

        return implode("\n", $lines);
    }

    private function escapePhpString(string $value): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }

    /**
     * Normalise a field's `methods` spec into three buckets:
     *  - shared:      always applied (both create and update)
     *  - create-only: applied when the form is creating a new record
     *  - update-only: applied when the form is editing an existing record
     *
     * Accepted input shapes:
     *  - Flat list (back-compat): `["required", "sortable", {"name": "...", "args": [...]}]`
     *    → everything goes into `shared`.
     *  - Per-mode map: `{"create": ["required"], "update": ["sortable"]}`
     *    → methods present in both go into `shared` (compared by name),
     *    the rest go into the appropriate `*Only` bucket.
     *
     * Each bucket entry is `['name' => string, 'args' => phpLiteralString]`,
     * already sanitised and validated against the target field class — the
     * caller can concatenate them into `->name(args)` without extra checks.
     *
     * @param class-string $fieldClass Concrete MoonShine\UI\Fields\* class (or subclass).
     *
     * @return array{0: list<array{name: string, args: string}>, 1: list<array{name: string, args: string}>, 2: list<array{name: string, args: string}>}
     */
    private function normaliseFieldMethods(mixed $methods, string $fieldClass): array
    {
        if (! is_array($methods) || $methods === []) {
            return [[], [], []];
        }

        // Per-mode split: object-shaped map with {create, update} keys.
        $topKeys = array_keys($methods);
        $isSplit = array_diff($topKeys, ['create', 'update']) === [];

        if (! $isSplit) {
            return [$this->sanitiseMethodList($methods, $fieldClass), [], []];
        }

        $createList = $this->sanitiseMethodList(
            is_array($methods['create'] ?? null) ? $methods['create'] : [],
            $fieldClass,
        );
        $updateList = $this->sanitiseMethodList(
            is_array($methods['update'] ?? null) ? $methods['update'] : [],
            $fieldClass,
        );

        // Intersection by name — a method present in both becomes shared.
        // Ties on args: we take the create-side entry (consistent, predictable).
        $createByName = [];
        foreach ($createList as $m) {
            $createByName[$m['name']] = $m;
        }
        $updateByName = [];
        foreach ($updateList as $m) {
            $updateByName[$m['name']] = $m;
        }

        $shared = [];
        $createOnly = [];
        $updateOnly = [];

        foreach ($createList as $m) {
            if (isset($updateByName[$m['name']])) {
                // Preserve insertion order by using the create-side entry.
                $shared[$m['name']] ??= $m;
            } else {
                $createOnly[] = $m;
            }
        }
        foreach ($updateList as $m) {
            if (! isset($createByName[$m['name']])) {
                $updateOnly[] = $m;
            }
        }

        return [array_values($shared), $createOnly, $updateOnly];
    }

    /**
     * Sanitise and validate a flat list of method entries. Drops unknowns
     * (e.g. MoonShine v2/v3 legacy helpers like hideOnForm) with a log line.
     *
     * @param array<int|string, mixed> $rawMethods
     * @param class-string $fieldClass
     *
     * @return list<array{name: string, args: string}>
     */
    private function sanitiseMethodList(array $rawMethods, string $fieldClass): array
    {
        $out = [];

        foreach ($rawMethods as $method) {
            $name = '';
            $args = '';

            if (is_string($method)) {
                $name = $method;
            } elseif (is_array($method) && isset($method['name']) && is_string($method['name'])) {
                $name = $method['name'];

                if (isset($method['args']) && is_array($method['args'])) {
                    $argParts = [];

                    foreach ($method['args'] as $arg) {
                        if (is_string($arg)) {
                            $argParts[] = "'".addslashes($arg)."'";
                        } elseif (is_bool($arg)) {
                            $argParts[] = $arg ? 'true' : 'false';
                        } elseif ($arg === null) {
                            $argParts[] = 'null';
                        } elseif (is_int($arg) || is_float($arg)) {
                            $argParts[] = (string) $arg;
                        } elseif (is_array($arg)) {
                            $argParts[] = $this->phpExportShort($arg);
                        }
                        // Silently drop objects/resources — we can't render
                        // them as PHP literals anyway.
                    }

                    $args = implode(', ', $argParts);
                }
            }

            // Skip anything we couldn't resolve to a valid identifier. This
            // prevents the "Select is not callable" bug where an empty or
            // malformed method entry produced a bare `()` that PHP tried to
            // invoke on the previous expression's result.
            if (! preg_match('/^[a-zA-Z_]\w*$/', $name)) {
                continue;
            }

            if (! self::isFieldMethodAllowed($name)) {
                throw new InvalidArgumentException("Field method [{$name}] is not available for hosted generation.");
            }

            // Validate against the concrete field class so we don't emit
            // legacy MoonShine v2/v3 methods that the agent picked up from
            // old docs (hideOnForm, hideOnCreate, hideOnUpdate…).
            if (! method_exists($fieldClass, $name)) {
                Log::warning('ResourceGenerator: dropping unknown field method', [
                    'field_class' => $fieldClass,
                    'method' => $name,
                    'hint' => 'Method does not exist on the generated field class. Most common cause: legacy v2/v3 helper (e.g. hideOnForm, hideOnCreate) — since MoonShine 4, hide fields per page with the hide_on spec key or page-level fields().',
                ]);
                continue;
            }

            $out[] = ['name' => $name, 'args' => $args];
        }

        return $out;
    }

    /**
     * Emit a method call that only takes effect in one mode (create or update).
     *
     * Preference order:
     *  1. If the target method's FIRST parameter accepts Closure|bool|null
     *     (verified via Reflection), pass the condition directly:
     *     `->required($this->getItem() === null)` — native MoonShine pattern,
     *     plays nicely with any further chained behavior the field has.
     *  2. Otherwise, wrap the call in `->when($condition, fn ($f) => $f->name(args))`.
     *     The Conditionable trait is present on every MoonShineComponent, so
     *     this always works; we only avoid it when option 1 is cleaner.
     *
     * @param class-string $fieldClass
     * @param array{name: string, args: string} $method
     */
    private function buildConditionalMethodCall(string $fieldClass, array $method, string $condition): string
    {
        $name = $method['name'];
        $args = $method['args'];

        if ($args === '' && self::methodAcceptsConditionArg($fieldClass, $name)) {
            return "->{$name}({$condition})";
        }

        // Fall back to when() wrapper — works for any method, always safe.
        $invocation = "\$f->{$name}({$args})";

        return "->when({$condition}, fn (\$f) => {$invocation})";
    }

    /**
     * Does this method's first parameter accept `Closure|bool|null`?
     *
     * Uses ReflectionMethod to inspect the declared parameter type, so we
     * stay correct as MoonShine evolves without maintaining a hand-curated
     * whitelist of method names. Methods like `required`, `disabled`,
     * `readonly`, `nullable`, `multiple`, `vertical`, `searchable`, `tabMode`
     * match; `sortable` (accepts Closure|string) and `eye` (no params) don't.
     *
     * @param class-string $fieldClass
     */
    public static function methodAcceptsConditionArg(string $fieldClass, string $methodName): bool
    {
        try {
            $reflection = new ReflectionMethod($fieldClass, $methodName);
        } catch (ReflectionException) {
            return false;
        }

        $params = $reflection->getParameters();
        if ($params === []) {
            return false;
        }

        $type = $params[0]->getType();
        if (! $type instanceof ReflectionUnionType) {
            return false;
        }

        $names = [];
        foreach ($type->getTypes() as $t) {
            if ($t instanceof ReflectionNamedType) {
                $names[] = $t->getName();
            }
        }

        // Require at least Closure + bool in the union — the canonical
        // MoonShine conditional signature. `null` is optional (present on
        // methods that default to no-op when called without args).
        return in_array('Closure', $names, true) && in_array('bool', $names, true);
    }

    /**
     * Build a `rules(DataWrapperContract $item): array` method body for the
     * Form page stub from the caller-provided rules map.
     *
     * Accepted shapes, in ascending flexibility:
     *   • Flat: `{col: "required|…"}` or `{col: ["required", "uuid"]}` — same
     *     rule for both create and update (back-compat, the original shape).
     *   • Per-column per-mode: `{col: {"create": "…", "update": "…"}}` lets
     *     a single column differ between create and update while every other
     *     column stays shared.
     *   • Top-level split: `{"create": {col: "…"}, "update": {col: "…"}}` —
     *     two fully independent maps.
     *
     * All three shapes are normalised into a pair of create/update maps and
     * the generator emits a rules() method that returns either a single
     * array (when create == update) or branches on `$item->getKey() === null`.
     * The $item getKey() check is the canonical "is this a fresh record"
     * signal in the DataWrapperContract.
     *
     * Returns an empty string if no rules were provided — the stub then
     * falls through to the parent default (no rules() method emitted).
     */
    private function buildRulesMethod(mixed $rules): string
    {
        if (! is_array($rules) || $rules === []) {
            return '';
        }

        [$createRules, $updateRules] = $this->normaliseRulesSpec($rules);

        if ($createRules === [] && $updateRules === []) {
            return '';
        }

        // Collapse to a single-array return when create and update agree —
        // keeps the common case readable and behaviour unchanged from the
        // original flat-rules spec.
        $flatCreate = $this->formatRulesArrayBody($createRules, 12);
        $flatUpdate = $this->formatRulesArrayBody($updateRules, 12);

        if ($flatCreate === $flatUpdate) {
            return <<<PHP


    /**
     * @return array<string, string|list<string>>
     */
    protected function rules(\\MoonShine\\Contracts\\Core\\TypeCasts\\DataWrapperContract \$item): array
    {
        return [
{$flatCreate}
        ];
    }
PHP;
        }

        // Branching body — one extra indent level because the return is
        // nested inside `if (...) { ... }` / the fallthrough `return`.
        $createBody = $this->formatRulesArrayBody($createRules, 16);
        $updateBody = $this->formatRulesArrayBody($updateRules, 12);

        return <<<PHP


    /**
     * @return array<string, string|list<string>>
     */
    protected function rules(\\MoonShine\\Contracts\\Core\\TypeCasts\\DataWrapperContract \$item): array
    {
        // `getKey() === null` is the canonical "fresh record" signal on the
        // DataWrapperContract — the caller split rules per create/update so
        // e.g. `password` stays required on create but can be left blank
        // on edit without wiping the existing value upstream.
        if (\$item->getKey() === null) {
            return [
{$createBody}
            ];
        }

        return [
{$updateBody}
        ];
    }
PHP;
    }

    /**
     * Normalise all accepted `rules` shapes into [createMap, updateMap].
     *
     * @param array<mixed, mixed> $rules
     *
     * @return array{0: array<string, string|array<int, string>>, 1: array<string, string|array<int, string>>}
     */
    private function normaliseRulesSpec(array $rules): array
    {
        $topLevelKeys = array_keys($rules);
        $isTopLevelSplit = $topLevelKeys !== []
            && array_diff($topLevelKeys, ['create', 'update']) === [];

        if ($isTopLevelSplit) {
            return [
                $this->sanitiseRulesMap(is_array($rules['create'] ?? null) ? $rules['create'] : []),
                $this->sanitiseRulesMap(is_array($rules['update'] ?? null) ? $rules['update'] : []),
            ];
        }

        $create = [];
        $update = [];

        foreach ($rules as $column => $rule) {
            if (! is_string($column)) {
                continue;
            }
            if (! preg_match('/^[a-zA-Z_]\w*$/', $column)) {
                continue;
            }
            // Per-column per-mode: {col: {create: "...", update: "..."}}.
            if (is_array($rule) && ! array_is_list($rule)
                && (isset($rule['create']) || isset($rule['update']))) {
                $createRule = $this->sanitiseRule($rule['create'] ?? null);
                $updateRule = $this->sanitiseRule($rule['update'] ?? null);

                if ($createRule !== null) {
                    $create[$column] = $createRule;
                }
                if ($updateRule !== null) {
                    $update[$column] = $updateRule;
                }
                continue;
            }

            $shared = $this->sanitiseRule($rule);
            if ($shared !== null) {
                $create[$column] = $shared;
                $update[$column] = $shared;
            }
        }

        return [$create, $update];
    }

    /**
     * @param array<mixed, mixed> $map
     *
     * @return array<string, string|array<int, string>>
     */
    private function sanitiseRulesMap(array $map): array
    {
        $out = [];
        foreach ($map as $column => $rule) {
            if (! is_string($column)) {
                continue;
            }
            if (! preg_match('/^[a-zA-Z_]\w*$/', $column)) {
                continue;
            }
            $clean = $this->sanitiseRule($rule);
            if ($clean !== null) {
                $out[$column] = $clean;
            }
        }

        return $out;
    }

    /**
     * @return string|array<int, string>|null
     */
    private function sanitiseRule(mixed $rule): string|array|null
    {
        if (is_string($rule) && $rule !== '') {
            HostedValidationRules::assertString($rule);

            return $rule;
        }

        if (is_array($rule) && array_is_list($rule) && $rule !== []) {
            $parts = [];
            foreach ($rule as $r) {
                if (is_string($r) && $r !== '') {
                    HostedValidationRules::assertString($r);
                    $parts[] = $r;
                }
            }

            return $parts === [] ? null : $parts;
        }

        return null;
    }

    /**
     * @param array<string, string|array<int, string>> $map
     */
    private function formatRulesArrayBody(array $map, int $indent): string
    {
        if ($map === []) {
            return '';
        }

        $pad = str_repeat(' ', $indent);
        $lines = [];

        foreach ($map as $column => $rule) {
            if (is_string($rule)) {
                $lines[] = $pad."'{$column}' => '".addslashes($rule)."',";
            } else {
                $parts = [];
                foreach ($rule as $r) {
                    $parts[] = "'".addslashes($r)."'";
                }
                $lines[] = $pad."'{$column}' => [".implode(', ', $parts).'],';
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Add `$spaces` extra spaces to the start of every non-empty line.
     * Used to re-indent generator output when substituted into nested stubs
     * (e.g. form fields inside Box::make([...])).
     */
    private function indentLines(string $text, int $spaces): string
    {
        if ($text === '') {
            return $text;
        }

        $pad = str_repeat(' ', $spaces);

        return preg_replace('/^(?=.)/m', $pad, $text) ?? $text;
    }

    /**
     * @param array<int|string, mixed> $value
     */
    private function phpExportShort(array $value): string
    {
        // List literals (0..n consecutive int keys) emit bare values —
        // `['a', 'b']` reads cleaner than `[0 => 'a', 1 => 'b']`.
        if (\array_is_list($value)) {
            $parts = [];
            foreach ($value as $v) {
                $parts[] = $this->scalarToPhp($v);
            }

            return '['.\implode(', ', $parts).']';
        }

        $parts = [];

        foreach ($value as $k => $v) {
            $key = is_int($k) ? (string) $k : "'".addslashes($k)."'";
            $parts[] = $key.' => '.$this->scalarToPhp($v);
        }

        return '['.implode(', ', $parts).']';
    }

    private function scalarToPhp(mixed $v): string
    {
        if (is_string($v)) {
            return "'".addslashes($v)."'";
        }

        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }

        if ($v === null) {
            return 'null';
        }

        if (is_scalar($v)) {
            return (string) $v;
        }

        return 'null';
    }

    /**
     * Build use statement block from collected classes.
     *
     * @param array<string, string> $useStatements
     */
    private function buildUseStatements(array $useStatements): string
    {
        if ($useStatements === []) {
            return '';
        }

        $lines = [];

        foreach (array_keys($useStatements) as $class) {
            $lines[] = "use {$class};";
        }

        sort($lines);

        return implode("\n", $lines);
    }
}
