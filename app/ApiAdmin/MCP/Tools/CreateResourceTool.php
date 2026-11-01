<?php

declare(strict_types=1);

namespace App\ApiAdmin\MCP\Tools;

use App\ApiAdmin\MCP\Generator\ResourceGenerator;
use App\ApiAdmin\MCP\Generator\ResourceValidator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * MCP Tool: create_resource
 *
 * Generates a REST resource PHP file with configured fields,
 * registers it in the panel menu, and triggers hot-reload.
 */
final class CreateResourceTool implements ToolContract
{
    /**
     * MCP tool schema definition.
     *
     * @return array{
     *     name: string,
     *     description: string,
     *     inputSchema: array{
     *         type: string,
     *         properties: array<string, mixed>,
     *         required: list<string>,
     *     },
     * }
     */
    public static function schema(): array
    {
        return [
            'name' => 'create_resource',
            'description' => 'Generation changes are serialized and rolled back on failure. Create a new REST resource with fields for an existing service. Generates PHP class and MoonShine 5 pages (RestIndexPage wires relation filters), registers in menu, and reloads.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'service' => [
                        'type' => 'string',
                        'description' => 'Service name this resource belongs to (must be registered first)',
                    ],
                    'kind' => [
                        'type' => 'string',
                        'enum' => ['main', 'relation'],
                        'description' => 'Resource kind. "main" — top-level, appears in the service panel menu. "relation" — only reachable through parent has_one/has_many (endpoint must contain {parent_id}). Auto-detected from endpoint when omitted.',
                    ],
                    'name' => [
                        'type' => 'string',
                        'description' => 'Resource name (e.g. "post", "product", "order"). Singular is conventional. Tools resolve the singular and plural forms to the same resource, so "posts" regenerates an existing Post instead of adding a second resource.',
                    ],
                    'endpoint' => [
                        'type' => 'string',
                        'description' => 'API endpoint path (e.g. "/posts", "/api/products")',
                    ],
                    'title' => [
                        'type' => 'string',
                        'description' => 'Display title for the resource (e.g. "Blog Posts")',
                    ],
                    'panel' => [
                        'type' => 'string',
                        'description' => 'Panel identifier (defaults to service-admin)',
                    ],
                    'group' => [
                        'type' => 'string',
                        'description' => 'Optional menu group label for the panel sidebar. Omit (default) for a flat entry directly under the panel — appropriate for single-service panels where an extra inner group would just duplicate the panel title. Pass a string to wrap the menu item in a MenuGroup, which is useful when a single panel hosts resources from multiple domains (e.g. group: "Catalog" for products/categories, group: "Billing" for invoices/payments under one "Shop Admin" panel).',
                    ],
                    'icon' => [
                        'type' => 'string',
                        'description' => 'Optional menu icon. Emits `#[Icon("<name>")]` on the resource class, which MoonShine\'s MenuItem reads via reflection to render the sidebar icon. Uses Heroicons outline slug by default (`users`, `clipboard-document-list`, `tag`, `cube`, `bolt`, etc. — https://heroicons.com/outline). Omit for the MoonShine default icon. Typical per-domain pairing: users → `users`, statuses → `tag`, projects → `briefcase`, tasks → `clipboard-document-list`, clients → `building-office-2`.',
                    ],
                    'fields' => [
                        'type' => 'array',
                        'description' => 'List of field definitions',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'column' => ['type' => 'string', 'description' => 'API field name'],
                                'label' => ['type' => 'string', 'description' => 'Display label'],
                                'type' => [
                                    'type' => 'string',
                                    'enum' => [
                                        'id', 'text', 'textarea', 'number', 'email', 'password',
                                        'date', 'datetime', 'checkbox', 'switcher', 'select',
                                        'image', 'file', 'json', 'color', 'slug', 'url',
                                        'belongs_to', 'has_many', 'has_one', 'belongs_to_many',
                                    ],
                                    'description' => 'Field type. Hosted mode rejects file/image uploads and custom fields; use external URL values for media. The enum lists built-in types; in local mode custom types registered via `create_field` are accepted too — they auto-appear in the catalog at runtime via the `api-admin.custom_fields` config merge inside `ResourceGenerator::fieldTypeCatalog()`. Call `list_fields` to see the live set (built-ins + custom, with `is_custom: true` marking caller-generated types). NOTE: `password` maps to MoonShine\\UI\\Fields\\Password with `->raw()` auto-applied by the generator. `->raw()` disables Hasher::make on apply so the RAW value is sent to the upstream API — the upstream is expected to hash it, and double-hashing would lock users out. Do not add `raw` to `methods` manually; it is always emitted for `type: password`.',
                                ],
                                'methods' => [
                                    'description' => 'Chainable field methods. Two shapes: '
                                        .'(1) FLAT list — `["sortable", "required", "nullable"]` applies to every mode/page (back-compat). '
                                        .'(2) PER-MODE map — `{"create": ["required"], "update": []}` emits different chains for create vs edit on the FormPage. Classic use: `password` gets `->required()` only on the Create page so the asterisk does not lie on Edit (validation is still driven by the `rules` map). '
                                        .'For per-mode methods, the generator uses the native MoonShine conditional form `->required($this->getItem() === null)` when the target method accepts Closure|bool (detected via Reflection — required/disabled/readonly/nullable/multiple/vertical/searchable/tabMode), and falls back to `->when($cond, fn ($f) => $f->method(args))` otherwise (works for any method via the Conditionable trait). On Index/Detail pages the split methods are merged into one unconditional chain — those pages have no create-vs-edit mode. '
                                        .'Hosted mode accepts only the declarative method allowlist returned by list_field_methods; callbacks, custom views, arbitrary attributes and storage operations are rejected. '
                                        .'All method names are validated via reflection against the generated MoonShine\\UI\\Fields\\* class. Unknown names are dropped with a logged warning. DO NOT use legacy v2/v3 visibility helpers (hideOnForm / hideOnCreate / hideOnUpdate / hideOnIndex) — they do not exist in MoonShine 5. To hide a field from a specific page, use the `hide_on` property (see below).',
                                ],
                                'hide_on' => [
                                    'type' => 'array',
                                    'description' => 'Pages to omit this field from. MoonShine 5 has no per-field visibility helpers; hiding is done by not including the field in that page\'s fields() method. The generator honours this by filtering the field out of the corresponding IndexPage/FormPage/DetailPage. Typical use: server-managed columns like `created_at`, `updated_at`, `id` go `hide_on: ["form"]`; surrogate keys go `hide_on: ["form", "index"]`.',
                                    'items' => ['type' => 'string', 'enum' => ['index', 'form', 'detail']],
                                ],
                                'endpoint' => [
                                    'type' => 'string',
                                    'description' => 'API endpoint for relation fields',
                                ],
                                'target_service' => [
                                    'type' => 'string',
                                    'description' => 'Target service name for cross-service relation fields. Omit for same-service relations. When set on belongs_to / belongs_to_many / has_one / has_many, option loading and nested relation actions use this service config and response mapping while the parent resource remains in `service`.',
                                ],
                                'value_key' => [
                                    'type' => 'string',
                                    'description' => 'Value key for relation fields (default: id)',
                                ],
                                'label_key' => [
                                    'type' => 'string',
                                    'description' => 'Label key for relation fields (default: name)',
                                ],
                                'endpoint_query' => [
                                    'type' => 'object',
                                    'description' => 'Static query params attached to every HTTP call this relation field makes (options fetch, has_many list, has_one get). Use for scoped dropdowns that would otherwise 422 — e.g. `endpoint_query: {"for_entity":"project"}` for a status belongs_to where the target API demands a scope. Field-local on purpose: the correct scope can differ per referring resource (status inside ProjectResource vs inside TaskResource). `{col}` placeholders resolve against current form values — e.g. `{"project_id":"{project_id}"}` narrows the scope to the currently-selected project.',
                                ],
                                'depends_on' => [
                                    'type' => 'array',
                                    'items' => ['type' => 'string'],
                                    'description' => 'Sibling column names that drive this relation field\'s options. CREATE-mode cascade: `depends_on: ["project_id"]` on a Status dropdown auto-marks the field reactive so MoonShine re-renders it on every form change — the async-search URL then carries the fresh project_id value and upstream narrows the status list. The driver column (project_id field) MUST also be marked reactive (add `"methods": ["reactive"]` to its field spec), otherwise its change won\'t broadcast and the dependent field will not refresh. Pair with `endpoint_query: {"{col}": "{col}"}` to get the value into the async URL.',
                                ],
                            ],
                            'required' => ['column', 'type'],
                        ],
                    ],
                    'index_fields' => self::pageFieldsSchema('index'),
                    'form_fields' => self::pageFieldsSchema('form'),
                    'detail_fields' => self::pageFieldsSchema('detail'),
                    'search' => [
                        'type' => 'array',
                        'description' => 'Searchable field columns',
                        'items' => ['type' => 'string'],
                    ],
                    'default_query' => [
                        'type' => 'object',
                        'description' => 'Query parameters attached to every index/detail HTTP call. Use this for APIs that require scope params to return anything sensible — e.g. a multi-tenant API that errors with 422 unless `?for_entity=project` is present. Shape: {index: {param: value}, detail: {param: value}}. User-driven filters / sort / search / pagination always override these defaults at runtime — the baked values only ride when nothing user-driven is set for that key. Values may be scalars or lists (repeated query params).',
                        'properties' => [
                            'index' => [
                                'type' => 'object',
                                'description' => 'Params attached to GET /resource (list fetch).',
                            ],
                            'detail' => [
                                'type' => 'object',
                                'description' => 'Params attached to GET /resource/{id} (single-item fetch).',
                            ],
                        ],
                    ],
                    'list_request' => [
                        'type' => 'object',
                        'description' => 'Override how the Index page and fetch_resource_items read the list endpoint. Default is GET with params in query string. Use `{"method":"POST","body":{...}}` for POST-RPC/list/search APIs that require a JSON body. For POST, static `body` is sent first and runtime pagination/search/sort/filter/detail-list params are merged on top. If a static body key is a list (e.g. `{"ids":[]}`) and a runtime detail filter supplies a scalar id, it is wrapped to `{"ids":[id]}`. This only controls list reads; create/update/delete still use `mutations` or REST defaults.',
                        'properties' => [
                            'method' => ['type' => 'string', 'enum' => ['GET', 'POST']],
                            'body' => [
                                'type' => 'object',
                                'description' => 'Static JSON body merged into POST list requests. Values may be scalar or scalar lists.',
                            ],
                        ],
                    ],
                    'actions' => [
                        'type' => 'array',
                        'description' => 'Allowlist of CRUD actions the upstream API genuinely supports. When omitted, MoonShine defaults to all five (create, view, update, delete, mass_delete). Set this explicitly when the API lacks an action — e.g. ["create","view","update"] for an API with no DELETE /tasks/{id} endpoint — so the generated Resource overrides activeActions() and the Delete/MassDelete buttons never render in the UI.',
                        'items' => ['type' => 'string', 'enum' => ['create', 'view', 'update', 'delete', 'mass_delete']],
                    ],
                    'mutations' => [
                        'type' => 'object',
                        'description' => 'Declarative CQRS mutation routing. Use when the upstream API does not expose plain POST/PUT /resource/{id} — e.g. mutations split across dedicated endpoints like PUT /tasks/{id}/assignee and POST /tasks/{id}/status-transitions. Generator emits a $mutations property on the Resource class; AbstractRestResource::performCreate/performUpdate read it at runtime. Placeholders in `path`/`body` strings are {column_name} tokens substituted from the submitted form payload (+{id} for update).',
                        'properties' => [
                            'create' => [
                                'type' => 'object',
                                'description' => 'Routing for the create mutation. Sent as a single HTTP request.',
                                'properties' => [
                                    'method' => ['type' => 'string', 'enum' => ['POST', 'PUT', 'PATCH']],
                                    'path' => ['type' => 'string', 'description' => 'Defaults to endpoint(). {column} placeholders interpolate form data.'],
                                    'body_from' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Subset of form columns to include in the request body. Mutually exclusive with `body`.'],
                                    'body' => ['type' => 'object', 'description' => 'Literal body template. Values may reference {column} from the form payload.'],
                                ],
                            ],
                            'update' => [
                                'type' => 'array',
                                'description' => 'Ordered list of mutations dispatched on save. After all succeed the base resource re-fetches the item for canonical state. Use `when_present` to skip a mutation when its column is absent from the changeset.',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'method' => ['type' => 'string', 'enum' => ['POST', 'PUT', 'PATCH', 'DELETE']],
                                        'path' => ['type' => 'string', 'description' => '{id} → item key, {column} → form value.'],
                                        'body' => ['type' => 'object', 'description' => 'Literal body template with {column} interpolation.'],
                                        'body_from' => ['type' => 'array', 'items' => ['type' => 'string']],
                                        'when_present' => ['type' => 'string', 'description' => 'Skip this mutation if the named column is not in the submitted payload.'],
                                        'ignore_status' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'HTTP status codes to treat as success (e.g. [409] for "already in that state").'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'layout' => [
                        'type' => 'object',
                        'description' => 'Page layout preset. Currently only `form` is supported; Index/Detail pages have their own natural layouts.'
                            .' Default (omit the param) wraps all form fields in a single `Box::make([...])` — identical to pre-preset output. Three presets:'
                            .' (1) `box` — same as default (explicit); (2) `two-column` — `Grid::make([Column::make([Box::make([...group fields...])])->columnSpan($span)…])` for dense layouts;'
                            .' (3) `tabs` — `Tabs::make([Tab::make(label, [Box::make([...group fields...])])…])` for long forms split by concern (profile / access / settings).'
                            .' Each group: `{"label": "Profile", "columns": ["first_name", "last_name"], "span": 6}`. `span` applies only to two-column (defaults to 6; group spans should sum to 12).'
                            .' Columns not listed in ANY group spill into the LAST group so the caller can\'t silently drop fields.'
                            .' For complex nested layouts (Grid inside Column inside Tabs inside Collapse), use `layout.form.tree` — a recursive AST whose nodes are `{type: "box"|"grid"|"column"|"tabs"|"tab"|"collapse", children: [...]}` containers and `{type: "fields", columns: [...]}` leaves. Columns not referenced in any `fields` leaf are appended after the tree in an auto-Box. Tree wins over `type`/`groups` when both are supplied.',
                        'properties' => [
                            'form' => [
                                'type' => 'object',
                                'properties' => [
                                    'type' => [
                                        'type' => 'string',
                                        'enum' => ['box', 'two-column', 'tabs'],
                                    ],
                                    'groups' => [
                                        'type' => 'array',
                                        'items' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'label' => ['type' => 'string'],
                                                'columns' => ['type' => 'array', 'items' => ['type' => 'string']],
                                                'span' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 12, 'description' => 'two-column only'],
                                            ],
                                            'required' => ['columns'],
                                        ],
                                    ],
                                    'tree' => [
                                        'type' => 'object',
                                        'description' => 'Root node of the recursive layout AST. Container nodes: type ∈ {"box","grid","column","tabs","tab","collapse"} with `children: [...]`. Leaf node: {"type":"fields","columns":["col1","col2"]}. `tab`/`collapse` read `label`; `column` accepts optional `span` (1–12). Example: {"type":"grid","children":[{"type":"column","span":8,"children":[{"type":"box","children":[{"type":"fields","columns":["title","body"]}]}]},{"type":"column","span":4,"children":[{"type":"tabs","children":[{"type":"tab","label":"Meta","children":[{"type":"fields","columns":["slug","published_at"]}]}]}]}]}',
                                        'properties' => [
                                            'type' => ['type' => 'string'],
                                            'label' => ['type' => 'string'],
                                            'span' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 12],
                                            'columns' => ['type' => 'array', 'items' => ['type' => 'string']],
                                            'children' => ['type' => 'array'],
                                        ],
                                        'required' => ['type'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'detail_strategy' => [
                        'type' => 'string',
                        'enum' => ['endpoint', 'list'],
                        'description' => 'Resource-level override for the service\'s `detail_strategy`. Use when one resource in the service needs a different single-item fetch than the rest — classic case: a Laravel service where most resources have `GET /{id}` (service-level `endpoint`) but one or two lookup tables (statuses, tags, categories) expose only a list + CUD endpoints (`GET /statuses` + `PUT /statuses/{id}`, no `GET /statuses/{id}`). Set `detail_strategy: "list"` on JUST the lookup resource — siblings keep their service-level endpoint strategy. Omit (default) to inherit service-level config. Generator emits `getDetailStrategyOverride()` on the Resource class; AbstractRestResource::findItem() honors it before falling back to service default.',
                    ],
                    'detail_list_key_param' => [
                        'type' => 'string',
                        'description' => 'Resource-level override for `detail_list_key_param` — the query-param name used to narrow the list endpoint to a single row when `detail_strategy="list"`. Defaults to the resource\'s primary_key when omitted. Use when one resource\'s list filter differs from the service convention (e.g. most resources use `?id=7` but a legacy one uses `?status_id=7`).',
                    ],
                    'rules' => [
                        'type' => 'object',
                        'description' => 'Validation rules for the Form page. Hosted mode accepts only these local data rules: '.implode(', ', ResourceGenerator::hostedValidationRules()).'. Email modes are restricted to rfc/filter/filter_unicode; database, custom, regex, file, password and network rules are rejected. Three accepted shapes:'
                            .' (1) FLAT: `{col: "required|email"}` or `{col: ["required", "uuid"]}` — same rule applied to both create and update (original shape, fully back-compat).'
                            .' (2) PER-COLUMN PER-MODE: `{col: {"create": "required|...", "update": "nullable|..."}}` — a single column differs between create and update, every other column stays shared. Classic use: `password` required on create, nullable on edit so leaving the field blank does not wipe the upstream value.'
                            .' (3) TOP-LEVEL SPLIT: `{"create": {col: "..."}, "update": {col: "..."}}` — two fully independent maps.'
                            .' The generator normalises all three shapes, then emits either a single-array rules() (when create == update) or a rules() that branches on `$item->getKey() === null` (canonical "fresh record" signal on DataWrapperContract).',
                    ],
                    'filters' => [
                        'type' => 'array',
                        'description' => 'Filter field definitions on the index page. Same shape as `fields[]`: `column` + `type` required, plus type-specific extras. For relation-type filters (belongs_to, belongs_to_many) you MUST pass `endpoint` (and optionally `value_key` / `label_key` / `endpoint_query`) — otherwise the options dropdown loads against an empty endpoint and renders blank even when the upstream has data. For `select` filters, pass a static `options` map. This mirrors the `fields[]` spec so relation filters work out of the box.',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'column' => ['type' => 'string'],
                                'label' => ['type' => 'string'],
                                'type' => ['type' => 'string'],
                                'options' => [
                                    'type' => 'object',
                                    'description' => 'Static options for `select` filters (key-value map).',
                                ],
                                'endpoint' => [
                                    'type' => 'string',
                                    'description' => 'API endpoint for relation filters — required for belongs_to / belongs_to_many filters, otherwise the dropdown is silently empty.',
                                ],
                                'target_service' => [
                                    'type' => 'string',
                                    'description' => 'Target service name for cross-service relation filters. Omit for same-service filters. When set, filter option loading uses this service config and response mapping.',
                                ],
                                'value_key' => [
                                    'type' => 'string',
                                    'description' => 'Value key for relation filters (default: id).',
                                ],
                                'label_key' => [
                                    'type' => 'string',
                                    'description' => 'Label key for relation filters (default: name).',
                                ],
                                'endpoint_query' => [
                                    'type' => 'object',
                                    'description' => 'Same semantics as fields[].endpoint_query — static query params (optionally with {col} placeholders) attached to the filter options fetch.',
                                ],
                            ],
                        ],
                    ],
                ],
                'required' => ['service', 'name', 'endpoint', 'fields'],
            ],
        ];
    }

    /**
     * Schema fragment for the optional per-page `<page>_fields[]` arrays
     * (index_fields / form_fields / detail_fields). Same shape as a
     * field entry — when present and non-empty, REPLACES the canonical
     * `fields[]` for that page (hide_on becomes irrelevant for that
     * page). Use when index/form/detail need genuinely different field
     * sets that subtraction via hide_on can't express.
     *
     * @return array<string, mixed>
     */
    private static function pageFieldsSchema(string $page): array
    {
        return [
            'type' => 'array',
            'description' => "Optional override of the field list for the {$page} page. When present and non-empty, replaces the canonical fields[] for that page — hide_on stops applying. Each entry is the same shape as fields[] (column + type required). Use for genuinely different per-page sets; for simple subtraction prefer fields[].hide_on.",
            'items' => [
                'type' => 'object',
                'properties' => [
                    'column' => ['type' => 'string'],
                    'type' => ['type' => 'string'],
                    'label' => ['type' => 'string'],
                    'methods' => ['description' => 'Same as fields[].methods (flat list or per-mode map).'],
                    'endpoint' => ['type' => 'string', 'description' => 'Same as fields[].endpoint for relation fields.'],
                    'target_service' => ['type' => 'string', 'description' => 'Same as fields[].target_service for cross-service relation fields.'],
                    'value_key' => ['type' => 'string', 'description' => 'Same as fields[].value_key for relation fields.'],
                    'label_key' => ['type' => 'string', 'description' => 'Same as fields[].label_key for relation fields.'],
                    'endpoint_query' => ['type' => 'object', 'description' => 'Same as fields[].endpoint_query for relation fields.'],
                    'depends_on' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Same as fields[].depends_on for cascade relation fields.'],
                ],
                'required' => ['column', 'type'],
            ],
        ];
    }

    /**
     * Execute the create_resource tool.
     *
     * @param array<string, mixed> $params Raw JSON-RPC payload; shape validated by ResourceGenerator.
     *
     * @return array<string, mixed>
     */
    public function execute(array $params): array
    {
        $fields = $params['fields'] ?? [];
        Log::info('CreateResourceTool: executing', [
            'service' => $params['service'] ?? null,
            'name' => $params['name'] ?? null,
            'endpoint' => $params['endpoint'] ?? null,
            'fields_count' => is_array($fields) ? count($fields) : 0,
        ]);

        // Step 1: Generate the resource file
        $generator = new ResourceGenerator;
        $result = $generator->generate($params);

        // Step 2: Trigger hot-reload (in-process cache clear — no slow composer step)
        Log::debug('CreateResourceTool: triggering reload');

        try {
            Artisan::call('api-admin:reload');

            Log::info('CreateResourceTool: reload completed');
        } catch (Throwable $e) {
            Log::warning('CreateResourceTool: reload failed (non-blocking)', [
                'error' => $e->getMessage(),
            ]);
        }

        // Step 3: Post-generation validation — surfaces problems to the
        // agent immediately (syntax errors, autoload failures, missing
        // required methods) instead of waiting for the user to hit 500
        // in the browser.
        $validation = (new ResourceValidator)->validate($result);

        $className = $result['className'];
        $file = $result['file'];

        Log::info('CreateResourceTool: completed', [
            'file' => $file,
            'class' => $result['class'],
            'validation_ok' => $validation['ok'],
            'validation_issues' => count($validation['issues']),
        ]);

        return [
            'success' => $validation['ok'],
            'resource' => $className,
            'class' => $result['class'],
            'file' => $file,
            'message' => $validation['ok']
                ? "Resource '{$className}' created at {$file} — validation clean."
                : "Resource '{$className}' generated, but validation reported errors — see `validation.issues`.",
            'validation' => $validation,
        ];
    }
}
