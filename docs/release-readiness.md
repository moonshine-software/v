[← Hosted Security](hosted-security.md) · [Back to README](../README.md)

# Release readiness

What is verified before a release, and the conditions for running MoonShine V
in production. This does not replace checking your own infrastructure.

## Status

`1.0.0` is the first stable release. Before tagging each release:

- Recheck the pinned MoonShine release if upstream has changed.
- Verify the anonymous `npx` installation and private vulnerability reporting from a clean environment.
- Run every check in [Automated checks](#automated-checks) on the commit being tagged.

## Core and compatibility

`moonshine/moonshine` uses stable `^5.0` releases, starting with `5.0.0` pinned in `composer.lock`. Install with `composer install` to use the tested dependency versions.

`moonshine/multi-panels` uses the published `1.0.0`, which supports MoonShine 4.4+ and 5.x. Index pages and the generation stub use `RestIndexPage`: filters belong to the page, and relation filters receive their parent resource.

## Automated checks

CI runs the PHP, browser, frontend, installer and container checks on every push and pull request.

| Check | Covers |
|---|---|
| `XDEBUG_MODE=off composer ai:validate` | Pint, Rector, production dependency audit, PHPStan level max and PHPUnit, including API compatibility, relation search, secret-free diagnostics, HTTP authorization and hosted provisioning |
| `npm run test:e2e` | Chrome against an isolated copy of the repository: generation through MCP, relations, pagination strategies, hosted onboarding, delegated sign-in and custom field tools |
| `npm run test:installer` | Installer logic and E2E process lifecycle on Linux, macOS and Windows |
| `npm run test:installer:docker` | Fresh installation, secret exclusion, administrator login, MCP discovery, backup/restore and restart persistence |
| `docker/smoke.sh` | Production stack: PostgreSQL, Redis, queue, generated state, container recreation, backup/restore and backend outage |
| `composer audit --locked`, `npm audit` | Dependency advisories, including development dependencies |
| `npm run build` | Frontend build |

Hosted MCP is additionally exercised with the official MCP SDK: handshake, ping, `tools/list` and `tools/call` over stateless Streamable HTTP.

## Security guarantees

Each guarantee below is covered by regression tests; the full model is in the [hosted security guide](hosted-security.md).

- **Authorization.** Relation, search and nested CRUD endpoints check the panel guard, tenant membership, delegated state and resource action before any outbound request. Endpoints and queries come from server-side field definitions; dynamic context is encrypted and bound to the resource, field and parent.
- **Outbound requests.** `OutboundHttp` fixes the configured origin and forbids redirects and unsafe TLS/proxy overrides. Hosted mode checks every resolved address, rejects private, link-local and NAT64 destinations and pins the verified IP. Record IDs and path placeholders are validated as single segments before HTTP.
- **Secrets.** Logs keep payload keys, exception classes and HTTP statuses, never values, upstream bodies or exception messages. Hosted service secrets and rotated OAuth refresh tokens are encrypted in the database; refreshes are serialized with `Cache::lock`.
- **Hosted isolation.** Hosted generation allows only built-in field types, methods and validation rules; custom PHP/Blade, native File/Image fields and Eloquent generation are disabled even for wildcard tokens. Tenant members cannot reach platform users, roles or unattached CRUD pages, and accounts are never linked by matching email.
- **Generation safety.** Generation is serialized through a shared filesystem lock, ownership is rechecked under the lock, reserved and foreign files are never overwritten, and a journal restores files, configuration and hosted database rows on failure.
- **Data correctness.** List-strategy reads require an exact primary-key match, and pagination links never expose upstream hosts, paths or credentials to the browser.

The MCP contract is kept in sync across tool descriptions, tests, `AGENTS.md`, `LLMS.txt` and `initialize.instructions`. Arbitrary extension code remains possible only in trusted local mode.

## Deployment and upgrade conditions

Follow the [production guide](production.md) and the [hosted security guide](hosted-security.md). For an existing hosted installation, first run database migrations and `api-admin:migrate-tenant-secrets`, keeping the old cache until they finish. Then regenerate old hosted resources from reviewed specs, remove untrusted legacy extensions and grant memberships. Updating the generator alone does not neutralize PHP or Blade created by an older version.

All generation writers must share one filesystem volume with working `flock`. The journal rolls back caught errors; it is not an atomic commit across files on power loss, so crash recovery needs tested backups.

## Out of scope

- Public TLS ingress, production secrets, external backups and monitoring: configure them for your environment using the deployment guide.
- Delivery through an external WebSocket service.
- Multi-node failover and load testing under a specific production traffic profile.

The outbound request policy was reviewed against the [OWASP SSRF Prevention Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Server_Side_Request_Forgery_Prevention_Cheat_Sheet.html).

## See Also

- [Getting Started](getting-started.md)
- [Production](production.md)
