[← MCP Workflow](mcp-workflow.md) · [Back to README](../README.md) · [Configuration →](configuration.md)

# MCP Tools Reference

MoonShine V exposes MCP tools for AI agents to manage the admin panel,
inspect generated resources, read/write resource data, run analytics, and check
the built-in instructor onboarding status.

There are two generation flows:

- REST microservices: `register_service` -> `probe_endpoint` -> `create_resource`.
- Local Laravel monoliths: `initialize_local_service` -> `create_model_resource`.

Keep them separate. REST tools work through `AbstractRestResource` and upstream
HTTP response mapping. Local tools generate native MoonShine `ModelResource`
classes for Eloquent models and do not use `base_url`, upstream auth, probes,
or `response_mapping`.

## All Tools

This page covers the core flows. [Detailed MCP schemas](api-admin/mcp-tools.md)
document the rest, and `tools/list` always returns the live schemas.

| Family | Tools |
|--------|-------|
| Services | `register_service`, `initialize_local_service`, `configure_layout`, `list_services`, `remove_service`, `probe_endpoint`, `health_check` |
| Resources | `create_resource`, `create_model_resource`, `update_resource`, `get_resource`, `list_resources`, `remove_resource` |
| Field deltas | `add_field`, `remove_field`, `rename_field`, `reorder_fields` |
| Data | `fetch_resource_items`, `fetch_resource_item`, `create_resource_item`, `update_resource_item`, `delete_resource_item` |
| Analytics | `count_resource`, `aggregate_resource` |
| Introspection | `list_fields`, `describe_field_type`, `list_field_methods`, `list_components`, `instructor_status` |
| Extensions | `create_field`, `update_field`, `delete_field`, `create_component`, `update_component`, `delete_component` |

## Hosted MCP

Local/self-hosted installs usually run `php artisan api-admin:mcp-server` over
stdio. Hosted installs expose HTTPS Streamable HTTP MCP routes such as
`https://admin.example.com/t/acme/api-admin/mcp`. Developer `.mcp.json` files
should contain only that hosted URL and a tenant-bound bearer token; upstream
API credentials stay in the hosted tenant secret store.

Hosted uses stateless Streamable HTTP with JSON responses. Connect to the RPC
URL above using an HTTP MCP client and a bearer token. Notifications receive an
empty `202`; GET receives `405` because server streams are not offered. The old
`/mcp/sse` endpoint is not supported. Enabled hosted realtime requires a nonempty
`webhook_secret`; omitted secrets are preserved on re-registration.

Hosted tokens grant coarse tool families (`tools:read`, `tools:generate`,
`tools:data:read`, `tools:data:write`, `tools:destructive`). Every tool call
also checks the active tenant context, so cross-tenant service/resource access
is denied even when the token has the requested scope.

## register_service

Creates a new panel for a microservice.

**Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `name` | string | yes | Service identifier (used in URL prefix and config) |
| `base_url` | string | yes | Base URL of the microservice API |
| `api_key` | string | yes | API key for authentication |
| `auth_type` | string | no | Server-to-upstream API auth scheme, such as `bearer`, `header`, `query`, `basic`, `oauth2_cc`, `oauth2_password`, `oauth2_refresh_token`, `session`, or `none` |
| `admin_login` | boolean | no | Legacy delegated username/password panel login for `oauth2_password` or `session` services |
| `admin_auth_mode` | string | no | Human panel login mode: `moonshine`, `delegated_token`, or `oauth2_authorization_code` |
| `admin_auth` | object | no | Per-panel login metadata. Use `validate_endpoint` for `delegated_token`; use `authorize_url`, `token_url`, `scopes`, and `redirect_uri` for OAuth2 SSO |
| `title` | string | no | Human-readable panel title (defaults to `name`) |
| `response_mapping` | object | no | API response format mapping (see [Configuration](configuration.md)) |
| `palette` | object | no | Color palette for the panel |

Do not put panel login modes in `auth_type`. Use `auth_type` for how the
server talks to the upstream API, and `admin_auth_mode` for how a human signs
in to the panel.

**Panel login modes:**

| Mode | Description |
|------|-------------|
| `moonshine` | Default local MoonShine login through `moonshine_users` |
| `delegated_token` | Admin enters an upstream PAT/API token, validated through `admin_auth.validate_endpoint` |
| `oauth2_authorization_code` | Browser SSO through OAuth2 Authorization Code + PKCE, using `admin_auth.authorize_url` and `admin_auth.token_url` |

`oauth2_authorization_code` covers organizations whose central IdP exposes an
OAuth2/OIDC-compatible Authorization Code flow. SAML-only SSO and full OIDC
discovery/JWKS/ID-token validation are not separate modes yet. If a user
needs those or another enterprise SSO protocol, ask them to open an issue in the project repository so
we can evaluate adding it.

**What it creates:**
- Service and panel entries in `storage/api-admin/generated/`
- `ServiceConfig` PHP file with base URL, auth, and response mapping
- Panel layout class

**Example:**

```json
{
  "name": "blog",
  "base_url": "https://api.blog.example.com",
  "auth_type": "none",
  "title": "Blog Admin",
  "response_mapping": {
    "data_key": "data",
    "total_key": "meta.total",
    "per_page_key": "meta.per_page"
  }
}
```

**OAuth2 SSO example:**

```json
{
  "name": "crm-api",
  "base_url": "https://crm.example.com/api",
  "auth_type": "none",
  "oauth_client_id": "<client_id>",
  "oauth_client_secret": "<provided by IdP; keep in hosted tenant secret storage>",
  "admin_auth_mode": "oauth2_authorization_code",
  "admin_auth": {
    "authorize_url": "https://idp.example.com/oauth/authorize",
    "token_url": "https://idp.example.com/oauth/token",
    "scopes": ["openid", "profile", "email"],
    "redirect_uri": "https://moonshine.example.com/crm-admin/oauth/callback"
  },
  "response_format": "laravel"
}
```

## initialize_local_service

Creates a local MoonShine panel for native Eloquent-backed resources.

Use this for monolith apps where the admin should manage models in the same
Laravel application. Do not pass REST-only keys such as `base_url`, `api_key`,
`response_mapping`, OAuth/session/admin auth settings, `health_path`, or
`realtime`; the tool rejects them with `LOCAL_ONLY`.

**Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `name` | string | yes | Local service identifier, e.g. `admin` or `catalog` |
| `panel` | string | no | Panel key and route prefix. Defaults to `<name>-admin` |
| `title` | string | no | Panel display title |
| `auth_type` | string | no | `local` or `none`; omit for the normal local MoonShine guard |

**Example:**

```json
{
  "name": "backoffice",
  "panel": "admin",
  "title": "Backoffice"
}
```

## create_model_resource

Generates a native MoonShine `ModelResource` and page classes for an existing
Eloquent model in a registered local service.

**Parameters:**

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
| `actions` | array | no | Allowlist: `create`, `view`, `update`, `delete`, `mass_delete` |
| `icon`, `group`, `title`, `column`, `detail` | mixed | no | Native MoonShine resource metadata |

Local field types: `id`, `text`, `textarea`, `number`, `email`, `password`,
`date`, `datetime`, `checkbox`, `switcher`, `select`, `image`, `file`, `json`,
`color`, `slug`, `url`. REST relation/upstream field types are intentionally
not accepted.

The tool refuses REST services with `LOCAL_SERVICE_REQUIRED` and missing or
non-Eloquent models with `MODEL_NOT_FOUND`. A single visible local service
hides the service dropdown; multiple local panels, or any REST service, keep
the switcher visible.

**Example:**

```json
{
  "service": "backoffice",
  "name": "post",
  "model": "App\\Models\\Post",
  "title": "Posts",
  "fields": [
    { "column": "id", "type": "id", "hide_on": ["form"] },
    { "column": "title", "type": "text", "methods": ["required", "sortable"] },
    { "column": "status", "type": "select", "options": { "draft": "Draft", "published": "Published" } },
    { "column": "published_at", "type": "datetime", "hide_on": ["form"] }
  ],
  "rules": { "title": "required|string|max:255" },
  "search": ["title"]
}
```

## create_resource

Generates a REST resource with fields for a specific API endpoint.

**Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `service` | string | yes | Service name (must be registered) |
| `name` | string | yes | Resource name (PascalCase, e.g., "Posts") |
| `endpoint` | string | yes | API endpoint path (e.g., "/api/posts") |
| `fields` | array | yes | Field definitions (see below) |
| `title` | string | no | Menu item title (defaults to `name`) |
| `primary_key` | string | no | Primary key field name (defaults to "id") |

**Field definition:**

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| `column` | string | yes | Field name matching API response key |
| `type` | string | yes | Field type: `text`, `number`, `email`, `date`, `textarea`, `select`, `belongs_to`, `has_many`, `has_one`, `belongs_to_many` |
| `label` | string | no | Display label |
| `methods` | array | no | Chainable field methods such as `["sortable", "required"]` |
| `endpoint` | string | no | Endpoint for relation fields |
| `target_service` | string | no | Target service for cross-service relation fields. Omit for same-service relations; relation option loading, has_one/has_many reads, and nested relation actions use the target service config and response mapping. |
| `value_key` | string | no | Relation value key (defaults to `id`) |
| `label_key` | string | no | Relation label key (defaults to `name`) |

`filters[]` uses the same field shape, including `target_service` for
cross-service relation filters.

**Cross-service relation example:**

```json
{
  "service": "blog",
  "name": "Posts",
  "endpoint": "/api/posts",
  "fields": [
    { "column": "id", "type": "id" },
    {
      "column": "brand_id",
      "type": "belongs_to",
      "label": "Brand",
      "target_service": "shop",
      "endpoint": "/api/brands",
      "label_key": "name"
    },
    {
      "column": "variants",
      "type": "has_many",
      "target_service": "shop",
      "endpoint": "/api/products/{parent_id}/variants"
    }
  ]
}
```

The parent resource still uses the top-level `service`; relation HTTP
calls use the target service's registered auth and `response_mapping`.
Omit `target_service` for same-service relations. Cross-service
`has_one` / `has_many` fields do not generate a nested relation resource
inside the parent service; they use field-level `targetService()` and
`endpoint()` for list, preview, create, update, and delete operations.
Use `endpoint_query` for target APIs that require scope parameters.

For debugging, enable `LOG_LEVEL=debug` to see target-resolution logs.
Warnings cover missing target services or request metadata that disagrees
with the field definition; upstream failures are logged as errors with
redacted context. Use `LOG_LEVEL=info` or higher to quiet verbose traces
in production.

**Example:**

```json
{
  "service": "blog",
  "name": "Posts",
  "endpoint": "/api/posts",
  "fields": [
    { "column": "id", "type": "id", "methods": ["sortable"] },
    { "column": "title", "type": "text", "methods": ["required", "sortable"] },
    { "column": "body", "type": "textarea", "methods": ["required"] },
    {
      "column": "author_id",
      "type": "belongs_to",
      "label": "Author",
      "target_service": "users",
      "endpoint": "/api/authors",
      "label_key": "name"
    },
    { "column": "created_at", "type": "date", "methods": ["sortable"] }
  ]
}
```

## configure_layout

Customizes the panel layout: logos and title. Menu items are discovered from the
resources that belong to the panel; use `group` and `icon` on `create_resource`
to organize them.

Omitted logos and title keep their existing values. The title is persisted in the
generated panel registry. Unsupported custom logo methods return an error rather
than reporting a successful update. Re-registering a service regenerates its
layout and panel settings; reapply customizations afterwards.

**Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `service` | string | yes | Service name |
| `logo` | string | no | Logo image path |
| `logo_small` | string | no | Small logo image path |
| `title` | string | no | Panel title override |

**Example:**

```json
{
  "service": "blog",
  "logo": "/images/blog-logo.svg",
  "title": "Blog"
}
```

## instructor_status

Read-only status for the browser onboarding wizard. The wizard lives on a
MoonShine-native admin page, and `/api-admin/instructor` remains as a
compatibility URL that opens it. The tool returns whether a session is active
and includes a sanitized proposal summary when available. It does not register
services, create resources, or expose secrets.

In hosted mode, an explicit `session_id` is looked up only inside the active
tenant. Sessions from other tenants and legacy unscoped sessions are unavailable.

## health_check

Pings one registered service or every service visible to the active tenant.
Hosted mode never checks another tenant's services.

**Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `service` | string | no | Restrict to one tenant-visible registered service |

**Response shape:**

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

In hosted mode, `diagnostics.warnings` reports degraded deployment backends
such as `CACHE_STORE=array|file`, `SESSION_DRIVER=array|file|cookie`,
`QUEUE_CONNECTION=sync`, or `BROADCAST_CONNECTION=log|null`.

## fetch_resource_items

Fetch a paginated list of items from a registered resource through the same
data layer the admin-panel UI uses (`AbstractRestResource::getItems()`).
Filter format, pagination strategy, sort/search param names, auth headers,
and `default_query` all apply automatically — the agent doesn't hand-build
query strings or guess paginator shapes.

**Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `service` | string | yes | Registered service name |
| `resource` | string | yes | Resource name in snake_case (e.g. `post`, `comment`) |
| `filter` | object | no | Filter map, serialised per the resource's `filter_format` (nested / flat) |
| `search` | string | no | Free-text search; routed via configured `search_param_name` |
| `sort` | string | no | Sort column, prefix `-` for descending (e.g. `-created_at`) |
| `page` | integer | no | Page number (length_aware / link_header paginators) |
| `per_page` | integer | no | Items per page (1–200) |
| `cursor` | string | no | Opaque token — pass `pagination.next_cursor` from a prior call |
| `parent_context` | object | no | Required for relation resources (`{parent_id: 7}`) |

**Response shape:**

```json
{
  "success": true,
  "service": "blog",
  "resource": "post",
  "endpoint": "/posts",
  "data": {
    "items": [{ "id": 1, "title": "..." }],
    "pagination": {
      "strategy": "length_aware",
      "current_page": 1,
      "per_page": 25,
      "total": 142,
      "last_page": 6,
      "has_more": true,
      "next_page_url": "...",
      "prev_page_url": null
    }
  }
}
```

Cursor paginators add `next_cursor` to `pagination`. Link-header paginators
emit the parsed `next_page_url` (and `last_page` when a `rel="last"` URL is
present). `strategy: "none"` maps to `pagination_type: "none"` services
where the upstream returns every row.

**Relation resources (`/posts/{parent_id}/comments`):**

Without `parent_context` the tool returns:

```json
{
  "success": false,
  "code": "RELATION_RESOURCE_REQUIRES_PARENT",
  "placeholders": ["parent_id"],
  "hint": "Pass parent_context with keys matching the placeholders ..."
}
```

On non-relation resources, extra `parent_context` keys are silently dropped
and the response carries `ignored_parent_context: true`.

**Upstream errors** pass through as `{success: false, code: "UPSTREAM_ERROR",
status: 5xx, error_body: {…}}` (first 50 top-level keys; raw text capped at
2KB). Use `probe_endpoint` to debug the shape, then retry.

## fetch_resource_item

Fetch a single item by id through `AbstractRestResource::findItem()`. Honors
the resource's `detail_strategy` override (`endpoint` → `GET /resource/{id}`,
`list` → `GET /resource?<key>={id}` + first-match).

**Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `service` | string | yes | Registered service name |
| `resource` | string | yes | Resource name in snake_case |
| `id` | string \| integer | yes | Item id matching `primary_key` |
| `parent_context` | object | no | Required for relation resources |

**Response shape:**

```json
{
  "success": true,
  "service": "blog",
  "resource": "post",
  "endpoint": "/posts",
  "item": { "id": 1, "title": "...", "body": "..." }
}
```

`primary_key` is guaranteed at the top level of `item`, so agents can refer
to `item.{primary_key}` uniformly even when the upstream omits the key in
nested shapes. A 404 from the upstream returns
`{success: false, code: "NOT_FOUND"}`.

## Hot-Reload

After any tool call that generates or modifies files, the MCP server automatically triggers hot-reload:

1. `composer dump-autoload` — register new classes
2. `php artisan moonshine:optimize-clear` — clear MoonShine cache
3. `php artisan config:clear` — clear config cache

The new resource appears in the admin panel without container restart.

## See Also

- [Getting Started](getting-started.md) — connect your agent and use these tools
- [Configuration](configuration.md) — response mapping format details
