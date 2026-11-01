[← Hosted Security](hosted-security.md) · [Back to README](../README.md)

# Release readiness

Last verified: 2026-11-01. This records what was checked and what remains out of scope. It does not replace checking your own infrastructure.

## Status

The source passed the checks below. The repository is still private and no release has been tagged, so the anonymous one-command installation in the README cannot work yet. It must be verified separately after publication.

Before a public release:

- Recheck the pinned MoonShine commit if upstream changes before publication.
- Verify the anonymous `npx` installation and private vulnerability reporting once the repository is public.

## Core and compatibility

`moonshine/moonshine` follows the [development branch `5.x`](https://github.com/moonshine-software/moonshine/tree/5.x), and the exact commit is pinned in `composer.lock`. MoonShine 5 has no tagged release yet, so install with `composer install` and never with a floating `composer update`.

`moonshine/multi-panels` uses the published `1.0.0`, which supports MoonShine 4.4+ and 5.x. Index pages and the generation stub use `RestIndexPage`: filters belong to the page, and relation filters receive their parent resource.

## Automated checks

| Check | Result |
|---|---|
| `XDEBUG_MODE=off composer ai:validate` | Exit 0: Pint, Rector, production audit, PHPStan level max, PHPUnit |
| PHPUnit | **882 passed, 4467 assertions**, including real API compatibility and HTTP authorization regressions |
| Chrome E2E (`npm run test:e2e`) | **43 passed**, including real Django previous/next navigation without invented page metadata |
| Installer unit tests (`npm run test:installer`) | **16 passed** |
| Fresh Docker installation (`npm run test:installer:docker`) | Passed: secret exclusion, administrator login, MCP discovery, resume and restart persistence |
| Production smoke (`docker/smoke.sh`) | Passed: PostgreSQL, Redis, queue, generated state, container recreation, backup/restore, backend outage |
| `composer audit --locked`, `npm audit` | 0 advisories, including development dependencies |
| `npm run build` | Successful |

Hosted MCP was also exercised with the official MCP SDK: handshake, ping, `tools/list` and `tools/call` over stateless Streamable HTTP.

## Resolved findings

| Finding | Resolution and evidence |
|---|---|
| List lookup silently selected a different record | List-strategy reads require an exact primary-key match. Feature tests cover a single mismatched row and an ignored filter, assert 404/null, and prevent subsequent writes. |
| Django/Spring pagination mismatch | Django uses mapped `next`/`previous` links without guessing page counts; Spring supports `size` and zero-based upstream pages. Feature tests cover first/middle/last/empty pages, serialization, discovery and generated config; Chrome exercises actual Django navigation. |
| Browser relation endpoints rejected safe opaque IDs | The shared `PathSegment` validator is used at the browser authorization boundary. HTTP feature tests accept encoded opaque parent/child IDs and reject unsafe paths before outbound requests. |
| Unauthorized async HTTP proxy and upstream credential exposure | `RestEndpointAuthorization` checks guard, tenant, delegated state and resource action. Endpoint and query come from the server-side field; dynamic context is encrypted and bound to resource, field and parent. Regression tests assert no outbound request after a denial. |
| Permission bypass in HasMany/HasOne | The same checks plus field and parent object permissions. Hosted URLs contain the tenant. Fallback after 401/403 is forbidden; fallback after 404 requires a confirmed child-parent link. |
| SSRF and credential redirection | `OutboundHttp` fixes the origin and forbids redirects and unsafe TLS/proxy overrides. Hosted mode checks all DNS addresses, rejects private/link-local/NAT64 and pins the verified IP for cURL. |
| Path traversal through record IDs | `PathSegment` rejects dot segments, separators, encoded escapes and controls before HTTP. Batch deletion validates every ID before the first write. |
| OAuth refresh token loss and reuse | Rotated tokens are stored encrypted in the database and refreshes are serialized through `Cache::lock`. A late 401 reuses a token already refreshed by another worker. Covered with competing processes. |
| Arbitrary PHP/Blade in hosted mode | Custom field, component and local model tools are denied even for wildcard tokens. All string substitutions are escaped and templates are single-pass. Checked with malicious fixtures through the PHP tokenizer. |
| Dangerous field methods, shared uploads and platform database rules | Hosted mode uses allowlists of built-in types, methods and rules. File/Image fields are excluded and blocked on existing resources. |
| Hosted access to platform accounts | Tenant members cannot reach native users, roles or unattached CRUD pages through pages, JSON CRUD or component endpoints. |
| Cross-tenant browser user linking | Membership is explicit, identity is unique per tenant/service/subject, and a matching email does not link accounts. |
| Hosted realtime exposure | Private channels check panel guard, delegated state and active membership. An enabled hosted webhook requires a non-empty secret. |
| Loss of persistent secrets after cache clear | The encrypted database is the source of truth, with a migration from the legacy cache. Corrupted ciphertext fails closed. |
| Races and partial generation | A shared `flock`, ownership rechecked from persistent state under the lock, and collision checks. A journal restores files, configuration and hosted database rows on failure. |
| HTTP MCP without limits | A runtime feature gate, throttling and a payload limit. Disabled, oversized and rate-limited requests are tested. |
| Development deployment and false health checks | A separate production Compose stack with required secrets, a read-only source tree, queue and scheduler. Readiness checks Laravel, PostgreSQL and Redis. |
| Non-persistent generated paths and no recovery | Writable state and uploads live in a volume; the web container sees only public uploads, read-only. Backup and restore are exercised by the smoke test. |

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
