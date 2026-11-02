# AGENTS.md

> Project instructions and map for AI agents, including Claude Code. This is the only agent instructions file: keep it up to date as the project evolves.

## Project Overview

MoonShine V (`moonshine-v`) is a free, MIT-licensed admin panel generator: an AI agent connects over MCP and builds a MoonShine admin UI for REST APIs or local Eloquent models. The repository is a runnable Laravel application; the API Admin module lives in `app/ApiAdmin/`. Use "MoonShine V" in prose and `moonshine-v` in identifiers: MCP `serverInfo.name`, `moonshine-v://` resource URIs, the development Compose project, container and database names.

## Tech Stack

- **Language:** PHP 8.5
- **Framework:** Laravel 13
- **Admin Panel:** MoonShine 5 (`5.x-dev`, exact commit pinned in `composer.lock`)
- **Database:** SQLite for source development; PostgreSQL + Redis in the installer and production stacks
- **Frontend:** Tailwind CSS 4, Vite 8
- **Testing:** PHPUnit 12, `node --test` for the installer

## Project Structure

```
v/
├── app/
│   ├── ApiAdmin/               # API Admin module
│   │   ├── MCP/                # MCP server, tools, generators, analytics, spec store
│   │   ├── Resources/          # AbstractRestResource, MultiPanelRestResource
│   │   ├── Fields/             # REST relation fields + generated Custom/
│   │   ├── Pages/              # RestIndexPage, RestFormPage
│   │   ├── ServiceConfig/      # Service, auth, response mapping and realtime DTOs
│   │   ├── Auth/               # Upstream auth handlers, delegated admin auth, retry-on-401
│   │   ├── Tenancy/            # Local/hosted tenant context, routes, keys, secrets
│   │   ├── Instructor/         # Browser onboarding domain logic
│   │   ├── Realtime/           # Webhook ingest and browser refresh events
│   │   └── Http/, Layouts/, Components/, TypeCasts/, Validation/, Pagination/, Console/, Providers/
│   ├── MoonShine/
│   │   ├── ApiAdmin/           # Hub + Instructor pages and generated Configs/, Layouts/, Resources/
│   │   └── Resources/          # Native MoonShine resources, generated local model resources
│   └── Models/                 # Tenant, MCP token and upstream identity models
├── config/                     # api-admin.php, moonshine.php and Laravel config
├── database/                   # Migrations, factories, seeders
├── docker/                     # Dockerfile, compose stacks, backup/restore, smoke test
├── docs/                       # User documentation
├── examples/                   # Example upstream APIs, MCP scenario, wipe script
├── resources/                  # Views and frontend sources
├── scripts/                    # Cross-platform installer and its tests
├── stubs/api-admin/            # Code generation templates
├── storage/api-admin/generated/ # Runtime service and panel registry
├── tests/                      # ApiAdmin/{Unit,Integration}, Feature, Unit
└── .ai-factory/                # Roadmap, rules, architecture, references, generated specs
```

## Key Entry Points
| File | Purpose |
|------|---------|
| `artisan` | Laravel CLI entry point |
| `composer.json` | Dependencies — Laravel 13, MoonShine 5 |
| `app/ApiAdmin/MCP/McpServer.php` | MCP tool registry and dispatch |
| `app/ApiAdmin/MCP/Generator/ResourceGenerator.php` | REST resource generator (spec + stubs → PHP) |
| `app/ApiAdmin/Resources/AbstractRestResource.php` | HTTP-backed CrudResource: list, find, save, delete |
| `app/ApiAdmin/Providers/ApiAdminServiceProvider.php` | Composition root: routes, generated resources, service bindings |
| `app/ApiAdmin/Pages/RestIndexPage.php` | MoonShine 5 index page base that binds REST relation filters to their resource |
| `config/app.php` | Application configuration |
| `routes/web.php` | Web routes |
| `database/database.sqlite` | Local development database (created during source setup) |
| `app/ApiAdmin/Auth/OAuth2TokenStore.php` | OAuth2 token acquisition, refresh, rotation, and tenant/user-scoped cache keys |
| `app/ApiAdmin/Auth/SessionAuthHandler.php` | Cookie/CSRF session login handler with retry refresh support |
| `app/ApiAdmin/Auth/DelegatedTokenAuthHandler.php` | Per-panel delegated token/PAT session auth handler |
| `app/ApiAdmin/Auth/UpstreamUserProvider.php` | Per-panel upstream-backed MoonShine user provider for delegated/OAuth admin auth |
| `app/ApiAdmin/Auth/CurrentAdminUser.php` | Current MoonShine/upstream admin id resolver for per-user auth cache scopes |
| `app/ApiAdmin/Http/Controllers/OAuthAuthorizationController.php` | Per-panel OAuth Authorization Code + PKCE redirect/callback flow |
| `app/MoonShine/ApiAdmin/Pages/InstructorPage.php` | MoonShine-native browser UI for the Instructor onboarding flow |
| `app/ApiAdmin/Tenancy/TenantResolver.php` | Tenant context contract for local/hosted execution |
| `app/ApiAdmin/Tenancy/TenantRoutes.php` | Local/hosted route prefix and tenant URL helper |
| `app/ApiAdmin/Tenancy/TenantKeyBuilder.php` | Tenant-aware cache/session/realtime channel key builder |
| `app/ApiAdmin/Tenancy/TenantSecretStore.php` | Durable encrypted DB secrets with legacy cache migration |
| `app/ApiAdmin/Tenancy/TenantBrowserAccess.php` | Explicit tenant membership authorization for browser users |
| `app/ApiAdmin/Http/RestEndpointAuthorization.php` | Panel, tenant and resource-action authorization for relation/search endpoints |
| `app/ApiAdmin/Http/OutboundHttp.php` | Same-origin outbound clients and hosted DNS/IP egress restrictions |
| `app/ApiAdmin/Realtime/BroadcastAuthController.php` | Hosted private channel authorization using the service panel guard and tenant membership |
| `app/ApiAdmin/MCP/GenerationTransaction.php` | Generation lock, filesystem journal and failure rollback |
| `docker/compose.production.yml` | Production profile with persistent generated state, workers, readiness and backup |
| `app/Models/ApiAdminTenant.php` | Hosted tenant metadata model |
| `app/ApiAdmin/MCP/ServiceRegistry.php` | Tenant-aware generated service/resource/config/layout state registry |
| `app/ApiAdmin/MCP/McpAuthorizationGate.php` | Shared MCP authorization gate for hosted token scopes and tenant/service access |
| `app/ApiAdmin/MCP/HostedMcpController.php` | Hosted Streamable HTTP MCP entry point with tenant-bound bearer token authentication |
| `app/Models/ApiAdminMcpToken.php` | Hosted developer MCP token metadata and hashed token storage |
| `app/ApiAdmin/Console/TenantCommand.php` | `api-admin:tenant` — create, rename, list, activate and deactivate hosted tenants |
| `app/ApiAdmin/Console/McpTokenCommand.php` | `api-admin:mcp-token` — issue (shown once), list and revoke tenant-bound MCP tokens |
| `app/Models/MoonshineUserUpstreamIdentity.php` | Upstream subject link and encrypted refresh metadata for synced admin users |

## Key Vendor References
| File | Purpose |
|------|---------|
| `vendor/moonshine/moonshine/src/Crud/src/Resources/CrudResource.php` | Base class for REST resources |
| `vendor/moonshine/moonshine/src/Laravel/src/Fields/Relationships/` | Relation fields to study/replace |
| `vendor/moonshine/moonshine/src/Core/src/TypeCasts/` | MixedDataCaster, MixedDataWrapper |
| `vendor/moonshine/moonshine/src/Laravel/src/DependencyInjection/MoonShineRouter.php` | Router for multi-panels |

## Documentation
| Document | Path | Description |
|----------|------|-------------|
| README | README.md | Project overview and one-command installation |
| Getting Started | docs/getting-started.md | Source installation, browser onboarding and examples |
| Interactive Installation | docs/installation.md | Cross-platform wizard, MCP setup and recovery |
| MCP Workflow | docs/mcp-workflow.md | REST/Eloquent generation, fields and mutation routing |
| MCP Tools | docs/mcp-tools.md | Tool schemas and parameters |
| Configuration | docs/configuration.md | Response mapping, authentication and service settings |
| Architecture | docs/architecture.md | Modules and dependency rules |
| Production | docs/production.md | Deployment, readiness, backup and restore |
| Hosted Security | docs/hosted-security.md | Tenant membership, restrictions and upgrades |
| Release Readiness | docs/release-readiness.md | Recorded release checks and limitations |
| Security Policy | SECURITY.md | Private vulnerability reporting and supported code |
| Contributing | CONTRIBUTING.md | Contributor setup, checks and pull request conventions |
| Code of Conduct | CODE_OF_CONDUCT.md | Community standards and private conduct reporting |
| Changelog | CHANGELOG.md | Notable changes per release |
| Detailed MCP Tools | docs/api-admin/mcp-tools.md | Detailed schemas and JSON examples |
| ServiceConfig | docs/api-admin/service-config.md | Service configuration reference |
| Development Docker | docs/api-admin/docker-quickstart.md | Contributor container setup |
| Smoke Checklist | docs/SMOKE_CHECKLIST.md | Manual verification scenarios |

## AI Context Files
| File | Purpose |
|------|---------|
| AGENTS.md | This file — project map, conventions and MCP tool contract |
| LLMS.txt | Agent-facing cheatsheet for the MCP tool surface (fallback when `initialize.instructions` isn't surfaced by the client). Kept in sync with `McpServerCommand::agentInstructions()` and the tool schemas |
| CONCEPT.md | Product concept |
| .ai-factory/DESCRIPTION.md | Project specification and tech stack |
| .ai-factory/ARCHITECTURE.md | Architecture decisions and guidelines |
| .ai-factory/ROADMAP.md | Completed milestones |
| .ai-factory/RULES.md | Quality gate and PHPStan policy |
| .ai-factory/references/ | MoonShine internals, devkit-restapi, multi-panels, moonshine-builder |

## Agent Rules

- Never combine shell commands with `&&`, `||`, or `;` — execute each command as a separate Bash tool call. This applies even when a skill, plan, or instruction provides a combined command — always decompose it into individual calls.
  - Wrong: `git checkout main && git pull`
  - Right: Two separate Bash tool calls — first `git checkout main`, then `git pull`

## Quality Gate

**Before marking any task done, run `XDEBUG_MODE=off composer ai:validate` and ensure it exits clean.** Use `XDEBUG_MODE=off` for project validation and tests in this environment: PHP 8.5 CLI with Xdebug can abort during `php artisan test` with `zend_mm_heap corrupted` / signal 6, while the same suite passes with Xdebug disabled. The script chains:

1. `composer lint` — Pint dry-run (code style).
2. `composer rector` — Rector dry-run (refactor suggestions).
3. `composer audit --no-dev --locked --abandoned=ignore` — supply-chain advisories.
4. `composer stan` — PHPStan level max via larastan, `excludePaths: []` and `ignoreErrors: []` are enforced.
5. `composer test` — PHPUnit (`php artisan test`). New behavior is covered by new tests.

PHPUnit does not render most of the UI, so a green gate is not enough after changes to the interface or authorization, or after a MoonShine update: run `npm run test:e2e` and the production smoke (`docker/smoke.sh`).

Hosted browser routes allow only `AbstractRestResource`; native platform users,
roles and unattached CRUD pages are denied centrally by `EnsureTenantMembership`.
Keep tenant URL defaults available before authentication redirects. Scoped
controllers read named route parameters from `Request`, because `{tenant}` is
the first positional parameter. Generated hosted layout namespaces must match
their tenant directories and persisted panel class names.
Resource URI prefixes come from the service segment after any tenant directories;
two hosted services with the same resource name must retain different URI keys.

`App\MoonShine\Forms\DelegatedLoginForm` composes the final native MoonShine
`LoginForm`; it does not copy its markup. PATs use a `Password` field, retain case,
allow an absent refresh token, and are hashed in throttle keys. The `username`
input is excluded from flashed old input because it can contain a PAT.
Delegated state uses `expires_at = 0` for an unspecified expiry; restored PAT
sessions still require upstream validation, and actual expired state is rejected.

`npm run test:e2e` runs Playwright in the installed Chrome against an isolated copy of the files git publishes: its own SQLite, its own example API ports and generation through MCP. The developer's local services are not involved and do not change. Specs live in `tests/e2e/specs`, setup in `tests/e2e/global-setup.mjs` and `scripts/e2e-provision.php`. Setup rejects occupied test ports before provisioning and requires its own server processes to stay alive through readiness.

Generator tests must use independent temporary resource/spec roots. Test service
identifiers need random suffixes; the timestamp portion of `uniqid()` is shared
by concurrent processes and is not a unique identifier.

Full rationale and individual fix commands: `.ai-factory/RULES.md`.

REST save diagnostics log payload keys only, never values. Instructor completion
links use `TenantRoutes` so hosted panels retain the tenant prefix.
Async relation searches use the target service's `search_param_name` and retain
server-owned `endpoint_query` values. Error diagnostics use exception class and
HTTP status, never upstream response bodies or exception messages. Feature coverage:
`RestAsyncSearchTest`; Chrome covers selecting and saving a match beyond page one.
Selected BelongsTo labels use the target service's detail strategy and field scope,
require an exact ID match, and fall back to list options only after a 404.
`make optimize` clears config/route caches and caches only views: caching
configuration or routes prevents live panel discovery.

## UI Components

- **Always** use the ready-made components from `moonshine/ui` (package `moonshine/ui`, namespace `MoonShine\UI`).
- Before creating a new component, check whether a suitable one exists in `vendor/moonshine/moonshine/src/UI`.
- Create a new component **only** if `moonshine/ui` has nothing suitable and it cannot be built by composing or extending existing ones. In that case, briefly explain why the existing one did not fit.
- Prefer extending (inheriting or wrapping) an existing component to creating one from scratch.

## ApiAdmin Resources — structure

`App\MoonShine\ApiAdmin\Resources\*` resources follow the style of the native `App\MoonShine\Resources\*`, but are **partitioned by service**: service directory → resource directory → `Pages/` folder. Fields live **on pages**, not on the resource.

```
app/MoonShine/ApiAdmin/Resources/
  Blog/                                (service)
    Post/                              (resource)
      PostResource.php                 ← service config, endpoint, panel, title, group, pages(), search()
      Pages/
        PostIndexPage.php              ← fields() + filters()
        PostFormPage.php               ← fields() (+ optional rules())
        PostDetailPage.php             ← fields()
    Comment/
      CommentResource.php
      Pages/...
  Shop/
    Product/
      ProductResource.php
      Pages/...
```

Rules:
- The first level is the **service name** in StudlyCase (`blog` → `Blog`). This prevents class collisions when different services have entities with the same name (for example, `Blog\Post` and `Cms\Post`).
- The second level is the **resource name** in StudlyCase (`post` → `Post`). It holds the resource class and a `Pages/` folder with three pages.
- Resource namespace: `App\MoonShine\ApiAdmin\Resources\{Service}\{Resource}`. Pages: `App\MoonShine\ApiAdmin\Resources\{Service}\{Resource}\Pages`.
- A Resource holds only metadata (`$panel`, `$title`, `getGroup()`, `resolveServiceConfig()`, `endpoint()`, `pages()`, `search()`). **Do not define** `indexFields()/formFields()/detailFields()/filters()` on the resource: they remain in `AbstractRestResource` as a fallback, and in this structure pages override them.
- `FormPage` for REST resources extends `App\ApiAdmin\Pages\RestFormPage` (renders the outside fields `RestHasOne`/`RestHasMany` after the main form). `IndexPage` extends `App\ApiAdmin\Pages\RestIndexPage` (MoonShine 5: relation filters are bound to the REST resource on the page), `DetailPage` extends `MoonShine\Crud\Pages\DetailPage`.
- Related resources (used in `RestHasMany`/`RestHasOne`) live **inside the same service** and extend `AbstractRestResource`, without `$panel`/`getGroup()`.

The scaffolder (MCP `create_resource`, class `App\ApiAdmin\MCP\Generator\ResourceGenerator`) creates this structure automatically from the `service` parameter. Stubs live in `stubs/api-admin/` (`resource.stub`, `relation-resource.stub`, `index-page.stub`, `form-page.stub`, `detail-page.stub`). When creating a resource by hand, always keep this layout.

### Keeping the generator, stubs and generated files in sync

Generated resources and pages (`app/MoonShine/ApiAdmin/Resources/**`) are the **output** of the generator, not the source of truth. If you edit a generated file by hand (for example, add a field modifier, change a wrapper, rename a method), you must:

1. Update the matching stub in `stubs/api-admin/` and/or `App\ApiAdmin\MCP\Generator\ResourceGenerator` so the next regeneration (`make services-regen` or MCP `create_resource`) produces the same file.
2. Cover the change with a test: the generator is covered by `tests/ApiAdmin/Unit/ResourceGeneratorTest.php`, field behavior by integration tests (`tests/ApiAdmin/Integration/RestHasOne*`, `RestFieldsTest`, `OutsideFieldsTest`). The test must catch both the old undesirable behavior (what was removed) and the new expected one.
3. Check the MoonShine API conventions (for example, removable naming instead of deletable, `removable()`/`creatable()`/`editable()` from `MoonShine\UI\Traits\Removable`): the public API of custom fields must repeat these names so stubs and user code look native.
4. Run `make services-regen` after generator changes and inspect the result for at least one service (Blog) so the output matches the expectation. A mismatch between a manual edit and a stub causes silent regressions on the next `create_resource`.
5. Run `make stan` — PHPStan with `larastan` at level max. There is deliberately no baseline, `ignoreErrors: []` and `excludePaths: []` stay empty: a new error is fixed in code (see `.ai-factory/RULES.md`).

### Box wrappers and preview for REST fields

The main form is wrapped in a `Box` **through the page's `fields()`** (the native MoonShine pattern, see `MoonShineUserFormPage`), **not** through a `mainLayer()` override on `RestFormPage`. The `form-page.stub` stub generates `Box::make([ ...fields... ])` in the `fields()` return, and the generator (`ResourceGenerator::generatePages`) indents form fields by +4 spaces through `indentLines()` so they sit inside `Box::make([...])`. If you change the wrapper (add `Tabs`, `Collapse` and so on), edit the stub or generator, not `RestFormPage::mainLayer()`.

`RestHasOne` in the outside `bottomLayer()` block renders in its own `Box` (see `RestHasOne::viewData()`) because it contains an embedded FormBuilder and needs a visual frame. `RestHasMany` has **no Box**: `viewData()` returns a bare `TableBuilder` plus a separate `createButton`, and the blade view `fields/rest-has-many.blade.php` renders them like the native `has-many` (`<flex>createButton</flex>` + `lineBreak` + table). To bring a Box back for HasMany, edit `viewData()` and the blade view, not `RestFormPage`.

`AbstractRestResource::getDetailFields()` includes outside fields by default (`withOutside: true`) so `RestHasOne`/`RestHasMany` appear on the detail page in preview mode (native MoonShine strips them). Fields render through their `resolvePreview()`:
- `RestHasOne::resolvePreview()` lazily loads the related item over HTTP and renders `getTableComponent()` (a vertical preview table). It uses `getValue()` (through `resolveValue`), not `toValue()`, otherwise the detail page stays empty because the parent does not embed the relation in its payload.
- `RestBelongsToMany::resolvePreview()` fetches `loadCurrentAssociations()` + `loadOptions()` and joins labels with commas. On an index page that is one HTTP call per row by default (acceptable for 25-row pagination). For bulk optimization: (1) all `loadOptions()` and `loadCurrentAssociations()` calls auto-coalesce through the static `RestBelongsToMany::$requestCache`, so the same URL+query per request hits the wire **once**; (2) if the upstream exposes a bulk endpoint, configure `->batchAttachEndpoint('/posts/tags-batch?parent_ids[]={ids}')` + `->batchAttachMapper(Closure)` and call `->primeAssociations($allParentIds)` from a list-page hook before rendering the table: the bulk fetch populates the primed cache and later per-row lookups read from it without HTTP. `RestBelongsToMany::flushCaches()` is for tests.
- `RestHasMany::resolvePreview()` renders the first `previewLimit` (default 3) entities through `renderPreviewLabel()`: key priority `title → name → label → slug`, fallback `#{id}`. Override with `->previewLabel(fn(array $item): string)`. An empty collection gives `''` (consistent with `RestBelongsToMany`), overflow gives `+N more`. The label is escaped, including one from `previewLabel`. `resolveValue()` prefers an already loaded value (`toValue`) if the parent embedded the children, avoiding an extra HTTP request.

### Value escaping

Values are escaped when displayed and not changed when saved: the payload belongs to the upstream. So `config/moonshine.php` sets `escapes.value_on_apply = false`; otherwise `Tom &amp; Jerry` goes to the API and every re-save encodes the value again. Custom fields must escape upstream data themselves in `resolvePreview()`: `RestBelongsTo`, `RestBelongsToMany` and `RestHasMany` do it with `e()`. MoonShine expects ready HTML from a preview, so a missing `e()` is a stored XSS. Coverage: `RestPayloadEscapingTest`, `RestFieldsTest::test_*_preview_escapes_upstream_*`.

Box and preview tests: `ResourceGeneratorTest` (checks `Box::make([` in the form page), `RestHasOneTest::test_view_data_returns_box_*`, `RestHasOneTest::test_resolve_preview_lazy_loads_related_item`, `RestFieldsTest::test_belongs_to_many_preview_*`, `RestFieldsTest::test_has_many_preview_*`, `OutsideFieldsTest::test_get_detail_fields_includes_outside_relations_by_default`.

### Page layout presets

`layout.form` on `create_resource` / `update_resource` input changes the field wrapper in `FormPage::fields()`. Three presets; the default keeps the original behavior (one `Box`):

| `type` | What it emits | Use |
|---|---|---|
| `box` (default) | `Box::make([ …all fields… ])` | The default, nothing to do |
| `tabs` | `Tabs::make([Tab::make(label, [Box::make([ …group-fields… ])]), …])` | Long forms grouped by meaning (profile / access / settings) |
| `two-column` | `Grid::make([Column::make([Box::make([ …group-fields… ])])->columnSpan(span), …])` | Dense main+side layout; `span` defaults to 6, the sum must be 12 |

Shape:
```jsonc
{ "layout": { "form": {
  "type": "tabs",
  "groups": [
    { "label": "Profile", "columns": ["first_name", "last_name", "email"] },
    { "label": "Access", "columns": ["password", "password_confirmation"] }
  ]
}}}
```

Columns not mentioned in any group are added to the **last** group rather than silently lost. Extra imports (`Grid`/`Column`/`Tabs`/`Tab`) are merged into the FormPage use statements automatically. The stub contains `{{ formLayout }}` (not `{{ formFields }}`), and the `Box`/`Grid`/`Tabs` wrapper is built in the generator: to change the structure, edit `ResourceGenerator::buildFormLayout` and its helpers (`wrapLinesInBox`, `buildTabsLayout`, `buildTwoColumnLayout`, `partitionColumnsByGroups`, `renderGroupLines`), not the template.

A recursive tree (AST) is supported through `layout.form.tree`. Container nodes: `type ∈ {box, grid, column, tabs, tab, collapse}` with `children: [...]`. Leaf: `{type: "fields", columns: ["col1","col2"]}`. `tab`/`collapse` read `label`; `column` accepts an optional `span` (1–12). The tree wins over the `type`/`groups` presets if both are set. Columns not mentioned in any `fields` leaf are added after the tree in an auto-Box, the same "no field is lost" contract as the presets. Example spec: `{"type":"grid","children":[{"type":"column","span":8,"children":[{"type":"box","children":[{"type":"fields","columns":["title","body"]}]}]},{"type":"column","span":4,"children":[{"type":"tabs","children":[{"type":"tab","label":"Meta","children":[{"type":"fields","columns":["slug","published_at"]}]}]}]}]}`. Implementation: `ResourceGenerator::buildLayoutTreeRoot` + `buildLayoutNode` (a recursive match on `type`). Coverage: `ResourceGeneratorTest::test_generator_emits_recursive_layout_tree`, `test_generator_appends_unclaimed_columns_after_layout_tree`.

Coverage: `ResourceGeneratorTest::test_generator_emits_tabs_layout_preset`, `test_generator_emits_two_column_layout_preset`, `test_generator_default_layout_unchanged_without_spec`.

### Per-resource `detail_strategy` override

`register_service.response_mapping.detail_strategy` (`endpoint` / `list`) is set at the service level and applies to all resources by default. If in a real API **some** resources have `GET /{id}` (users, projects) and **others** only list + CUD (statuses, tags, categories — a typical Laravel lookup with `Route::resource('statuses')->only(['index','store','update','destroy'])`), override it at the resource level:

```json
{ "detail_strategy": "list", "detail_list_key_param": "id" }
```

in the `create_resource` / `update_resource` spec. The generator emits `getDetailStrategyOverride()` / `getDetailListKeyParamOverride()` on the `Resource` class; `AbstractRestResource::findItem()` and `findItemViaListFilter()` read the resource override first, then fall back to the service default. Both are `null` by default, so resolution stays service-wide.

Without this option you either move the whole service to `list` (which breaks users/projects) or have to change the upstream API. Coverage: `ResourceGeneratorTest::test_generator_emits_per_resource_detail_strategy_override`, `test_generator_omits_detail_strategy_override_when_absent`.

### POST list / POST-RPC resources

Some upstream APIs read a list not through `GET /resource` but through a POST-RPC/search endpoint with a JSON body. For such resources pass to `create_resource` / `update_resource`:

```json
{
  "list_request": {
    "method": "POST",
    "body": { "ids": [] }
  }
}
```

The generator emits `$indexMethod = 'POST'` and `$indexBody`; `AbstractRestResource::getItems()`, `fetch_resource_items`, `count_resource`, `aggregate_resource` and `detail_strategy: "list"` use the same POST list path. `detail_strategy: "list"` can be combined with `detail_list_key_param`: for example, `detail_list_key_param: "ids"` turns a lookup of id=7 into the body `{"ids":[7]}` if the static body contains `ids: []`.

`probe_endpoint` also accepts `method` + `body`; for a POST-only list endpoint always use `probe_endpoint(service, path, method: "POST", body: {...})`, otherwise a GET 405 can produce a garbage `guessed_mapping` from the error body.

### Filter / sort / search param names

Pagination request mapping also accepts `per_page_param_name` (default `per_page`)
and `zero_based_pages` (default `false`). Spring Boot uses `size` and `true`;
UI/MCP pages remain one-based. The Django preset maps body `next`/`previous`
navigation and uses a simple pager when last-page metadata is absent. Probe and
Instructor discovery must emit the same mapping, including Spring's `content`,
`totalElements`, `size`, `number`, `totalPages` and `zero_based_pages: true`.
List-strategy item reads require
an exact primary-key match and must never fall back to the first row.

Link-header pagination reads only positive `page` numbers from upstream URLs and
builds panel URLs with the current filter/search/sort query. Never emit upstream
hosts, paths or credentials in browser pagination links. Feature coverage lives
in `RestApiCompatibilityTest`; Chrome covers simple and numbered Link navigation.

`response_mapping` on `register_service` input accepts three keys: `filter_param_name` (default `filter`), `sort_param_name` (default `sort`), `search_param_name` (default `search`). The combination `filter_format: nested + filter_param_name: filters` is the Laravel + Spatie Query Builder default (`?filters[status]=todo`). Without these keys the filter, sort and search controls could be silently ignored by the upstream and look like "filters are broken".

`probe_endpoint` with `detect_filter_for` checks three forms (`filter[]`, `filters[]`, `flat`) and returns `guessed_filter_format` ∈ {`nested`, `plural_nested`, `flat`}. If `plural_nested` wins, the agent must set `filter_format: nested + filter_param_name: filters`.

Coverage: `ServiceConfigTest::test_filter_sort_search_param_names_*`. `AbstractRestResource::buildListParams` uses `$mapping->filterParamName`/`sortParamName`/`searchParamName` instead of hard-coded names.

### Per-mode `methods` (create vs update)

The field-level analog of per-mode rules. `methods` accepts a flat list (back-compat) or a split map:

```jsonc
{ "column": "password", "type": "password",
  "methods": {
    "create": ["required"],   // asterisk only on the create page
    "update": []              // no modifiers on edit
  }
}
```

On the FormPage the generator branches by mode:
- Methods present in **both** lists go in the plain chain (`->sortable()`).
- Create-only: if the signature accepts `Closure|bool` (detected through Reflection: `required`/`disabled`/`readonly`/`nullable`/`multiple`/`vertical`/`searchable`/`tabMode`), it emits the native MoonShine pattern `->required($this->getItem() === null)`. Methods that take no condition (`sortable`, `eye`, `customAttributes` and so on) become `->when($this->getItem() === null, fn ($f) => $f->method(args))` through the `Conditionable` trait (all `MoonShineComponent`s have it).
- Update-only: the same with the condition `$this->getItem() !== null`.

On IndexPage/DetailPage split methods **merge** into one unconditional chain: there is no create-vs-edit mode, and `$this->getItem()` may be unavailable or meaningless. UI-only modifiers on these pages are usually needed in both "modes" anyway.

The canonical case is `password` on a user resource: `methods.create: ["required"]`, `methods.update: []`, plus `{"create": "required|...", "update": "nullable|..."}` in `rules`. On Create the user sees the asterisk and the requirement. On Edit neither (blank means do not touch the password). Coverage: `ResourceGeneratorTest::test_generator_splits_field_methods_per_mode_with_condition_arg`, `test_generator_wraps_update_only_method_in_when_when_no_condition_arg`, `test_generator_collapses_split_methods_shared_in_both_modes`, `test_generator_emits_split_methods_as_union_on_non_form_pages`.

### Per-mode `rules` (create vs update)

`rules` on `create_resource` / `update_resource` input supports three forms that normalize into one pair `[createMap, updateMap]`:

1. **Flat** (the original, fully compatible) — `{"col": "required|..."}` or `{"col": ["required", "uuid"]}`. The rule applies to both create and update.
2. **Per-column per-mode** — `{"col": {"create": "required|...", "update": "nullable|..."}}`. One column differs between modes, the others are shared. The typical case is `password` on a user resource: `required` on create, `nullable` on edit so a blank field does not erase the password upstream.
3. **Top-level split** — `{"create": {...}, "update": {...}}`. Two independent maps; what is not in one of them does not reach the generated method.

If after normalization the create map equals the update map, the generator collapses them into one flat `return [...]` and does not emit a separate `if ($item->getKey() === null)` branch (dead code). If they differ, it branches on `$item->getKey() === null` (the canonical "fresh record" signal from `DataWrapperContract`; the native `MoonShineUserFormPage::rules()` does the same).

Note: `->required()` on the field itself is only a UI marker (a red asterisk); rules drive validation. If a rule is create-only, the edit form still shows the asterisk. This is a known cosmetic mismatch with no functional effect. Coverage: `ResourceGeneratorTest::test_generator_splits_rules_per_column_per_mode`, `test_generator_splits_rules_at_top_level`, `test_generator_collapses_per_mode_rules_when_create_equals_update`.

### Password `->raw()` — no Hash::make for REST

`type: password` maps to the native `MoonShine\UI\Fields\Password`, and the generator **automatically** appends `->raw()` to the chain. `raw()` (added in MoonShine 4.x, PR #2018) disables `Hasher::make()` in `resolveOnApply()`: the field writes to the payload exactly what came in the request. The reason: ApiAdmin REST resources are not Eloquent-backed; the upstream hashes the password itself, and hashing here too would produce a double hash and the user could not log in. The rest of the Password behavior (input masking, `***` preview, skip empty on edit) stays native.

This used to be an `App\ApiAdmin\Fields\RestPassword` subclass overriding `resolveOnApply()`. With `Password::raw()` available out of the box the subclass was removed, and user code must not add `raw` to `methods` by hand; that is the generator's job. If you add another "raw" variant of a protected field, first check whether a native `->raw()` exists; if not and an override is required, update `FIELD_TYPE_MAP` in `ResourceGenerator`, `LLMS.txt`, the enum description in `CreateResourceTool::schema()` and `McpServerCommand::agentInstructions()` as a single commit. Coverage: `ResourceGeneratorTest::test_generator_emits_password_with_raw_for_password_type` (the map and `->raw()` do not regress).

### Static `make()` and docblocks

If a custom field or component overrides the constructor and adds new named arguments (for example, `RestHasOne::__construct(..., ?string $resource = null)`), you **must** add a PHPDoc annotation on the class:

```php
/**
 * @method static static make(string $label, ?string $column = null, ?Closure $formatted = null, class-string<AbstractRestResource>|null $resource = null)
 */
```

Otherwise an IDE or PHPStan sees only the parent signature (`Field::make`) and reports "Unknown named parameter 'resource'". This is the same convention MoonShine itself uses (see `ModelRelationField`, `Box`).

### Regenerating services from scratch

The repository has no generated services: every developer deploys the project and generates their own. Configs, Layouts (except `AdminHubLayout.php`), Resources, specs and `storage/api-admin/generated/` are ignored by git. Do not add services, their panels, tokens or addresses of specific environments to the repository, and do not use `git add -f` on these paths.

- `make services-wipe` — deletes locally generated `Configs/`, `Layouts/*Layout.php` (except `AdminHubLayout.php`), `Resources/*`, specs and the state in `storage/api-admin/generated/{services,panels}.php`. It does not change tracked files. Script: `examples/wipe-services.php`. **It removes all your local services.**
- `make services-regen` — `services-wipe` → `examples-up` → runs `examples/run-mcp-scenario.php` → `api-admin:reload`. Use it to rebuild the example Blog/Shop services locally after changes to stubs or the generator.

## MCP tools (the surface for an external AI agent)

The core is MoonShine `5.x-dev`, exact commit in `composer.lock`. `create_resource` / `update_resource` generate a list page based on `RestIndexPage`: `filters()` and `getFilters()` belong to the page, not the resource. This contract is kept in sync with the tool schema, tests, `LLMS.txt` and `McpServerCommand::agentInstructions()` under the common sync rule.

Moonshine-v is a free Docker product. A developer starts the container and, from their Claude Code over MCP, builds admin panels over REST APIs or local Eloquent models without looking at moonshine-v code. In local/self-hosted mode operations go through the stdio MCP server: `php artisan api-admin:mcp-server`. In hosted mode the client connects to an HTTPS Streamable HTTP MCP endpoint with a tenant-bound bearer token; token scopes (`tools:read`, `tools:generate`, `tools:data:read`, `tools:data:write`, `tools:destructive`) grant access only to tool families, and `McpAuthorizationGate` still cuts off cross-tenant service/resource access before dispatch. Operators provision hosted access with `api-admin:tenant` (create, rename, list, `--activate`/`--deactivate`), `api-admin:tenant-member` and `api-admin:mcp-token` (issue with explicit `--scope`, `--list`, `--revoke`); tokens are printed once and stored as SHA-256 hashes, and `McpAuthorizationGate::isKnownScope()` is the scope allowlist. There is deliberately no MCP tool for tenants or tokens. Hosted `register_service` stores `api_key`, `oauth_client_secret`, delegated OAuth/session secrets and `webhook_secret` through the tenant-scoped `TenantSecretStore`; `.env` and literal secret fallbacks remain only for local/self-hosted mode.

Hosted generation policy: create_field/update_field/delete_field,
create_component/update_component/delete_component, initialize_local_service,
and create_model_resource require trusted local mode; hosted calls fail with
MCP_HOSTED_TOOL_DISABLED even for wildcard scopes. Service, panel, and generated
class names must start with a letter and contain only letters, digits,
underscores or hyphens (maximum 80 characters). Hosted outbound requests reject
private/link-local destinations by default; all modes enforce the configured
origin, TLS verification, and no redirects. Hosted service secrets are encrypted
in the database and survive cache clearing. Browser tenant access requires an
explicit operator-granted membership (api-admin:tenant-member).
Generation operations are serialized through a shared filesystem lock. A failed
operation restores modified files and configuration; Instructor confirmation
rolls back the whole service/resource operation on failure. This does not roll
back upstream data CRUD calls. Deploy writers on the same persistent filesystem.
Hosted fields use built-in types only. Allowed chain methods are: required,
disabled, readonly, nullable, multiple, vertical, searchable, tabMode, sortable,
reactive, placeholder, default, min, max, step, minLength, maxLength, pattern,
mask, format, inputFormat, onValue, offValue, options, raw, creatable, editable,
removable, fullCrud. list_field_methods returns the permitted catalog for the
current mode; arbitrary callbacks, storage methods and custom views are blocked.
Hosted native file/image fields are disabled, including existing resource forms,
because their storage is shared. Use upstream media URLs instead. Generated
validation rules are limited to the scalar/list/date rules advertised in
create_resource.rules; database, custom, file, regex and network validation
rules are rejected (email accepts only rfc/filter/filter_unicode modes).
Service names and panel identifiers must be unique installation-wide. Ownership
is rechecked from persistent state while the generation lock is held.

### Connecting Claude Code from another project

Copy `v/.mcp.json.example` to your project root as `.mcp.json` and keep one of the two blocks (Docker or local). Hosted mode needs no local moonshine-v clone: `.mcp.json` must point at `https://<host>/t/<tenant>/api-admin/mcp` and pass only a tenant-bound bearer token. After restarting Claude Code the tools appear with the `moonshine-v_*` prefix.

### MCP resources

Besides tools, the server provides `resources/list` + `resources/read`. Four resources:

- `moonshine-v://llms` — serves the root `LLMS.txt` live (the full agent cheatsheet).
- `moonshine-v://fields` — JSON dump of `list_fields` (live catalog of field types).
- `moonshine-v://components` — JSON dump of `list_components` (layout primitives).
- `moonshine-v://field-methods` — JSON dump of `list_field_methods` (union of chain methods).

An agent reads them once per session and does not parse the tool schema on every iteration. Add new resources in `McpServerCommand::resourceCatalog()` + `readResource()` and cover them in `McpProtocolTest::test_stdio_resources_*`.

### Tool set

| Tool | What it does | Destructive |
|---|---|---|
| `register_service` | Registers a service: Config, Layout, service and panel entries in `storage/api-admin/generated/`. Accepts `response_format: laravel\|django\|flat\|github\|custom` (mapping preset) or an explicit `response_mapping`; `realtime` defines the webhook + browser refresh contract, the recommended broadcaster backend is Laravel Reverb. Omitted secrets are preserved in local/hosted storage; explicit empty strings clear them unless required by the selected auth/realtime mode. Omitting the whole `realtime` object keeps its stored settings. | no |
| `initialize_local_service` | Registers a local MoonShine panel for Eloquent models (`kind: local`, `driver: eloquent`). Does not accept `base_url`, `api_key`, `response_mapping` or OAuth/session/admin_auth/realtime/health settings; those need `register_service`. | no |
| `create_resource` | Generates a Resource + three pages for a service. Supports `has_many`/`has_one` with nested resources, `belongs_to_many`, `select` with `options`, `list_request` for POST list/search endpoints. An existing resource wins over the other form of its name: `posts` regenerates `Post` (`ServiceRegistry::resourceBaseName`). | no |
| `create_model_resource` | Generates a native MoonShine `ModelResource` + Pages for a local Eloquent service. Accepts `model`, scalar/UI `fields`, per-page overrides, `rules`, `search`, `actions`, `icon`, `group`. Rejects REST services with `LOCAL_SERVICE_REQUIRED`, missing or non-Eloquent models with `MODEL_NOT_FOUND`. | no |
| `configure_layout` | Changes panel logos and title. Menu items come from the panel's resources. | no |
| `list_services` | Array of registered services with `base_url`, `panel`, `auth_type`, `resources_count`, file paths. Read-only. | no |
| `list_resources` | Array of generated resources (across all services or for one). Read-only. | no |
| `get_resource` | The latest resource spec from the sidecar (`.ai-factory/specs/<service>/<resource>.json`). Call it before `update_resource`, which does a full replace. `SPEC_MISSING` if there is no sidecar. Read-only. | no |
| `remove_service` | Full service removal: Configs, Layouts, Resources/{Service}, service and panel entries in the generated registry. Requires `confirm: true`. | **YES** |
| `remove_resource` | Removes one resource (directory + Pages). Requires `confirm: true`. | **YES** |
| `probe_endpoint` | Tries a service endpoint with its authorization. GET by default; for POST-only list/search accepts `method: "POST"` + `body`. Returns `{status, headers, sample_json, guessed_mapping, guessed_format, hints, timings_ms}`. | no |
| `health_check` | Pings the health endpoint of all tenant-visible services (or one through `service`). Returns `{service, tenant_id, status, code, url, latency_ms}` and `diagnostics` (`mode`, tenant id, cache/session/queue/broadcast backends, hosted warnings). | no |
| `update_resource` | Regenerates an existing resource with a new `fields` spec. Refuses if the resource does not exist (`RESOURCE_NOT_FOUND`, hint → `create_resource`). The current `endpoint` is picked up automatically if not passed. | no (but overwrites files) |
| `fetch_resource_items` | Fetches a paginated list through `AbstractRestResource::getItems()`, the same code path as the UI. Accepts `filter`, `search`, `sort`, `page`/`per_page`, `cursor`, `parent_context`. Returns `{items, pagination: {strategy, ...}}`. For relation resources (`{parent_id}` in the endpoint) without `parent_context` it responds `RELATION_RESOURCE_REQUIRES_PARENT`. | no |
| `fetch_resource_item` | A single item through `findItem()`; honors the per-resource `detail_strategy` override (endpoint vs list). Accepts `id` + optional `parent_context`. Returns `{item}` with the primary_key guaranteed at the top. `NOT_FOUND` on a 404. | no |
| `count_resource` | Counts resource records through the same `AbstractRestResource::getItems()` path. Accepts `filter`, `search`, `time_window`, `parent_context`, `per_page`. Prefers the upstream `pagination.total`; for `pagination_type=none` counts materialized items. If the upstream indicates `has_more` without a total it returns `warning: PARTIAL_COUNT`. | no |
| `aggregate_resource` | Aggregates a resource's materialized items (`count`, `sum`, `avg`, `min`, `max`) with optional `field`, `group_by`, `filter`, `search`, `time_window`, `parent_context`, `limit`/`per_page`. Numeric operations require `field`; with `has_more` it returns `warning: PARTIAL_AGGREGATE`. | no |
| `instructor_status` | Read-only status of the browser onboarding wizard `/api-admin/instructor`: whether a session is active, whether there is a proposal, a sanitized summary. Hosted sessions are isolated to the active tenant even with an explicit session ID. Does not register services or generate resources; secrets are redacted. | no |
| `list_fields` | Flat list of field types for `fields[].type` with `group`/`description`/`is_custom`. Source: `ResourceGenerator::fieldTypeCatalog()`. Read-only. | no |
| `describe_field_type` | Reflection on a specific type: parent_class, `available_methods` (with `accepts_condition`), curated `schema_hints`. Read-only. `FIELD_TYPE_UNKNOWN` for an unknown type. | no |
| `list_field_methods` | Union of chain methods across all types (or one through `type?`). Each entry has `field_types`, `accepts_condition`, an optional `category`. Read-only. | no |
| `list_components` | Catalog of UI components for `layout.form.tree` (Box, Tabs, Grid, Column, FormBuilder, TableBuilder, …) + custom components from config and the filesystem. Read-only. | no |
| `create_resource_item` | POSTs an item through `AbstractRestResource::createItem()`. Honors `mutations.create`, the `actions` allowlist, `parent_context`. | no |
| `update_resource_item` | PUT/PATCH of an item with a pre-check through `findItem` → `NOT_FOUND` before the mutation. Honors `mutations.update` (multi-endpoint CQRS + `when_present`). | no |
| `delete_resource_item` | DELETE of an item. Requires `confirm: true`. Honors the `actions` allowlist. | **YES** |
| `create_field` | Scaffolds a custom MoonShine field under `app/ApiAdmin/Fields/Custom/<Name>.php` + an entry in `api-admin.custom_fields`. | no |
| `update_field` | Full replace of a custom field. `FIELD_NOT_FOUND` if it does not exist. | no (but overwrites) |
| `delete_field` | Removes the PHP + config entry. `FIELD_IN_USE` with a list of dependent specs; `force: true` overrides. Requires `confirm: true`. | **YES** |
| `create_component` | Scaffolds a custom component under `app/ApiAdmin/Components/Custom/<Name>.php` + a paired blade view + an entry in `api-admin.custom_components`. | no |
| `update_component` | Full replace of a component. `COMPONENT_NOT_FOUND` if it does not exist. | no (but overwrites) |
| `delete_component` | Removes a component. `COMPONENT_IN_USE` if a layout tree mentions it; `force: true` overrides. Requires `confirm: true`. | **YES** |
| `add_field` | Inserts one column into an existing resource without re-listing the full spec. Position via `before`/`after`/`index` (mutually exclusive, default = append). `pages` (subset of `[index, form, detail]`) emits `hide_on` of the complement. Mirrors the insert into any present `<page>_fields` overrides for pages in the visibility set. Errors: `FIELD_ALREADY_EXISTS`, `INVALID_POSITION`, `SPEC_MISSING`. | no |
| `remove_field` | Removes one column + a cross-reference scan (search/rules/filters/layout/mutations/depends_on/endpoint_query). Refuses with `FIELD_REFERENCED` (with a list of locations) if references exist, unless `force: true`. With force it strips dangling references. Clears the column in all 4 field arrays (canonical + index/form/detail overrides). Requires `confirm: true`. | **YES** |
| `rename_field` | Renames a column and automatically rewrites ALL cross-references: search, rules (flat + per-mode), filters, layout-tree leaves, layout groups, mutations (body_from/when_present/body keys/path placeholders), sibling depends_on, endpoint_query placeholder VALUES (keys are upstream-bound and untouched). Walks all 4 field arrays. `update_mutations: false` opts out of the mutation rewrite. Returns per-location counts. | no |
| `reorder_fields` | Reorders the canonical `fields[]`. `order` is partial by default: listed columns take the given order, unlisted ones keep their relative order and are appended after. `strict: true` requires an exhaustive list (`INCOMPLETE_ORDER`). The layout tree is NOT touched (author-controlled). | no |

### Panel sessions

The hub and regular panels use the `moonshine` guard. Panels with a separate sign-in (`admin_login: true`, `delegated_token`, `oauth2_authorization_code`) use their own guard `api-admin-upstream-{panel}`, so signing in to such a panel or losing its state does not affect the hub session, and upstream credentials give no access to the hub or other panels. Registration: `ApiAdminServiceProvider::registerUpstreamGuard`. `moonshine/multi-panels` runs `MultiPanelMiddleware` before authentication, so MoonShine's `Authenticate` checks the panel guard. Coverage: `DelegatedPanelStateTest`.

### Cross-service relation specs

- For relation fields (`belongs_to`, `belongs_to_many`, `has_one`, `has_many`) use `target_service` when the target endpoint belongs to another registered service. The parent resource stays in the top-level `service`; `endpoint` is relative to the target service. Leave same-service relations without `target_service`.
- `target_service` is supported in `fields[]`, `filters[]`, `index_fields[]`, `form_fields[]`, `detail_fields[]`, and in `add_field.field`.
- For cross-service `has_one`/`has_many` the generator does NOT create a nested relation resource inside the parent service. It emits a field-level `->targetService(...)->endpoint(...)`; options, relation data reads and nested CUD go through the target service config and response mapping.
- Diagnostics: `LOG_LEVEL=debug` shows source/target service, endpoint and query keys; `WARNING` catches a missing target service and a request service mismatch; `ERROR` covers upstream failures without secrets or the full payload. Keep `LOG_LEVEL=info` or higher in production.

### Local Eloquent services

- For a monolithic Laravel application use a separate flow: `initialize_local_service` → `create_model_resource`. Do not mix it with the REST flow (`register_service` → `probe_endpoint` → `create_resource`).
- The local service config is stored as `kind: local`, `driver: eloquent`, without `base_url`, `api_key` and `response_mapping`. Default generation paths: `api-admin.local_resources_path = app/MoonShine/Resources`, namespace `api-admin.local_resources_namespace = App\MoonShine\Resources`.
- `create_model_resource.fields[]` accepts only scalar/UI types: `id`, `text`, `textarea`, `number`, `email`, `password`, `date`, `datetime`, `checkbox`, `switcher`, `select`, `image`, `file`, `json`, `color`, `slug`, `url`. REST relation/upstream types are not valid here.
- Data tools (`probe_endpoint`, `fetch_resource_items`, `fetch_resource_item`, item CRUD, analytics) go through `AbstractRestResource` and stay REST-only. `list_services` shows local services and resource counts; `list_resources` targets REST resources.
- One visible local service hides the service/panel dropdown in the UI; several local panels or any REST service keep the switcher visible.
- An explicit `create_model_resource.panel` must equal the registered service panel (`LOCAL_PANEL_MISMATCH` otherwise). Local model resources share their namespace: a name already owned by another panel is rejected, while the owning panel can regenerate it. `MoonShineUser` and `MoonShineUserRole` are reserved, including spelling/case aliases. Local `register_service` and `remove_service` reject `admin-hub` aliases to preserve `AdminHubLayout`.
- `remove_resource` and `remove_service` delete local resources only when their explicit panel matches the service; native and unassigned resources are preserved.
- Do not instruct an agent to edit generated local files by hand: iteration must go through MCP tools.

### Per-page field arrays (`index_fields` / `form_fields` / `detail_fields`)

Additional top-level spec keys that **REPLACE** (do not merge with) the canonical `fields[]` for a specific page. Use them when index/form/detail need fundamentally different field sets that subtraction through `hide_on` cannot express (for example, a mass-mutation form page with its own `bulk_*` fields absent from index/detail). An empty override (`detail_fields: []`) is treated as absent (it does not blank the page). An empty `<page>_fields` falls back to the canonical `fields[]` filtered by `hide_on`. The sidecar round-trips these keys like any other params. Delta tools (`add_field`/`remove_field`/`rename_field`) keep all 4 arrays internally consistent; otherwise a column silently disappears on a page using an override.

### Custom fields / components

Custom fields and components are created through the MCP tools `create_field` / `create_component`. Source of truth:
- **Fields:** `app/ApiAdmin/Fields/Custom/` + `api-admin.custom_fields` in `config/api-admin.php`. `ResourceGenerator::fieldTypeCatalog()` automatically merges the config over builtins.
- **Components:** `app/ApiAdmin/Components/Custom/` + `resources/views/api-admin/components/custom/` + `api-admin.custom_components`.

Both generators edit `config/api-admin.php` through `ConfigArrayBlock`, which works on PHP tokens and refuses invalid results: a broken file stops the application and the MCP server. Tests of these tools use `Tests\ApiAdmin\Concerns\UsesIsolatedProjectRoot` and must re-read the file, not only the in-memory config.

An agent must first make sure no ready primitive exists (`list_fields` + `describe_field_type` / `list_components`): custom generation is for cases where built-in primitives truly do not fit.

### CRUD on resource items

Data reads (`fetch_resource_items`, `fetch_resource_item`) and data writes (`create_resource_item`, `update_resource_item`, `delete_resource_item`) reach the upstream through the same `AbstractRestResource` methods as the admin UI, that is, on top of `mutations.*` routing, the per-resource `detail_strategy` override and `parent_context` for relation endpoints. All CUD tools respect the `actions` allowlist from the spec sidecar (`.ai-factory/specs/<service>/<resource>.json` key `actions: [view, create, update, delete]`) through `ResourceActionResolver`. If the allowlist forbids an action, the tool returns `ACTION_NOT_ALLOWED` before any network call.

### Tool response contract

All tools return a JSON object. Success: `{success: true, ...payload}`. Error: `{success: false, error, code, hint?}`. Error codes (not exhaustive): `VALIDATION`, `CONFIRM_REQUIRED`, `SERVICE_NOT_REGISTERED`, `NETWORK_ERROR`, `UPSTREAM_5XX`, `ACTION_NOT_ALLOWED`, `RESOURCE_NOT_FOUND`, `NOT_FOUND`, `FIELD_TYPE_UNKNOWN`, `FIELD_NOT_FOUND`, `FIELD_IN_USE`, `COMPONENT_NOT_FOUND`, `COMPONENT_IN_USE`. An agent must read `code` first, then `hint`; `error` is for logs and users.

### Typical developer flow

REST microservice:

1. Start moonshine-v.
2. Create `.mcp.json` in your project.
3. `list_services` → see what already exists.
4. `register_service(name, base_url, api_key, auth_type, response_format, realtime?)` — use a preset. `auth_type`: `bearer` (default, Sanctum PAT / OAuth / GitHub PAT), `header` (+ `auth_header`), `query`, `basic` (api_key = "user:password"), `oauth2_cc` (client-credentials flow: requires `token_url` + `oauth_client_id` + `oauth_client_secret` + optional `oauth_scope`; the token auto-refreshes through the tenant-scoped Laravel Cache with TTL = `expires_in − 30s`), `oauth2_password`, `oauth2_refresh_token`, `session`, `none`. For service-backed admin sign-in set `admin_login: true`: the password is not stored in moonshine-v, the user enters it on `/<panel>/login`, and the panel keeps only tenant-scoped token/cookie state in the Laravel session. There are three per-panel admin auth modes: `moonshine` — the local `moonshine_users` table; `delegated_token` — a person enters an upstream token/PAT, the panel validates it against `admin_auth.validate_endpoint` and syncs the user; `oauth2_authorization_code` — browser SSO through `/{$panel}/oauth/redirect` + `/{$panel}/oauth/callback`, PKCE S256, token exchange and sync through `/me`. Always set `admin_auth_mode` separately from `auth_type`: `auth_type` is how the server calls the upstream API; `admin_auth_mode` is how a person signs in to the panel. Do not pass `"delegated_token"`/`"oauth2_authorization_code"` as `auth_type`. SSO limits: SAML-only and full OIDC discovery/JWKS/ID-token validation are not separate built-in modes yet; if you need SAML, OIDC discovery/JWKS, a multi-provider chooser or another enterprise SSO protocol, open an issue in the project repository so it can be evaluated. For upstream realtime pass `realtime.enabled: true`; hosted requires a non-empty `webhook_secret`, and subscriptions use private channels and `/broadcasting/auth` with panel guard and membership checks. `webhook_secret` is stored in `<SERVICE>_WEBHOOK_SECRET` locally or in `TenantSecretStore` in hosted mode; Laravel Reverb is configured through the regular broadcasting config. The full breakdown of auth and realtime options is in `LLMS.txt` and `McpServerCommand::agentInstructions()`.
5. `probe_endpoint(service, path: "/<entity>")` — confirm the format and primary_key.
6. `create_resource(service, name, endpoint, fields: [...])`.
7. Open `/<panel>/resource/<service>-<entity>-resource/<entity>-index-page` in a browser (the link is in the panel menu).
8. If needed, `remove_resource` / `remove_service` and start over.

Local monolith / Eloquent:

1. `list_services` → check whether a local service already exists.
2. `initialize_local_service(name, panel?, title?, auth_type?: "local")`.
3. `create_model_resource(service, name, model: "App\\Models\\Post", fields: [...], rules?, search?, actions?)`.
4. Open `/<panel>/resource/<entity>-resource/<entity>-index-page` in a browser. The panel uses `App\ApiAdmin\Layouts\LocalPanelLayout`: the menu has the panel's resources and system resources, and the switcher is shared with REST panels.
5. If you need a different field set, repeat generation through the MCP tool; do not edit generated files by hand.

The detailed step-by-step smoke checklist is `docs/SMOKE_CHECKLIST.md`.

### Keeping stubs, tests, LLMS.txt and MCP instructions in sync

- Any change to an MCP tool (schema, behavior) must be reflected in:
  1. the code in `app/ApiAdmin/MCP/Tools/*`;
  2. tests (`tests/ApiAdmin/Unit/*ToolTest.php` / `tests/ApiAdmin/Integration/*ToolTest.php`);
  3. the "MCP tools" section of this `AGENTS.md`;
  4. **the root `LLMS.txt`** (the fallback for agents without an MCP client): keep the cheatsheet in sync for the keys `fields[]`, `actions`, `mutations`, `response_mapping`, `realtime`;
  5. **`McpServerCommand::agentInstructions()`** — the string the server returns in `initialize.instructions`. Agents receive it as system context on connection. It must list the same keys and conventions as `LLMS.txt`, but shorter.
- A mismatch between these points produces agents that confidently do the wrong thing: the schema description says X while `LLMS.txt`/instructions say Y.
- Response-format presets for `register_service` are the constant `RegisterServiceTool::RESPONSE_FORMAT_PRESETS`. When adding a preset, update the enum in the JSON Schema, the constant, and `ProbeEndpointTool::guessMapping()` so it can detect the format automatically.
- When adding a key to the field spec (for example, how `hide_on` was added), update the `fields.items.properties.*` description in `CreateResourceTool::schema()`, the logic in `ResourceGenerator`, the cheatsheet in `LLMS.txt`, and `agentInstructions()` in `McpServerCommand`. Treat these four points as one atomic commit; otherwise an agent sees contradictions between the schema description and the docs.

### Resource kind: main vs relation

`ResourceGenerator` distinguishes two resource types and uses different stubs:

- **main** (`resource.stub`) — extends `MultiPanelRestResource`, has `$panel` and `getGroup()`, appears in the panel menu, available at `/<panel>/resource/<service>-<name>-resource/...` (`MultiPanelRestResource::getUriKey()`).
- **relation** (`relation-resource.stub`) — extends `AbstractRestResource`, without `$panel`/`getGroup()`, only a `has_one`/`has_many` target. The endpoint contains a `{parent_id}` placeholder.

Auto-detection is by the presence of `{` in the endpoint. Override with the `kind` parameter in `create_resource`/`update_resource`.

**Reusing existing resources.** If a parent's `create_resource` lists a has_one/has_many column whose resource already exists, the generator **does not overwrite** it and inserts a reference `resource: <>Resource::class`. This lets an entity be both standalone (`main`) and a relation target.

**Guard in `AbstractRestResource::guardAgainstUnresolvedEndpoint()`** throws a 404 when a relation-only resource is opened through a standalone URL (an endpoint with an unresolved `{parent_id}`). It protects against routing errors.

Tests: `ResourceGeneratorTest::test_generator_uses_relation_stub_when_endpoint_has_parent_placeholder`, `test_generator_reuses_existing_resource_for_has_one`, `test_generator_kind_override_wins_over_endpoint_heuristic`.

## UI checks

To verify interface changes, run `npm run test:e2e` and extend the specs with the new behavior. For manual checks use **Playwright MCP** (`mcp__playwright__*`):
- Dev server: `http://127.0.0.1:8000`
- Administrator: created through `php artisan moonshine:user`
- Typical flow: `browser_navigate` → `browser_snapshot` → `browser_fill_form`/`browser_click` → `browser_console_messages` (error) + `browser_network_requests` on failures.
- Do not report "it works" without a real browser check; if Playwright is unavailable, say so directly.

### Upstream path and OAuth rotation safety

Record IDs and endpoint path placeholders go through `Http\PathSegment`: reject empty/dot segments, slashes, backslashes, percent escapes and controls before HTTP; encode other opaque IDs as one segment. Mutation bodies retain raw values. Batch deletion validates every ID before the first write.

`OAuth2TokenStore` serializes cached grants with `Cache::lock` and rechecks the access token under that lock. A delayed 401 carries the rejected bearer so it can reuse another worker's refreshed token. Rotated refresh tokens are encrypted in `api_admin_tenant_secrets`, scoped by tenant/service/user/client/seed; legacy cache entries migrate on first use. Delegated refresh uses only the supplied user's token.

Local `.agents/` and `.claude/` toolkits, `.ai-factory.json`, `skills-lock.json` and the working notes in `.ai-factory/{PLAN.md,plans,patches,qa}` are ignored by git; keep project conventions in this file.
