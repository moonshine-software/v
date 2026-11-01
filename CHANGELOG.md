# Changelog

Notable changes to MoonShine V. The project follows [Semantic Versioning](https://semver.org/) from its first tagged release.

## Unreleased

First public version.

### Fixed

- Link-header pagination keeps navigation inside the panel and preserves filters, search and sorting without forwarding upstream URL credentials.
- Concurrent generator and field-tool test runs use independent temporary resources and spec identifiers.
- REST form saves log payload keys without exposing passwords or nested secrets.
- Hosted browser onboarding links to the generated panel within the current tenant.
- `make optimize` preserves live service and panel discovery by caching only views.

### Added

- REST admin panels generated over MCP for services written in any language, with configurable response mapping, pagination and filtering.
- HTTP-backed BelongsTo, HasMany, HasOne and BelongsToMany fields, including relations across services.
- Native Eloquent panels for models in the same Laravel application, in trusted local mode.
- Separate panels per service with upstream, delegated-token and OAuth2/PKCE sign-in.
- Browser onboarding through **Add service**.
- Hosted mode with tenant-bound MCP tokens and explicit browser memberships.
- Realtime refresh from upstream webhooks.
- Cross-platform interactive installer and a production Compose stack with backup and restore.
