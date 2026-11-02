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
- Hosted mode with tenant-bound MCP tokens and explicit browser memberships.
- Realtime refresh from upstream webhooks.
- Cross-platform interactive installer and a production Compose stack with backup and restore.

### Fixed

- Upstream error logs omit response bodies and exception messages that can contain credentials.
- Installer backup and restore support the local Compose override, preserving HTTP and cookie settings after restart.
