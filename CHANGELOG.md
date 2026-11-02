# Changelog

Notable changes to MoonShine V. The project follows [Semantic Versioning](https://semver.org/) from its first tagged release.

## Unreleased

First public version.

### Added

- REST admin panels generated over MCP for services written in any language, with configurable response mapping, pagination and filtering.
- HTTP-backed BelongsTo, HasMany, HasOne and BelongsToMany fields, including relations across services.
- Native Eloquent panels for models in the same Laravel application, in trusted local mode.
- Separate panels per service with upstream, delegated-token and OAuth2/PKCE sign-in.
- Browser onboarding through **Add service**.
- Hosted mode with tenant-bound MCP tokens and explicit browser memberships, provisioned with `api-admin:tenant`, `api-admin:tenant-member` and `api-admin:mcp-token`.
- Realtime refresh from upstream webhooks.
- Cross-platform interactive installer and a production Compose stack with backup and restore.

### Fixed

- Keep upstream response secrets out of async relation search error logs.
- Forward relation dropdown searches to the target API before pagination and restore saved labels by ID, preserving server-defined scopes.
- Detect Spring Boot pagination consistently in MCP probes and browser onboarding.
- Isolate per-page generator tests so simultaneous runs do not share files or specs.
- Preserve the system hub and native administrator resources during code generation and removal.
- Reject local resource overwrites across panels and remove Eloquent files only for their owning panel.
- Group numeric aggregate keys without a sorting error.
- Reject occupied E2E ports and failed server processes instead of testing a stale application.
- Remove temporary fix labels from runtime diagnostics.

- Upstream error logs omit response bodies and exception messages that can contain credentials.
- Installer backup and restore support the local Compose override, preserving HTTP and cookie settings after restart.
