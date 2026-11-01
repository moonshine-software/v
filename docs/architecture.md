[← Configuration](configuration.md) · [Back to README](../README.md) · [Production →](production.md)

# Architecture

## Overview

MoonShine V follows a **Modular Monolith** architecture. Each concern is a self-contained module with clear boundaries inside one Laravel application that runs in Docker.

```
┌─────────────────────────────────────────────────────────┐
│                     MCP Server                          │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐  │
│  │ RegisterSvc  │  │ CreateRes    │  │ ConfigLayout │  │
│  └──────┬───────┘  └──────┬───────┘  └──────┬───────┘  │
│         │                 │                  │          │
│         ▼                 ▼                  ▼          │
│  ┌─────────────────────────────────────────────────┐    │
│  │              Generator (stubs → PHP)             │    │
│  └─────────────────────────┬───────────────────────┘    │
│                            │                            │
│  ┌─────────────┬───────────┼───────────┬────────────┐   │
│  │ Resources   │  Fields   │ TypeCasts │ Validation  │   │
│  │             │           │           │             │   │
│  │ Abstract    │ BelongsTo │ Config.   │ ApiError    │   │
│  │ RestResource│ HasMany   │ ApiCaster │ Mapper      │   │
│  │ MultiPanel  │ HasOne    │           │             │   │
│  │ RestResource│ BelongsTo │           │             │   │
│  │             │ Many      │           │             │   │
│  └──────┬──────┴─────┬─────┴─────┬─────┴─────────────┘   │
│         │            │           │                       │
│         ▼            ▼           ▼                       │
│  ┌─────────────────────────────────────────────────┐    │
│  │              ServiceConfig (DTOs)                │    │
│  └─────────────────────────────────────────────────┘    │
└─────────────────────────────────────────────────────────┘
```

## Module Overview

| Module | Purpose | Dependencies |
|--------|---------|-------------|
| **ServiceConfig** | DTOs for service configuration (base_url, auth, response mapping) | None (leaf module) |
| **TypeCasts** | ConfigurableApiCaster — wraps any API format into MixedDataWrapper | ServiceConfig |
| **Validation** | ApiErrorMapper — maps API 422 errors to form fields | None (leaf module) |
| **Resources** | AbstractRestResource + MultiPanelRestResource — HTTP-based CrudResource | TypeCasts, ServiceConfig, Validation |
| **Fields** | RestBelongsTo, RestHasMany, RestHasOne, RestBelongsToMany | Resources (parent's HTTP client) |
| **Tenancy** | TenantContext, resolvers, route helpers, key builder, hosted secret store | App tenant models, cache/crypt |
| **MCP** | MCP server + hosted transport + tools + ResourceGenerator | All modules above, Tenancy |
| **Realtime** | Tenant-scoped webhook ingest, event normalization, broadcast channels | ServiceConfig, Tenancy |
| **Console** | MCP stdio server, hot-reload, tenant membership and secret migration commands | MCP, Tenancy |
| **Auth** | Upstream auth handlers, delegated admin login, retry on 401 | Tenancy |
| **Instructor** | Browser onboarding: probe, propose, apply through MCP tools | MCP, ServiceConfig |

## Application Structure

```
app/ApiAdmin/
├── Resources/                  # HTTP-based CrudResource extensions
├── Fields/                     # REST relation fields
├── Pages/                      # RestIndexPage, RestFormPage
├── TypeCasts/                  # Configurable API response casting
├── ServiceConfig/              # DTOs (base_url, auth, mapping, realtime)
├── Auth/                       # Upstream auth and delegated admin login
├── Tenancy/                    # Local/hosted tenant context and scoped keys
├── Realtime/                   # Tenant-scoped webhooks and browser refresh
├── Instructor/                 # Browser onboarding
├── Validation/                 # Two-layer validation with API error mapping
├── MCP/                        # MCP server, hosted transport, tools, code generators
│   ├── Tools/
│   └── Generator/
├── Console/                    # Artisan commands
└── Providers/                  # ServiceProvider (composition root)

app/MoonShine/ApiAdmin/         # Hub and Instructor pages, generated Configs/Layouts/Resources
config/api-admin.php            # Module configuration
stubs/api-admin/                # Code generation templates
storage/api-admin/generated/    # Runtime service and panel registry
docker/                         # Dockerfile, compose stacks, backup/restore, smoke test
tests/
```

## Key Design Decisions

### No Eloquent for REST services
REST service data flows through HTTP. CrudResource is the base class (not ModelResource). Relation fields use HTTP endpoints, never database queries, so upstream services can use any stack. Local Eloquent services are a separate flow: `create_model_resource` generates native MoonShine `ModelResource` classes in trusted local mode.

### Values Stay Raw Until Displayed
Form values reach the upstream API exactly as entered (`escapes.value_on_apply = false`) and are escaped when rendered. Custom fields must escape upstream data in `resolvePreview()`, because MoonShine treats preview output as HTML.

### Code Generation over Runtime Config
MCP tools generate actual PHP classes from stubs. The admin panel runs standard compiled PHP — no runtime config interpretation. This means:
- Full IDE support in generated code
- Standard MoonShine debugging
- No performance overhead from config parsing

### Service Auth Propagation
ServiceConfig holds auth credentials. AbstractRestResource creates an HTTP
client with those credentials. Same-service fields delegate through their
parent resource client. Cross-service relation fields resolve the target
through the registered ServiceConfig and use that service's HTTP client,
auth, and response mapping. No field creates ad hoc auth headers or
unauthenticated clients.

### Relation Target Resolution
`RestRelationTarget` is the small runtime boundary between relation fields
and registered services. It preserves same-service behavior when
`target_service` is omitted, and resolves explicit cross-service targets
through `ApiAdminServiceProvider::resolveService()`. Missing target
services fail closed: relation UI returns an empty state and logs a
warning instead of sending malformed HTTP.

Cross-service `has_one` and `has_many` use field-level endpoints for
list, preview, create, update, and delete operations. Generated nested
relation resource classes remain same-service only until there is a
separate contract for where cross-service relation resources should live.

### Multi-Panel Isolation
Each microservice gets its own panel with a unique URL prefix, layout, and palette. Panels don't share state. This maps to MoonShine's multi-panels mechanism with `$panel` property + router prefix override.

### Hosted Tenant Boundary

Local/self-hosted mode resolves a deterministic local tenant and keeps legacy
paths such as `/admin`, `/blog-admin`, and shared generated directories. Hosted
mode must resolve a real active tenant at the HTTP or MCP boundary before any
service, panel, resource, generated file, auth state, webhook, or runtime data
access.

Hosted admin and support routes are prefixed by `t/{tenant}` by default.
Generated resources, configs, layouts, and spec sidecars are stored under a
tenant path segment. Service API keys, OAuth/session secrets, webhook secrets,
delegated admin login state, OAuth/session caches, health snapshots, and
realtime broadcast channels are scoped by tenant id or tenant fingerprint.

Hosted MCP tokens are bearer tokens bound to one active tenant. Token scopes
gate tool families (`tools:read`, `tools:generate`, `tools:data:read`,
`tools:data:write`, `tools:destructive`), and `McpAuthorizationGate` still
denies cross-tenant service/resource access before tool dispatch.

## Dependency Rules

```
✅ MCP → Generator → Resources, Fields, ServiceConfig
✅ MCP → Tenancy for hosted token, scope, and tenant authorization
✅ Resources → TypeCasts, ServiceConfig, Validation
✅ Fields → Resources and registered ServiceConfig through RestRelationTarget
✅ TypeCasts → ServiceConfig (ResponseMapping)
✅ Auth/Realtime → Tenancy only for scoped key/channel construction

❌ Fields must NOT create ad hoc HTTP clients or auth headers
❌ TypeCasts must NOT know about specific field types
❌ ServiceConfig must NOT depend on runtime modules other than constructing auth helpers from primitive config
❌ No module should depend on MCP (it's outermost)
❌ Hosted mode must not read or mutate service/resource/generated state without a resolved tenant context
```

## See Also

- [Getting Started](getting-started.md) — installation and first steps
- [Configuration](configuration.md) — ServiceConfig and ResponseMapping details
