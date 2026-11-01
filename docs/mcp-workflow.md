[← Interactive Installation](installation.md) · [Back to README](../README.md) · [MCP Tools →](mcp-tools.md)

# MCP Workflow

The calls below use readable pseudocode; send their arguments as JSON through your MCP client.


The AI agent (Claude Code, another LLM) talks to moonshine-api-admin through the MCP server. There are two separate generation paths: REST microservices use `register_service` -> `probe_endpoint` -> `create_resource`; local Laravel apps use `initialize_local_service` -> `create_model_resource`.

## Agent and operator responsibilities

After installation, agents configuring panels should use MCP tools for registration,
probing, generation, updates and removal. These tools handle cache clearing and
hot-reload. Report a failed refresh as a tool issue instead of manually editing
generated files. Shell commands in the installation and deployment guides are
for operators and contributors setting up or maintaining the application.

In hosted mode, connect to `https://<host>/t/<tenant>/api-admin/mcp` with a
tenant-bound bearer token. Authorization checks tenant ownership and tool scopes
on every call. Service and panel names must be **unique across the installation**;
a collision returns `MCP_NAME_CONFLICT`. Browser administrators need explicit
tenant membership, independent of developer MCP tokens.

Hosted generation allows REST resources with approved field types, methods and
validation rules. Custom PHP/Blade generators, native File/Image fields and local
Eloquent generation are unavailable. See [Hosted security](hosted-security.md).

### REST flow

#### 1. Register the service

```
register_service(
  name: "tasks",
  base_url: "http://localhost:8081",
  api_key: "<optional>",
  auth_type: "bearer" | "header" | "query" | "none",
  response_format: "laravel" | "django" | "flat" | "custom"
)
```

`response_format` picks the response envelope preset. If the API's shape doesn't fit a preset, pass `"custom"` and fill `response_mapping` (see step 3).

#### 2. Probe a representative endpoint

```
probe_endpoint(
  service: "tasks",
  path: "/tasks",
  detect_filter_for: { column: "status", value: "todo" }  // optional
)
```

Returns:

- `guessed_format`, `guessed_mapping` — response envelope + primary key
- `detected_columns` — field names from the first item (auto-detects plural-wrapped lists like `{"tasks":[...]}`)
- `guessed_filter_format` — `"nested"` (`?filter[x]=y`) or `"flat"` (`?x=y`), when `detect_filter_for` is passed
- `filter_format_detection` — raw baseline/nested/flat counts the decision was based on
- `hints` — actionable notes on 4xx / envelope shape

Use the output to finalize `response_mapping` and the field list before creating the resource.

#### 3. Adjust response_mapping if needed

If `probe_endpoint` returns `guessed_filter_format: "flat"` or the upstream doesn't support sorting, call `register_service` again with the corrected mapping:

```php
'response_mapping' => [
    'data_key'         => 'tasks',    // from guessed_mapping
    'primary_key'      => 'id',
    'filter_format'    => 'flat',     // ?status=todo instead of ?filter[status]=todo
    'supports_sorting' => false,      // suppress ?sort=... when the API ignores it
],
```

Ignored sort parameters make the UI appear to sort without changing upstream results — set `supports_sorting: false` when the upstream handler doesn't parse `sort`.

#### 4. Create the resource

```
create_resource(
  service: "tasks",
  name: "task",
  endpoint: "/tasks",
  fields: [
    { column: "id",          type: "id",       hide_on: ["form"] },
    { column: "title",       type: "text",     methods: ["required"] },
    { column: "description", type: "textarea" },
    { column: "status",      type: "select",
      options: {todo: "Todo", in_progress: "In Progress", done: "Done"} },
    { column: "assignee_id", type: "text",     label: "Assignee" },
    { column: "created_at",  type: "date",     hide_on: ["form"] },
    { column: "updated_at",  type: "date",     hide_on: ["form"] }
  ],
  filters: [ { column: "status", type: "select", options: {todo: "Todo", done: "Done"} } ],
  rules:   { title: "required|string|max:255", assignee_id: ["nullable", "uuid"] },
  search:  ["title", "id"]
)
```

Key fields-spec semantics:

- `methods`: chainable field methods (`["sortable", "required", "nullable"]`). Local generation validates methods against the target field class and drops unknown names. Hosted generation additionally rejects methods outside its safety allowlist. **Do not use v2/v3 visibility helpers** (`hideOnForm`, `hideOnCreate`, `hideOnUpdate`) — they don't exist in MoonShine 5.
- `hide_on`: list of pages (`"index" | "form" | "detail"`) to omit this field from. Maps to "just don't include the field in that page's `fields()`", which is the MoonShine 5 idiom.
- `options`: static key→value map for `select`-type fields.
- Top-level `actions`: allowlist of CRUD actions the API actually supports (subset of `["create", "view", "update", "delete", "mass_delete"]`). Omit unsupported ones so the corresponding buttons don't render — e.g. task-manager APIs without `DELETE /tasks/{id}` pass `actions: ["create", "view", "update"]`.

The generator writes `App\MoonShine\ApiAdmin\Resources\Tasks\Task\{TaskResource.php, Pages/*.php}`. Resource metadata lives in `TaskResource`; fields live on the pages (`TaskIndexPage::fields()`, `TaskFormPage::fields()` wrapped in `Box::make([...])`, `TaskDetailPage::fields()`). If `rules` was passed, `TaskFormPage` gets a generated `rules()` method. If `mutations` was passed (step 5), `TaskResource` gets a `$mutations` property.

#### 5. CQRS-style APIs: declare mutation routing via MCP

If the upstream doesn't expose plain `POST /resource` / `PUT /resource/{id}` (e.g. task-manager splits updates across `PUT /tasks/{id}/assignee` and `POST /tasks/{id}/status-transitions`), pass a `mutations` block to `create_resource` / `update_resource`. The generator writes a `$mutations` property on the Resource class, and `AbstractRestResource::performCreate` / `performUpdate` dispatch against it at runtime — no custom PHP required.

```
create_resource(
  service: "tasks",
  name: "task",
  endpoint: "/tasks",
  fields: [ ... ],
  mutations: {
    "create": {
      "method": "POST",
      "path":   "/tasks",
      "body_from": ["title", "description"]
    },
    "update": [
      {
        "method": "POST",
        "path":   "/tasks/{id}/status-transitions",
        "body":   { "target_status": "{status}" },
        "when_present":  "status",
        "ignore_status": [409]
      },
      {
        "method": "PUT",
        "path":   "/tasks/{id}/assignee",
        "body":   { "assignee_id": "{assignee_id}" },
        "when_present": "assignee_id"
      }
    ]
  }
)
```

Semantics:
- Placeholders `{id}` → item key; `{column}` → value from the submitted form payload.
- `body_from`: subset of form columns to forward (mutually exclusive with `body`).
- `body`: literal body template with `{column}` interpolation.
- `when_present`: skip this update mutation when the named column is absent from the changeset.
- `ignore_status`: HTTP status codes to treat as success (e.g. `409` for "already in that state").
- After all applicable update mutations run, the resource re-fetches `GET /resource/{id}` so the form reflects canonical post-update state.

In a trusted local installation, if you need branching logic that this DSL can't express (conditional on old vs. new value, chained validation, etc.), you can still override `performCreate` / `performUpdate` in the Resource class — but for the common CQRS patterns the declarative form covers the whole flow end-to-end from MCP.

#### 6. Verify in the browser

Open the panel URL returned by the tool and choose the resource in its menu. Use the host and port printed by your installer (or port 8000 for `artisan serve`). If something looks wrong, re-probe and call `update_resource(...)` to regenerate it. Verify that your API supports each enabled action before trying writes.

#### 7. Clean up (destructive, confirm required)

```
remove_resource(service: "tasks", name: "task", confirm: true)
remove_service(name: "tasks", confirm: true)
```

See [MCP Tools Reference](mcp-tools.md) for full schemas, error codes, and hints.

### Local Eloquent flow

Use this when moonshine-api-admin is managing models in the same Laravel app:

```
initialize_local_service(
  name: "backoffice",
  panel: "backoffice-admin",
  title: "Backoffice"
)

create_model_resource(
  service: "backoffice",
  name: "post",
  model: "App\\Models\\Post",
  fields: [
    { column: "id", type: "id", hide_on: ["form"] },
    { column: "title", type: "text", methods: ["required", "sortable"] },
    { column: "status", type: "select",
      options: {draft: "Draft", published: "Published"} }
  ],
  rules: { title: "required|string|max:255" },
  search: ["title"]
)
```

Local services do not accept `base_url`, `api_key`, `response_mapping`, OAuth/session auth, health checks, or realtime settings. `create_model_resource` refuses REST services with `LOCAL_SERVICE_REQUIRED` and missing/non-Eloquent models with `MODEL_NOT_FOUND`. One visible local service hides the service dropdown; multiple local panels, or any REST service, keep it visible.


## See Also

- [MCP Tools Reference](mcp-tools.md) — schemas and parameters
- [Configuration](configuration.md) — authentication and response mapping
- [Example APIs](../examples/README.md) — runnable REST and authentication fixtures
