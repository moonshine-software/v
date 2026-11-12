# Project Roadmap

> Free, AI-driven admin panel for REST APIs that runs in Docker. Developer talks to an MCP-connected agent; agent builds multi-service admin without touching moonshine-v source.

## Milestones

- [x] **REST Admin Foundation** — HTTP-based CRUD resources (`AbstractRestResource`), multi-panel routing, MoonShine UI integration, two-layer validation (Laravel rules + API 422 mapping)
- [x] **MCP Builder Surface** — the initial tool set for service registration (`register_service`, `probe_endpoint`), resource generation (`create_resource`, `update_resource`), layout config (`configure_layout`), removal (`remove_service`, `remove_resource`), and diagnostics (`list_services`, `list_resources`, `health_check`)
- [x] **Layout + Field Presets** — `Box`/`Tabs`/`Grid` layout trees, per-mode `rules`/`methods` (create vs update), per-resource `detail_strategy` override, password `->raw()`, recursive AST layout
- [x] **MCP Introspection Surface** — `list_fields`, `describe_field_type`, `list_field_methods`, `list_components` + live JSON resources (`moonshine-v://fields|components|field-methods|llms`)
- [x] **MCP Runtime Data Surface (read)** — `fetch_resource_items` / `fetch_resource_item` honoring `mutations.*`, per-resource `detail_strategy`, `parent_context` for relations, filter/sort discoverability
- [x] **MCP CRUD Write Surface** — `create_resource_item` / `update_resource_item` / `delete_resource_item` with `actions` allowlist, mutations routing, `confirm: true` guard on destructive
- [x] **MCP Custom Extension Surface** — `create/update/delete_field` + `create/update/delete_component` with stub-based generators, spec-sidecar usage guards, `force` override
- [x] **Partial Resource Deltas** — `add_field` / `remove_field` / `rename_field` / `reorder_fields` instead of full-overwrite `update_resource`; per-page field list split (Index vs Form vs Detail)
- [x] **Advanced Auth Schemes** — OAuth2 custom refresh hook, cookie/CSRF session flow, token refresh on 401 with retry
- [x] **Delegated Admin Login** — MCP `register_service(admin_login: true)` mode for OAuth2 password and session/CSRF panels: admins enter upstream credentials on `/{panel}/login`; moonshine-v validates against the service, stores only delegated token/session state, and keeps service passwords out of config/env
- [x] **MCP Analytics Surface** — `count_resource`, `aggregate_resource` (sum/avg/group_by), time-window filters for conversational data queries
- [x] **Built-in Instructor Agent** — onboarding dialog on container start: asks for API base, auto-probes, proposes `register_service` + initial `create_resource` spec
- [x] **Realtime Upstream Updates** — webhook ingest + WebSocket auto-refresh of open pages on upstream mutations
- [x] **Cross-Service Relations** — `RestBelongsTo` / `RestHasMany` walking across registered services (distributed entity on one detail page)
- [x] **Hosted Edition** — hosted moonshine-v, tenant-isolated, `.mcp.json` only on developer side
- [x] **Local Eloquent Services** — `initialize_local_service` + `create_model_resource` for models in the same application
- [x] **MoonShine 5 Migration** — stable `^5.0` core, production Compose profile, backups, release hardening
- [x] **Interactive Installer** — one-command cross-platform setup with MCP client selection

## Completed

| Milestone | Date |
|-----------|------|
| REST Admin Foundation | 2026-04-24 |
| MCP Builder Surface | 2026-04-24 |
| Layout + Field Presets | 2026-04-24 |
| MCP Introspection Surface | 2026-04-24 |
| MCP Runtime Data Surface (read) | 2026-04-24 |
| MCP CRUD Write Surface | 2026-04-24 |
| MCP Custom Extension Surface | 2026-04-24 |
| Partial Resource Deltas | 2026-04-25 |
| Advanced Auth Schemes | 2026-04-26 |
| Delegated Admin Login | 2026-05-02 |
| MCP Analytics Surface | 2026-05-04 |
| Built-in Instructor Agent | 2026-05-04 |
| Realtime Upstream Updates | 2026-05-04 |
| Cross-Service Relations | 2026-05-06 |
| Hosted Edition | 2026-05-07 |
| Local Eloquent Services | 2026-06-16 |
| MoonShine 5 Migration | 2026-09-13 |
| Interactive Installer | 2026-09-13 |
