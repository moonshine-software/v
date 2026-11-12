# Changelog

Notable changes to MoonShine V. The project follows [Semantic Versioning](https://semver.org/) from its first tagged release.

## 0.1.0 — Unreleased

### Added

- Runnable Laravel application with MoonShine admin panels generated through MCP or browser onboarding.
- REST resources with forms, tables, filters, search, pagination, relations and declarative mutation routes.
- Native Eloquent resource generation for trusted local installations.
- Multiple service panels with token, session and OAuth2 authentication, including delegated sign-in and Authorization Code with PKCE.
- Hosted tenants with scoped MCP tokens, explicit browser memberships and encrypted upstream secrets.
- Cross-platform Docker installer with MCP client setup, persistent generated state and backup/restore scripts.
- Signed upstream webhooks and browser refresh integration through Laravel broadcasting.

### Changed

- Use stable MoonShine releases (`^5.0`), starting with `5.0.0` pinned in `composer.lock`.

### Security

- Require probe permission and `tools:data:write` (or `*` / `tools:*`) for hosted POST probes. Read-only and individual item-write grants cannot authorize arbitrary POST requests through `probe_endpoint`.

### Known limitations

- Hosted service and panel names must be unique across the installation. Custom PHP/Blade extensions and Eloquent generation are available only in trusted local mode.
- Public deployments require separately configured HTTPS ingress, secrets and off-host backups; the interactive installer serves local loopback HTTP.
