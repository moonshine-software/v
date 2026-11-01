# Architecture: Modular Monolith

## Overview
The moonshine-api-admin application follows a Modular Monolith architecture where each concern (Resources, Fields, TypeCasts, MCP, Validation, Realtime) is a self-contained module with clear boundaries and explicit public APIs. All modules ship in one Laravel application but maintain strict internal separation.

This pattern was chosen because the application has distinct functional areas (HTTP resource management, relation fields, API casting, realtime webhook ingestion, MCP code generation) that benefit from isolation, but deploying them as independent services would add unnecessary complexity.

## Decision Rationale
- **Project type:** Laravel application with a MoonShine 5 admin module
- **Tech stack:** PHP 8.5, Laravel 13, MoonShine 5
- **Key factor:** The module already has natural boundaries (Resources, Fields, MCP, Validation) — Modular Monolith formalizes these into explicit contracts

## Folder Structure
```
app/ApiAdmin/
├── Resources/                      # Module: REST resource management
│   ├── AbstractRestResource.php    #   Base CrudResource with HTTP client
│   └── MultiPanelRestResource.php  #   Multi-panel extension with $panel property
├── Fields/                         # Module: REST relation fields
│   ├── RestBelongsTo.php           #   Select + async HTTP options loading
│   ├── RestHasMany.php             #   Inline table with CRUD via HTTP
│   ├── RestHasOne.php              #   Detail view for single related item
│   └── RestBelongsToMany.php       #   Multi-select attach/detach via HTTP
├── TypeCasts/                      # Module: API response casting
│   └── ConfigurableApiCaster.php   #   DataCasterContract impl with configurable mapping
├── ServiceConfig/                  # Module: Service configuration
│   ├── ServiceConfig.php           #   DTO: base_url, auth, response mapping, realtime; thin factory for Auth handlers
│   ├── ResponseMapping.php         #   DTO: data_key, total_key, per_page_key etc.
│   └── RealtimeConfig.php          #   DTO: webhook verification + refresh behavior
├── Auth/                           # Module: HTTP auth schemes (refresh-on-401)
│   ├── AuthRetryHandler.php        #   Interface: refresh + rebuildRequest contract
│   ├── RetryOn401Middleware.php    #   Single-shot Guzzle middleware (401 / 419)
│   ├── OAuth2TokenStore.php        #   client_credentials / password / refresh_token / authorization_code grants + cache
│   ├── OAuth2AuthHandler.php       #   AuthRetryHandler for oauth2_* schemes
│   ├── SessionAuthHandler.php      #   Cookie + CSRF auto-login (Sanctum SPA / Django / Rails)
│   ├── DelegatedTokenAuthHandler.php # Per-user token/PAT/OAuth session auth for admin_auth_mode flows
│   ├── UpstreamUserProvider.php    #   UserProvider syncing delegated/OAuth panel users from upstream /me
│   ├── CurrentAdminUser.php        #   Current guard user id resolver for per-user cache scopes
│   └── SessionState.php            #   DTO: cached cookies + csrf token + expiresAt
├── Http/Controllers/               # Module: ApiAdmin HTTP support entry points
│   └── OAuthAuthorizationController.php # Per-panel Authorization Code + PKCE redirect/callback routes
├── (app/Models/)
│   └── MoonshineUserUpstreamIdentity.php # Upstream subject link for synced MoonShine admins
├── Validation/                     # Module: Two-layer validation
│   └── ApiErrorMapper.php          #   Maps API 422 errors to form fields
├── Tenancy/                        # Module: local/hosted tenant context
│   ├── TenantContext.php           #   Resolved tenant identity DTO
│   ├── TenantResolver.php          #   Resolver interface
│   ├── NullTenantResolver.php      #   Local default tenant resolver
│   ├── HttpTenantResolver.php      #   Hosted HTTP tenant resolver
│   ├── TenantRoutes.php            #   Local/hosted prefix, route params, and panel path matching
│   ├── TenantKeyBuilder.php        #   Tenant-scoped cache/session/channel keys
│   └── TenantSecretStore.php       #   Hosted encrypted tenant secret persistence
├── Instructor/                     # Module: guided onboarding
│   ├── InstructorSession.php       #   Session DTO with secret redaction
│   ├── InstructorDiscoveryService.php # Safe GET probing for new APIs
│   ├── InstructorProposalBuilder.php  # register_service/create_resource proposal builder
│   └── InstructorApplyService.php  # Confirmation-time generation via MCP tools
├── (app/MoonShine/ApiAdmin/Pages/)
│   └── InstructorPage.php          # MoonShine-native browser UI for the Instructor module
├── Realtime/                       # Module: upstream mutation webhooks + broadcasting
│   ├── WebhookController.php       #   POST /api-admin/webhooks/{service}
│   ├── WebhookVerifier.php         #   HMAC/disabled-service verification
│   ├── UpstreamMutationNormalizer.php # Payload aliases, allowed events, redaction
│   └── Events/UpstreamResourceMutated.php # Laravel broadcast event for Reverb/Echo
├── MCP/                            # Module: MCP server & code generation
│   ├── McpServer.php               #   MCP server entry point
│   ├── McpAuthorizationGate.php    #   Shared hosted tenant/scope authorization gate
│   ├── HostedMcpController.php     #   Hosted HTTP/SSE MCP transport with tenant token auth
│   ├── Analytics/                  #   Read-only count/aggregate query helpers
│   ├── Tools/                      #   MCP tool implementations
│   │   ├── RegisterServiceTool.php
│   │   ├── CreateResourceTool.php
│   │   └── ConfigureLayoutTool.php
│   └── Generator/                  #   PHP code generation
│       └── ResourceGenerator.php
├── Console/                        # Module: Artisan commands
│   └── ReloadCommand.php           #   Hot-reload after code generation
└── Providers/                      # Composition root
    └── ApiAdminServiceProvider.php #   Registers all modules with Laravel
├── config/
│   └── api-admin.php                   # Package configuration
├── stubs/                              # Code generation templates
│   ├── resource.stub
│   ├── service-config.stub
│   └── layout.stub
├── docker/
│   ├── Dockerfile
│   ├── docker-compose.yml
│   ├── angie/
│   │   ├── angie.conf
│   │   └── conf.d/
│   │       ├── default.conf
│   │       └── tls-acme.conf.example
│   ├── .env.example
│   └── reload.sh
└── tests/
    ├── Unit/
    └── Integration/
```

## Dependency Rules

Module dependency flow (allowed directions):

```
MCP/Tools → Analytics, Generator → Resources, Fields, ServiceConfig
Instructor → MCP/Tools, ServiceConfig (proposals + confirmed generation)
Resources → TypeCasts, ServiceConfig, Validation
Fields → Resources (parent resource's HTTP client)
TypeCasts → ServiceConfig (ResponseMapping)
ServiceConfig → Auth (delegates oauth2_* / session handler construction)
Realtime → ServiceConfig (webhook contract), Tenancy (channel keys), Laravel Events/Broadcasting
Tenancy → App\Models\ApiAdminTenant (hosted lookup only), Laravel Cache/Crypt (secret store)
Auth → Tenancy (cache/session key construction only)
Validation → (standalone, no internal deps)
Console → (calls reload logic, no module deps)
Providers → ALL modules (composition root, including Tenancy)
```

### Allowed
- MCP Tools depend on Generator, Resources, Fields, ServiceConfig
- MCP Tools depend on Analytics for read-only count/aggregate helpers
- Instructor depends on MCP Tools only at the confirmation boundary so browser onboarding uses the same generation behavior as MCP clients
- Analytics depends on MCP resource lookup/request helpers and Resources; it must stay read-only and must not generate code or mutate upstream data
- Resources depend on TypeCasts, ServiceConfig, Validation
- Fields depend on Resources (to delegate HTTP calls through parent's client)
- TypeCasts depend on ServiceConfig (for ResponseMapping)
- ServiceConfig depends on Auth (one-way — it builds OAuth2/Session handlers and installs RetryOn401Middleware on the HTTP client; Auth never imports ServiceConfig)
- Realtime depends on ServiceConfig for per-service webhook settings and Tenancy for tenant-scoped channel names; it must not call upstream service APIs or generate code
- Tenancy may read `ApiAdminTenant` metadata to resolve hosted context and may use Cache/Crypt for hosted service secrets; other modules should consume `TenantContext`, `TenantKeyBuilder`, or `TenantSecretStore` rather than querying tenant storage directly.
- Auth may depend on Tenancy only for tenant-scoped key construction; it must still receive primitive service/auth data and must not import ServiceConfig.
- Generator depends on stubs/ templates

### Forbidden
- Fields MUST NOT make HTTP calls directly — always through parent resource's client
- TypeCasts MUST NOT know about specific field types or resources
- Auth MUST NOT import ServiceConfig (the inverse dependency would re-introduce the leaf-DTO violation we just removed)
- Validation MUST NOT depend on any other module (it's a standalone mapper)
- Tenant context MUST be resolved at request/MCP boundaries before hosted service/config/resource access; missing hosted tenant context must fail closed.
- No module should depend on MCP (MCP is the outermost layer), except the Instructor confirmation boundary which intentionally delegates to MCP tools to avoid a second generation writer.
- Discovery may perform safe GET probes before a service is registered; confirmed generation must still go through RegisterServiceTool/CreateResourceTool rather than a parallel writer.

## Layer/Module Communication
- **ServiceConfig → Resources**: Resources receive ServiceConfig via constructor injection
- **Resources → Fields**: Fields receive parent resource reference to access HTTP client
- **Resources → TypeCasts**: ConfigurableApiCaster instantiated from ServiceConfig.responseMapping
- **Resources → Validation**: ApiErrorMapper called in `modifyErrorResponse()` hook
- **ServiceConfig → Auth**: `ServiceConfig::httpClient()` calls `RetryOn401Middleware::install` with the handler returned by `authHandler()`. Concrete handlers (`OAuth2AuthHandler`, `SessionAuthHandler`) and the token store (`OAuth2TokenStore`) take primitives — not the ServiceConfig itself — so the dependency arrow stays one-way.
- **MCP → Generator**: Tools invoke Generator to produce PHP files from stubs
- **MCP → Console**: After generation, calls ReloadCommand for hot-reload
- **MCP → Tenancy**: McpAuthorizationGate enforces hosted token scopes plus tenant/service visibility before every MCP tool dispatch; ServiceRegistry filters service/resource lists and generated state paths by `TenantContext` in hosted mode; HostedMcpController binds the authenticated developer token's tenant context before dispatch; local mode keeps the legacy shared paths.
- **Instructor UI → Instructor domain**: `App\MoonShine\ApiAdmin\Pages\InstructorPage` owns the browser shell and renders inside MoonShine. The HTTP controller keeps POST workflow boundaries and legacy URL compatibility; discovery/proposal/apply logic stays in `app/ApiAdmin/Instructor`.
- **Instructor → MCP Tools**: After human confirmation, InstructorApplyService dispatches RegisterServiceTool/CreateResourceTool through McpServer so browser onboarding and MCP clients share the same authorization and generation behavior.
- **Realtime → ServiceConfig/Tenancy**: WebhookController resolves the tenant-visible service config, WebhookVerifier checks the configured secret/header/algo, UpstreamMutationNormalizer tags the mutation with the service tenant id, and broadcast channels are built through TenantKeyBuilder so hosted tenant A cannot receive tenant B refresh events.
- **Resources → Realtime metadata**: AbstractRestResource exposes service/resource refresh metadata for the browser client without depending on webhook ingestion internals.
- **Tenancy → Hosted boundaries**: HTTP and future hosted MCP entry points resolve `TenantContext`; local/self-hosted mode uses a deterministic local context to preserve existing behavior.
- **Providers → Tenancy/ServiceConfig**: ApiAdminServiceProvider binds `TenantResolver`, registers hosted service configs under tenant-specific container keys, reads hosted secrets from TenantSecretStore, and keeps legacy `api-admin.service.{name}` bindings plus `.env` secret fallback only for the local tenant.
- **Providers → Tenancy/Routes**: ApiAdminServiceProvider uses `TenantRoutes` for hosted ApiAdmin support-route prefixes and tenant-aware panel prefix matching.

## Key Principles

1. **No Eloquent for REST services** — REST data flows through HTTP. CrudResource is the base, never ModelResource. Relations use HTTP endpoints, not database queries. Local Eloquent services are a separate flow that generates native ModelResource classes outside these modules.

2. **API key propagation** — ServiceConfig holds auth credentials. HttpClient in AbstractRestResource uses them. Fields delegate all HTTP through parent resource's client. No field ever makes its own authenticated request.

3. **Stack-free casting** — ConfigurableApiCaster uses ResponseMapping DTO to handle any API format (Django, Express, Spring Boot, Laravel). Mapping is configured per service, not hardcoded.

4. **Code generation over runtime config** — MCP tools generate PHP files (resources, configs, layouts). The admin panel runs standard compiled PHP, not interpreted configuration.

5. **Module public API** — Each module exposes only its public classes. Internal helpers stay within the module namespace and are not referenced by other modules.

6. **Secrets stay out of generated PHP** — Local/self-hosted mode may use `.env` for webhook secrets and API credentials, but hosted mode stores tenant-owned secrets in TenantSecretStore and generated PHP config must not contain literal secret fallbacks.

7. **Hosted tenancy fails closed** — Local mode can resolve the default tenant implicitly. Hosted mode must resolve a real active tenant before service, panel, resource, generated file, auth, realtime, or MCP access.
8. **Generated state is tenant-scoped in hosted mode** — `ServiceRegistry` stores Resources, Configs, and Layouts under a tenant path segment while local mode continues using the configured global directories.
9. **Runtime keys are tenant-scoped in hosted mode** — OAuth caches, session-auth caches, delegated admin session keys, and realtime broadcast channels include tenant fingerprints through TenantKeyBuilder; local mode preserves legacy key shapes.
10. **Hosted routes carry tenant identity** — Hosted mode prefixes MoonShine panels and ApiAdmin support routes with the configured tenant route prefix; local mode keeps the legacy flat `/admin`, `/blog-admin`, `/api-admin/*` routes.
11. **Hosted MCP tokens are tenant-bound** — Hosted MCP transports accept only hashed, non-revoked developer tokens linked to an active tenant; token scopes gate coarse tool families before tool dispatch, tenant/service mismatches are denied even when the token has the right tool family scope, and token values are never logged.

## Per-Panel Admin Auth

`auth_type` and `admin_auth_mode` are deliberately separate axes:

- `auth_type`: how the moonshine-v server calls the upstream API.
- `admin_auth_mode`: how a human signs into a generated MoonShine panel.

### Guard Chain

```text
Browser request /{panel}
        |
        v
MoonShine MultiPanelMiddleware resolves panel
        |
        v
Configs/<Service>Config.php admin_auth.mode
        |
        +-- moonshine ---------------------------> guard: moonshine
        |
        +-- delegated_token ---------------------> guard: api-admin-upstream-{panel}
        |                                           login form accepts token/PAT
        |                                           DelegatedAuthSession stores token
        |                                           UpstreamUserProvider GETs /me
        |
        +-- oauth2_authorization_code -----------> guard: api-admin-upstream-{panel}
                                                    /{panel}/oauth/redirect starts PKCE
                                                    /{panel}/oauth/callback exchanges code
                                                    DelegatedAuthSession stores OAuth token
                                                    UpstreamUserProvider GETs /me
```

Both upstream-backed modes upsert `moonshine_users` and link identities through
`moonshine_user_upstream_identities(provider_service, subject_id)`. The local
MoonShine password for synced users is a random unknown hash; upstream auth is
the source of truth.

### Decision Tree

- Choose `moonshine` when panel access should be owned by local
  `moonshine_users` and upstream calls can use static/service credentials.
- Choose `delegated_token` when each admin already has a per-user upstream
  PAT/API token and the upstream exposes an identity endpoint such as `/me`.
- Choose `oauth2_authorization_code` when the organization has an IdP and needs
  browser SSO. Configure `admin_auth.authorize_url`, `admin_auth.token_url`,
  OAuth client credentials, and optional scopes/redirect URI.
- Do not encode panel login in `auth_type`. Values like `bearer`, `session`,
  and `oauth2_cc` describe server-to-upstream API auth only.

## Code Examples

### AbstractRestResource — HTTP-based CrudResource
```php
<?php

declare(strict_types=1);

namespace App\ApiAdmin\Resources;

use MoonShine\Crud\Resources\CrudResource;
use App\ApiAdmin\ServiceConfig\ServiceConfig;
use App\ApiAdmin\TypeCasts\ConfigurableApiCaster;
use Illuminate\Support\Facades\Http;

abstract class AbstractRestResource extends CrudResource
{
    protected ServiceConfig $serviceConfig;

    public function __construct()
    {
        $this->serviceConfig = $this->resolveServiceConfig();
    }

    protected function httpClient(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::baseUrl($this->serviceConfig->baseUrl)
            ->withHeaders($this->serviceConfig->authHeaders());
    }

    public function getItems(): mixed
    {
        $response = $this->httpClient()->get($this->endpoint());
        return $this->caster()->cast($response->json());
    }

    protected function caster(): ConfigurableApiCaster
    {
        return new ConfigurableApiCaster($this->serviceConfig->responseMapping);
    }

    abstract protected function endpoint(): string;
    abstract protected function resolveServiceConfig(): ServiceConfig;
}
```

### ServiceConfig — Leaf DTO (no dependencies)
```php
<?php

declare(strict_types=1);

namespace App\ApiAdmin\ServiceConfig;

final readonly class ServiceConfig
{
    public function __construct(
        public string $name,
        public string $baseUrl,
        public string $apiKey,
        public ResponseMapping $responseMapping,
        public string $panel = 'default',
    ) {}

    public function authHeaders(): array
    {
        return ['Authorization' => 'Bearer ' . $this->apiKey];
    }
}
```

### Field delegating HTTP through parent resource
```php
<?php

declare(strict_types=1);

namespace App\ApiAdmin\Fields;

use MoonShine\UI\Fields\Select;
use App\ApiAdmin\Resources\AbstractRestResource;

class RestBelongsTo extends Select
{
    protected AbstractRestResource $relatedResource;

    // Uses parent resource's HTTP client — never makes direct HTTP calls
    protected function loadOptions(): array
    {
        $response = $this->relatedResource->httpClient()
            ->get($this->relatedResource->endpoint());

        return collect($response->json($this->relatedResource->serviceConfig->responseMapping->dataKey))
            ->pluck($this->titleKey, $this->valueKey)
            ->all();
    }
}
```

## Anti-Patterns
- **Direct HTTP in Fields** — Fields must never create their own HTTP client or use `Http::` facade directly. Always go through the parent resource's `httpClient()` method to ensure auth propagation.
- **Eloquent creep** — Never use `Model`, `Relation`, `Builder`, or any Eloquent class in REST modules. If MoonShine's base code requires it, override the method to work with arrays/stdClass.
- **Runtime configuration** — Don't build a JSON/YAML config interpreter. MCP generates actual PHP classes. The generated code is standard Laravel/MoonShine code.
- **Cross-module internals** — Don't reach into another module's non-public classes. If you need something, it should be in the module's public API (top-level namespace files).
- **Unescaped previews** — MoonShine treats `resolvePreview()` output as HTML. Escape upstream data with `e()` in custom fields.
- **Hardcoded response formats** — Never assume `data`, `total`, or any specific JSON key. Always use ResponseMapping from ServiceConfig.
- **Realtime as API client** — Realtime webhook handlers must not fetch or mutate upstream service data directly. They normalize inbound mutation notifications and broadcast them to the admin UI.
