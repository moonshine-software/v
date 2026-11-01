# MCP Tools Reference

The MCP server provides tools for AI-driven admin panel management, data access,
analytics, extension generation, and onboarding status.

Generation has two separate paths:

- REST microservices: `register_service` -> `probe_endpoint` -> `create_resource`.
- Local Laravel monoliths: `initialize_local_service` -> `create_model_resource`.

REST tools use upstream HTTP, auth, and `response_mapping`. Local tools generate
native MoonShine `ModelResource` classes for Eloquent models and reject upstream
API settings.

## Hosted MCP

Self-hosted installs can keep using stdio (`php artisan api-admin:mcp-server`).
Hosted deployments expose tenant-scoped HTTPS Streamable HTTP endpoints, for example
`https://admin.example.com/t/acme/api-admin/mcp`. Developer machines only need
a `.mcp.json` entry with that URL and a tenant-bound bearer token.

Hosted uses stateless Streamable HTTP with JSON responses. Connect to the RPC
URL above using an HTTP MCP client and a bearer token. Notifications receive an
empty `202`; GET receives `405` because server streams are not offered. The old
`/mcp/sse` endpoint is not supported. Enabled hosted realtime requires a nonempty
`webhook_secret`; omitted secrets are preserved on re-registration.

The token may allow `tools:read`, `tools:generate`, `tools:data:read`,
`tools:data:write`, `tools:destructive`, or all tool families. Tool scope is not
the tenant boundary: every call is also checked against the active tenant, and
cross-tenant service/resource access is denied before dispatch.

## register_service

Register a new microservice in the admin panel.

**Creates:** ServiceConfig file, Layout class, and service and panel entries in `storage/api-admin/generated/`.

### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `name` | string | yes | Unique service identifier (e.g. "blog", "shop") |
| `base_url` | string | yes | API base URL |
| `panel` | string | no | Panel identifier (default: `{name}-admin`) |
| `title` | string | no | Panel display title |
| `auth_type` | string | no | `bearer`, `header`, `query`, `basic`, `oauth2_cc`, `oauth2_password`, `oauth2_refresh_token`, `session`, or `none` (default: `bearer`) |
| `auth_header` | string | no | Custom header name when auth_type is `header` |
| `admin_login` | boolean | no | For `oauth2_password` or `session`, validate `/<panel>/login` credentials against the upstream service and store only token/cookie-state in Laravel session. Do not pass upstream user passwords during registration in this mode. |
| `response_mapping` | object | no | API response key mapping |
| `realtime` | object | no | Upstream webhook + browser refresh settings. Use Laravel Reverb as the recommended broadcaster backend via normal Laravel broadcasting config; this stores the service webhook contract. |

### Response Mapping

| Key | Default | Description |
|-----|---------|-------------|
| `data_key` | `data` | Key containing items array |
| `total_key` | `total` | Total items count key |
| `per_page_key` | `per_page` | Items per page key |
| `current_page_key` | `current_page` | Current page number key |
| `per_page_param_name` | `per_page` | Upstream page-size request parameter (`page_size` for Django, `size` for Spring) |
| `zero_based_pages` | `false` | Translate upstream zero-based page numbers; UI/MCP remain one-based |
| `next_page_url_key` | `next_page_url` | Next URL in the response (`next` for Django) |
| `prev_page_url_key` | `prev_page_url` | Previous URL in the response (`previous` for Django) |
| `last_page_key` | `last_page` | Last page number key |
| `primary_key` | `id` | Primary key field name |

### Realtime

| Key | Default | Description |
|-----|---------|-------------|
| `enabled` | `false` | Enables `POST /api-admin/webhooks/{service}` ingest and MoonShine page refresh metadata |
| `webhook_secret` | empty | Required for enabled hosted realtime; stored in `TenantSecretStore` there and `.env` locally. Omitted values preserve the secret; disable hosted realtime before clearing it. |
| `webhook_signature_header` | `X-ApiAdmin-Signature` | Header containing the upstream signature |
| `webhook_signature_algo` | `sha256` | `hash_hmac` algorithm |
| `webhook_event_key` | `event` | Payload key for the upstream event alias |
| `webhook_resource_key` | `resource` | Payload key for the changed resource |
| `webhook_action_key` | `action` | Payload key for the mutation action |
| `webhook_id_key` | `id` | Payload key for the changed item id |
| `allowed_events` | `created`, `updated`, `deleted`, `upserted` | Allowed actions/events |
| `refresh_debounce_ms` | `750` | Browser refresh debounce |
| `refresh_strategy` | `auto` | `auto`, `notice`, or `disabled` |
| `sensitive_payload_keys` | common secret keys | Keys redacted from verbose webhook logs |

### Example

```json
{
  "name": "register_service",
  "arguments": {
    "name": "blog",
    "base_url": "https://api.blog.example.com/v1",
    "panel": "blog-admin",
    "title": "Blog Admin",
    "auth_type": "none",
    "realtime": {
      "enabled": true,
      "refresh_strategy": "auto"
    },
    "response_mapping": {
      "data_key": "data",
      "total_key": "total",
      "primary_key": "id"
    }
  }
}
```

---

## initialize_local_service

Register a local MoonShine panel for Eloquent-backed resources.

Do not pass `base_url`, `api_key`, `response_mapping`, OAuth/session/admin auth
settings, `health_path`, or `realtime`. Those are REST-only and return
`LOCAL_ONLY` here.

**Creates:** local service config (`kind: local`, `driver: eloquent`) and a
MoonShine panel entry.

### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `name` | string | yes | Unique local service identifier |
| `panel` | string | no | Panel identifier; defaults to `<name>-admin` |
| `title` | string | no | Panel display title |
| `auth_type` | string | no | `local` or `none`; omit for the normal local MoonShine guard |

### Example

```json
{
  "name": "initialize_local_service",
  "arguments": {
    "name": "backoffice",
    "panel": "admin",
    "title": "Backoffice"
  }
}
```

---

## create_model_resource

Create a native MoonShine `ModelResource` for an existing Eloquent model.

**Creates:** Resource PHP class plus Index/Form/Detail page classes under the
configured local resource namespace.

### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `service` | string | yes | Local service created by `initialize_local_service` |
| `name` | string | yes | Resource name, e.g. `post` |
| `model` | string | yes | Fully qualified Eloquent model class, e.g. `App\\Models\\Post` |
| `fields` | array | yes | Local field definitions |
| `index_fields` | array | no | IndexPage field list; replaces `fields` for index when present |
| `form_fields` | array | no | FormPage field list; replaces `fields` for form when present |
| `detail_fields` | array | no | DetailPage field list; replaces `fields` for detail when present |
| `rules` | object | no | Form validation rules keyed by column |
| `search` | array | no | Searchable model columns |
| `actions` | array | no | Allowlist of active actions |
| `icon`, `group`, `title`, `column`, `detail` | mixed | no | Native MoonShine resource metadata |

Local field types are scalar/UI only:
`id`, `text`, `textarea`, `number`, `email`, `password`, `date`, `datetime`,
`checkbox`, `switcher`, `select`, `image`, `file`, `json`, `color`, `slug`,
`url`. REST relation/upstream field types are not accepted.

REST services return `LOCAL_SERVICE_REQUIRED`; missing or non-Eloquent model
classes return `MODEL_NOT_FOUND`. One visible local service hides the
service/panel switcher; multiple local panels, or any REST service, keep it
visible.

### Example

```json
{
  "name": "create_model_resource",
  "arguments": {
    "service": "backoffice",
    "name": "post",
    "model": "App\\Models\\Post",
    "fields": [
      {"column": "id", "type": "id", "hide_on": ["form"]},
      {"column": "title", "type": "text", "methods": ["required", "sortable"]},
      {"column": "status", "type": "select", "options": {"draft": "Draft", "published": "Published"}}
    ],
    "rules": {"title": "required|string|max:255"},
    "search": ["title"]
  }
}
```

---

## create_resource

Create a new REST resource with fields for an existing service.

**Creates:** Resource PHP class, triggers hot-reload.

### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `service` | string | yes | Service name (must be registered first) |
| `name` | string | yes | Resource name (e.g. "post", "product") |
| `endpoint` | string | yes | API endpoint path (e.g. "/posts") |
| `title` | string | no | Display title |
| `panel` | string | no | Panel identifier |
| `fields` | array | yes | Field definitions |
| `search` | array | no | Searchable field column names |
| `filters` | array | no | Filter field definitions |

### Field Definition

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| `column` | string | yes | API field name |
| `type` | string | yes | Field type (see below) |
| `label` | string | no | Display label |
| `methods` | array | no | Method chain calls (e.g. `["sortable", "required"]`) |
| `endpoint` | string | no | API endpoint for relation fields |
| `target_service` | string | no | Target service for cross-service relation fields. Omit for same-service relations. Relation option loading, has_one/has_many reads, and nested relation actions use the target service config and response mapping. |
| `value_key` | string | no | Value key for relation fields (default: `id`) |
| `label_key` | string | no | Label key for relation fields (default: `name`) |

### Field Types

`id`, `text`, `textarea`, `number`, `email`, `password`, `date`, `datetime`, `checkbox`, `switcher`, `select`, `image`, `file`, `json`, `color`, `slug`, `url`, `belongs_to`, `has_many`, `has_one`, `belongs_to_many`

> **`password`** emits `MoonShine\UI\Fields\Password` with `->raw()` auto-applied by the generator. `->raw()` (shipped in MoonShine 4.x) disables `Hasher::make` on apply, so the raw submitted value is sent to the upstream API — the upstream is expected to hash the password, and double-hashing on our side would lock users out. Do not add `raw` to `methods` manually; the generator always emits it for `type: password`.

### Cross-Service Relations

Use `target_service` when a relation field points at a different
registered service than the parent resource. The parent still belongs to
top-level `service`; `endpoint` is resolved against the target service
base URL and uses the target service auth and response mapping.

```json
{
  "service": "blog",
  "name": "post",
  "endpoint": "/posts",
  "fields": [
    { "column": "id", "type": "id" },
    {
      "column": "brand_id",
      "type": "belongs_to",
      "label": "Brand",
      "target_service": "shop",
      "endpoint": "/brands",
      "value_key": "id",
      "label_key": "name"
    },
    {
      "column": "variants",
      "type": "has_many",
      "target_service": "shop",
      "endpoint": "/products/{parent_id}/variants"
    }
  ]
}
```

Same-service relations should omit `target_service`. For cross-service
`has_one` / `has_many`, the generator emits field-level
`targetService()` / `endpoint()` calls and does not create a nested
relation resource inside the parent service. If the target API requires
scope, keep using `endpoint_query`; placeholders still resolve from the
parent item/request context and empty placeholders are dropped.

Useful debugging logs:

- `DEBUG` target resolution with source service, target service,
  endpoint, and query keys.
- `WARNING` when a target service is missing or request-supplied service
  metadata differs from the field definition.
- `ERROR` for upstream failures, with exception/status context but no
  auth headers or payload dumps.

Set Laravel `LOG_LEVEL=info` or higher in production to reduce verbose
relation trace logs.

### Example

```json
{
  "name": "create_resource",
  "arguments": {
    "service": "blog",
    "name": "post",
    "endpoint": "/posts",
    "title": "Blog Posts",
    "fields": [
      {"column": "id", "type": "id"},
      {"column": "title", "type": "text", "label": "Title", "methods": ["sortable", "required"]},
      {"column": "body", "type": "textarea", "label": "Body"},
      {"column": "category_id", "type": "belongs_to", "label": "Category", "endpoint": "/categories"},
      {"column": "status", "type": "select", "label": "Status"}
    ],
    "search": ["title", "body"]
  }
}
```

---

## configure_layout

Customize a panel's layout: logos and title. Menu items are discovered from the
resources that belong to the panel.

Omitted values are preserved. Title changes persist in the generated panel
registry, and unsupported custom logo methods return an error. Re-registering
the service regenerates its layout and panel descriptor; reapply customizations
afterwards.

### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `service` | string | yes | Service name whose layout to configure |
| `logo` | string | no | Logo image path |
| `logo_small` | string | no | Small logo image path |
| `title` | string | no | Panel title override |

### Example

```json
{
  "name": "configure_layout",
  "arguments": {
    "service": "blog",
    "logo": "/images/blog-logo.svg",
    "title": "Blog"
  }
}
```

## Inspection and removal

### list_services

Returns registered services with `base_url`, `panel`, `auth_type`,
`resources_count` and generated file paths. Read-only.

### list_resources

Input: `{service?}`. Returns generated REST resources for all services or one
service. Read-only.

### get_resource

Input: `{service, name}`. Returns the spec last used to generate a resource,
read from `.ai-factory/specs/<service>/<resource>.json`. Call it before
`update_resource`, which is a full replace. Returns `SPEC_MISSING` when the
sidecar does not exist. Read-only.

### remove_service

Input: `{name, confirm: true}`. Removes the service config, layout, resources
and registry entries. Destructive.

### remove_resource

Input: `{service, name, confirm: true}`. Removes one resource directory with its
pages. Destructive. Removing a name that matches nothing succeeds with an empty
`removed_paths`.

Singular and plural forms of a resource name address the same resource in every
tool. An existing resource wins, so `posts` reaches an existing `Post`.

## Introspection (read-only)

Four tools let an agent discover available field types, methods, and
components without reading moonshine source. The generated resources
are reflected at runtime — adding a new type to `FIELD_TYPE_MAP` (or
via `create_field`) auto-advertises here.

### list_fields

Returns every usable `fields[].type` value with `group` (scalar /
relation / media), a short `description`, and `is_custom` flag.

### describe_field_type

Input: `{type}`. Returns the target UI class, parent class, every
chainable method (with signature + `accepts_condition: bool`), and
curated `schema_hints` for the common spec-level tweaks.

### list_field_methods

Input: `{type?}`. Union of chainable methods across every type, with
`field_types: [...]` reporting which types expose each one. Optional
`category` (visibility / validation / data / ui) is attached only
when a name-rule matches.

### list_components

Returns the layout primitives supported in `layout.form.tree` plus
any caller-generated components (`create_component`).

## health_check

Pings the configured health endpoint for every service visible to the active
tenant, or one service when `service` is passed. Hosted mode filters the list
before probing, so tenant A cannot health-check tenant B's services.

Response:

```json
{
  "success": true,
  "count": 1,
  "services": [
    {
      "service": "blog",
      "tenant_id": "1",
      "status": "ok",
      "code": 200,
      "url": "https://blog.test/api/health",
      "latency_ms": 32
    }
  ],
  "diagnostics": {
    "mode": "hosted",
    "tenant_id": "1",
    "cache_store": "redis",
    "session_driver": "redis",
    "queue_connection": "redis",
    "broadcast_connection": "reverb",
    "warnings": []
  }
}
```

Hosted diagnostics warn when cache, session, queue, or broadcasting are still
using local/development backends. Token values, upstream credentials, delegated
session state, webhook secrets, and tenant-sensitive payloads must not appear
in health logs or responses.

The live JSON snapshots are also published at
`moonshine-v://fields`, `://components`, `://field-methods` via
`resources/read`.

## Resource-item reads (read-only)

Both tools use the same `AbstractRestResource` path as the admin UI. See
[fetch_resource_items](../mcp-tools.md#fetch_resource_items) and
[fetch_resource_item](../mcp-tools.md#fetch_resource_item) for response shapes.

### fetch_resource_items

Input: `{service, resource, filter?, search?, sort?, page?, per_page?, cursor?,
parent_context?}`. Returns `{items, pagination}`. Relation resources without
`parent_context` return `RELATION_RESOURCE_REQUIRES_PARENT`.

### fetch_resource_item

Input: `{service, resource, id, parent_context?}`. Honours the per-resource
`detail_strategy` override and returns `{item}` with the primary key at the top
level. An upstream 404 surfaces as `NOT_FOUND`.

## Resource-item CRUD (data-write)

### create_resource_item

Input: `{service, resource, payload, parent_context?}`. POSTs through
the same `AbstractRestResource::createItem()` the UI uses, honouring
`mutations.create` routing. Relation resources require
`parent_context`. Returns `{success, item, primary_key_value}`.

### update_resource_item

Input: `{service, resource, id, payload, parent_context?}`. PUT/PATCH
with a pre-check via `findItem` — upstream 404 surfaces as
`NOT_FOUND` before the mutation fires. Honours `mutations.update`.

### delete_resource_item

Input: `{service, resource, id, confirm: true, parent_context?}`.
**Destructive** — `confirm: true` is required. Surfaces upstream 404
as `NOT_FOUND`.

All three respect the resource-level `actions` allowlist — a
resource whose spec declares `actions: ["view"]` refuses write verbs
with `ACTION_NOT_ALLOWED` before hitting the wire.

## Analytics (read-only)

Analytics tools answer conversational data questions through the same
generated `AbstractRestResource::getItems()` path as `fetch_resource_items`.
That means service auth, `default_query`, response mapping, filter format,
search parameter names, pagination strategy, and relation
`parent_context` are all honored.

### count_resource

Input: `{service, resource, filter?, search?, time_window?,
parent_context?, per_page?}`.

Returns `{success, service, resource, endpoint, count, source,
applied_filters}`. `source` is `upstream_total` when paginator metadata
contains a real total; otherwise it is `materialized_items`. If upstream
pagination says more pages exist but no total was available, the response
includes `warning: "PARTIAL_COUNT"`.

Example:

```json
{
  "name": "count_resource",
  "arguments": {
    "service": "blog",
    "resource": "post",
    "filter": {"status": "published"},
    "time_window": {
      "field": "created_at",
      "from": "2026-01-01",
      "to": "2026-01-31"
    }
  }
}
```

### aggregate_resource

Input: `{service, resource, operation, field?, group_by?, filter?,
search?, time_window?, parent_context?, limit?, per_page?}`.

`operation` is one of `count`, `sum`, `avg`, `min`, `max`. Numeric
operations require `field`; `count` does not. Without `group_by`, the
tool returns `{result, count}`. With `group_by`, it returns
`{groups: [{key, value, count}]}`. If pagination indicates more pages,
the response includes `warning: "PARTIAL_AGGREGATE"` because the value
is calculated over the materialized page.

Example:

```json
{
  "name": "aggregate_resource",
  "arguments": {
    "service": "shop",
    "resource": "order",
    "operation": "sum",
    "field": "total",
    "group_by": "status",
    "time_window": {
      "field": "created_at",
      "from": "2026-01-01"
    },
    "limit": 500
  }
}
```

`time_window` is translated into the normal filter map under the named
field, so it still follows `response_mapping.filter_format` and
`filter_param_name`; no upstream-specific query key is invented.

## Built-in instructor (read-only status)

The admin hub includes a MoonShine-native Instructor page for browser
onboarding. The compatibility URL `/api-admin/instructor` redirects there. The
flow asks for API base/auth details, probes safe GET endpoints, proposes a
`register_service` payload plus an initial `create_resource` spec, and requires
human confirmation before generated files are written.

### instructor_status

Input: `{session_id?}`.

Returns whether a browser instructor session is active and, when present,
a sanitized proposal summary. This tool is read-only: it does not call
`register_service`, does not call `create_resource`, and never returns
raw secrets.

Hosted lookup is limited to the active tenant, including explicit session IDs.
Sessions from another tenant or old unscoped cache entries are not returned.

Example:

```json
{
  "name": "instructor_status",
  "arguments": {
    "session_id": "browser-session-id"
  }
}
```

## Custom field / component generation

Use these when no built-in primitive fits. Always call `list_fields` +
`describe_field_type` (or `list_components`) first to confirm.

### create_field / update_field / delete_field

- `create_field({name, extends, alias?, methods_override?,
  properties?})` — emits `app/ApiAdmin/Fields/Custom/<Name>.php` and
  registers the type in `api-admin.custom_fields`. Returns the new
  type key (`Str::snake($name)`) for use in `fields[].type`.
- `update_field` — full-file rewrite. `FIELD_NOT_FOUND` when the
  field wasn't generated beforehand.
- `delete_field({name, confirm: true, force?})` — destructive. Scans
  every spec sidecar's `fields[]` for dependents; rejects with
  `FIELD_IN_USE` + `dependents: [{service, resource, columns}]`
  unless `force: true`.

### create_component / update_component / delete_component

Mirror of the field tools for layout components. `create_component`
also writes a paired blade view under
`resources/views/api-admin/components/custom/`. `delete_component`'s
dependents scan walks `layout.form.tree` recursively.

## Partial resource deltas

Single-field mutators that read the spec sidecar, splice in/out one
field, and re-emit the resource via the same `ResourceGenerator`
codepath as `create_resource`/`update_resource` — no parallel codepath,
no shape divergence. All four require an existing spec sidecar;
resources predating the sidecar return `SPEC_MISSING` (run
`update_resource` once with the full current spec to seed it).

### add_field

```
add_field({
  service: "blog",
  resource: "post",
  field: { column: "subtitle", type: "text" },
  position: { after: "title" },          // optional; default = append
  pages: ["index", "form", "detail"]     // optional; default = all 3
})
```

- Position keys `before` / `after` / `index` are mutually exclusive.
- `pages` subset → emits `hide_on` of the complement on the canonical
  field. Hidden on the unlisted pages.
- When per-page overrides (`<page>_fields`) exist on the spec, the new
  column is also appended to those overrides for pages in the
  visibility set — kept in sync so the column actually appears.
- The `field` object accepts the same relation keys as
  `create_resource.fields[]`, including `target_service` for
  cross-service relation fields.

Errors: `VALIDATION`, `SERVICE_NOT_REGISTERED`, `RESOURCE_NOT_FOUND`,
`SPEC_MISSING`, `FIELD_ALREADY_EXISTS`, `INVALID_POSITION`.

### remove_field

```
remove_field({
  service: "blog",
  resource: "post",
  column: "deprecated_field",
  confirm: true,
  force: false                           // optional; default false
})
```

- Destructive — `confirm: true` required.
- Cross-reference scan covers `search`, `rules` (flat + per-mode),
  `filters`, `layout.form.groups`, `layout.form.tree`,
  `mutations.create.body_from`/`body`/`when_present`/`path`
  placeholders, `mutations.update[*]` likewise, sibling fields'
  `depends_on` and `endpoint_query` placeholder values, and per-page
  overrides (`index_fields`/`form_fields`/`detail_fields`).
- Without references → field removed cleanly.
- With references → `FIELD_REFERENCED` + `references[]` listing every
  location, unless `force: true`. With `force` the dangling references
  are also stripped (mutation routes gated solely on the removed
  column via `when_present` are dropped entirely).

Errors: `VALIDATION`, `CONFIRM_REQUIRED`, `RESOURCE_NOT_FOUND`,
`SPEC_MISSING`, `FIELD_NOT_FOUND`, `FIELD_REFERENCED`.

### rename_field

```
rename_field({
  service: "tracker",
  resource: "task",
  from: "priority",
  to: "urgency",
  update_mutations: true                 // optional; default true
})
```

- Rewrites every reference automatically — `column` itself in all 4
  field-arrays, plus `search`, `rules`, `filters`, layout-tree leaves
  + groups, mutations (`body_from` / `when_present` / `body` keys /
  `path` placeholders), sibling `depends_on`, `endpoint_query`
  placeholder VALUES.
- `endpoint_query` keys are upstream API param names and stay bound to
  the upstream contract — they're NOT rewritten. Only the
  `{column}` placeholder values follow the local rename.
- `update_mutations: false` opts out of the mutation rewrite for the
  rare case where mutation paths are intentionally indexed by the old
  column name.
- Returns `references_updated: { fields, search, rules, filters,
  mutations, layout_groups, layout_tree, depends_on, endpoint_query }`
  so the caller can audit what was touched.

Errors: `VALIDATION`, `RESOURCE_NOT_FOUND`, `SPEC_MISSING`,
`FIELD_NOT_FOUND`, `FIELD_ALREADY_EXISTS` (when `to` already exists).

### reorder_fields

```
reorder_fields({
  service: "blog",
  resource: "post",
  order: ["title", "id"],                // partial by default
  strict: false                          // optional; default false
})
```

- `order` is partial — listed columns appear in the given order;
  unlisted columns retain relative order and are appended after.
- `strict: true` → exhaustive `order` required (`INCOMPLETE_ORDER`
  with `missing[]` if any column is omitted).
- Layout tree (`layout.form.tree` / `layout.form.groups`) is NOT
  touched — author-controlled. Visual group reordering belongs in
  `update_resource`.

Errors: `VALIDATION`, `RESOURCE_NOT_FOUND`, `SPEC_MISSING`,
`FIELD_NOT_FOUND`, `INCOMPLETE_ORDER`.

## Per-page field arrays

Three optional top-level spec keys that **REPLACE** (not merge with)
the canonical `fields[]` for the named page when present and
non-empty:

```
{
  "fields": [
    { "column": "id", "type": "id" },
    { "column": "title", "type": "text" },
    { "column": "body", "type": "textarea" }
  ],
  "form_fields": [
    { "column": "title", "type": "text" },
    { "column": "subtitle", "type": "text" },
    { "column": "body", "type": "textarea" }
  ]
}
```

- `index_fields` / `form_fields` / `detail_fields` — same shape as
  one entry of `fields[]`; `column` and `type` required per entry.
  Relation entries may include `endpoint`, `target_service`,
  `value_key`, `label_key`, `endpoint_query`, and `depends_on`.
- Empty override (`detail_fields: []`) is treated as **absent** — it
  does not blank the page.
- Delta tools (`add_field`, `remove_field`, `rename_field`) walk all
  4 arrays and keep them internally consistent. A new column added to
  canonical `fields[]` is also appended to any present
  `<page>_fields` override for pages in its visibility set; otherwise
  it would silently disappear on those pages.
- `hide_on` on the canonical field still applies in the no-override
  case. When an override is present for a page, `hide_on` for that
  page becomes irrelevant — the override is the complete list.
