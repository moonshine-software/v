[← Production](production.md) · [Back to README](../README.md) · [Release Readiness →](release-readiness.md)

# Hosted browser access and durable secrets

Hosted browser accounts require an explicit membership in each tenant. An active tenant URL, platform login, MoonShine role, or membership in another tenant does not grant access. Platform operators also use explicit memberships; there is no implicit superuser bypass. MCP bearer token authorization remains a separate mechanism.

## Provision a tenant

Run these operator commands inside the application container (prefix them with
`bash docker/production.sh exec api-admin-fpm` on the production stack). Hosted
mode requires `API_ADMIN_HOSTED_MODE=hosted`; the MCP endpoint additionally
requires `API_ADMIN_HOSTED_MCP_ENABLED=true`.

```bash
php artisan api-admin:tenant acme --name="Acme"
php artisan moonshine:user
php artisan api-admin:tenant-member acme 42
php artisan api-admin:mcp-token acme --scope=tools:read --scope=tools:generate --name="Acme developer"
```

1. `api-admin:tenant` creates the tenant and prints its panel URL
   (`/t/acme/admin`) and MCP endpoint (`/t/acme/api-admin/mcp`). The slug uses
   lowercase letters, digits and hyphens and starts with a letter. Run it without
   arguments to list tenants.
2. `moonshine:user` creates a browser account; grant it membership by numeric ID.
3. `api-admin:mcp-token` prints a bearer token **once**. Only its SHA-256 hash is
   stored, so pass it to the developer over a secure channel. Grant the smallest
   set of scopes they need:

| Scope | Tools |
|-------|-------|
| `tools:read` | Services, resources, field catalog, health and probe |
| `tools:generate` | Service and resource generation and field deltas |
| `tools:data:read` | Item reads and analytics |
| `tools:data:write` | Item create, update and delete |
| `tools:destructive` | `remove_service` and `remove_resource` |
| `tool:<tool_name>` | One tool, for example `tool:health_check` |
| `*` | Every hosted tool; use only for trusted automation |

Custom field/component generation and local Eloquent tools stay disabled in
hosted mode whatever the scope. Manage tokens and tenants later with:

```bash
php artisan api-admin:mcp-token acme --list
php artisan api-admin:mcp-token acme --revoke=7
php artisan api-admin:tenant acme --deactivate
php artisan api-admin:tenant acme --activate
```

Listings never show tokens or their hashes. Revocation takes effect on the next
request. Deactivating a tenant immediately blocks its MCP tokens and browser
sessions without deleting data; activate it again to restore access.

## Browser memberships

Hosted panels expose REST resources only. Native Eloquent resources, including
the platform users and roles, are blocked across pages, JSON CRUD and component
endpoints. Hiding their menu entries is not the authorization boundary. Manage
platform accounts through trusted operator commands; users can still edit their
own profile. Local mode retains native resource access.

After creating a MoonShine user, a trusted operator grants access using its numeric ID:

```bash
php artisan api-admin:tenant-member acme 42
```

Revoke access immediately:

```bash
php artisan api-admin:tenant-member acme 42 --revoke
```

Delegated/OAuth login links users by **tenant ID + service + upstream subject**. Matching email addresses never link an existing local administrator. Hosted identities use an internal synthetic email to avoid the global MoonShine email uniqueness constraint. A first successful upstream authentication can create the linked account, but an operator must grant membership before it can access hosted pages. Reauthentication with a revoked upstream token fails closed.

## Upgrade existing installations

Existing upstream identity rows are migrated to the `local` tenant. Old hosted identities cannot safely be assigned from email or service name alone; authenticate again within the intended tenant and grant the resulting user membership explicitly. Hosted upstream guard session names also change, requiring a fresh login.

Instructor drafts are tenant-scoped in both the browser and MCP. Old unscoped
hosted drafts are intentionally ignored; restart unfinished onboarding after an
upgrade. Local drafts keep their existing cache keys.

Service credentials are encrypted in `api_admin_tenant_secrets` using the application encryption key. The database is the source of truth. New writes discard old cache copies; a missing database value can migrate a valid legacy encrypted cache value once. Corrupt ciphertext fails closed instead of silently dropping upstream authentication.

For an upgrade, keep the old application cache available until these commands finish:

```bash
php artisan migrate --force
php artisan api-admin:migrate-tenant-secrets
```

Only then clear the application cache or replace its backend. The command checks all configured hosted services and never prints their secrets. Values already lost from the old cache must be restored from a backup or re-entered; migration cannot reconstruct them. It is safe to rerun the command: database values win over stale cache values.

## Existing generated resources

Before enabling hosted traffic on an upgraded installation, regenerate every hosted resource from its reviewed, validated declarative spec. The current generator rejects executable custom fields, native File/Image uploads, arbitrary field methods and database/custom/network validation rules. Use external URL fields for media. Replacing the generator does not sanitize PHP/Blade files generated by an earlier version; remove untrusted legacy custom extensions and their registrations as part of the upgrade. Native media fields are also blocked at the REST resource runtime boundary.

Re-register hosted services to regenerate layouts with their tenant namespace and
update their panel registry entries. Reapply `configure_layout` customizations
after re-registration. Verify the tenant login, profile, OAuth callback and REST
panel in a restored staging copy before reopening access.

Hosted resource URLs now use the service name, as local resources already do:
`blog-post-resource` and `shop-post-resource` remain distinct. Replace saved
links containing the old shared `tenants-` resource prefix.

Merge the current `config/moonshine.php` login form setting into persisted
configuration: `forms.login` uses `App\MoonShine\Forms\DelegatedLoginForm`. It
composes MoonShine's native form with secret PAT input, an optional refresh token
and an OAuth sign-in button. It does not replace the standard local login flow.

## Realtime and MCP transport

Hosted realtime requires a nonempty webhook secret. Registration rejects enabled
realtime without one, and the runtime rejects unsigned webhooks. An omitted secret
is preserved on re-registration; disable realtime before clearing it explicitly.

Refresh events use private channels. `POST /broadcasting/auth` derives the tenant
from the configured service channel, checks the service panel's guard and active
membership, and delegates signing to Laravel's broadcaster. Other tenants,
inactive tenants and unknown channels are denied. Configure the broadcaster and
Echo separately and preserve this application's broadcast-auth route.

Hosted MCP uses stateless Streamable HTTP with JSON responses at
`/t/{tenant}/api-admin/mcp`; legacy `/mcp/sse` is unsupported. Native HTTP clients
use bearer tokens without a browser CSRF token. If an Origin header is present,
it must match `APP_URL`. Behind an ingress, set `API_ADMIN_TRUSTED_PROXIES` as
described in the production guide so clients do not share one rate limit.

## Backup and recovery

Back up the application database, generated configuration/artifacts, and the corresponding `APP_KEY` through the deployment's protected secret backup mechanism. Keep database backups encrypted and restrict access. A database backup without the original encryption key cannot restore service credentials. Preserve the old key until every encrypted value has been re-encrypted during a planned key rotation; changing `APP_KEY` alone is not a credential rotation procedure.

Verify a restore in a separate environment using the recovered database and key. Clear the application cache there, confirm a scoped service credential can still decrypt, and perform an upstream authentication check using a test service. Rotate individual service credentials through registration with an explicit new value; an omitted secret preserves the previous value, and an explicit empty value clears it.

Regression tests cover membership isolation and revocation, rejected Instructor/async requests before upstream access, scoped identities, email account takeover prevention, encrypted database storage, legacy cache migration, and operation after cache clearing.

## See Also

- [Getting Started](getting-started.md)
- [Production](production.md)
