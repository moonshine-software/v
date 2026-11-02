# References Index

Background references collected while designing MoonShine V. They describe the
upstream packages as studied on 2026-03-27, mostly for MoonShine 4.x, and are
kept as design history. The project now runs on MoonShine 5 (`5.x-dev`, pinned
in `composer.lock`): when a reference disagrees with the code in `app/`, the
installed sources in `vendor/` or `AGENTS.md`, those win.

| Reference | Topic | Sources | Status |
|-----------|-------|---------|--------|
| [devkit-restapi](devkit-restapi.md) | MoonShine REST API resources (non-Eloquent CRUD) | github.com/moonshine-software/devkit (`4.x`) | Historical: the original prototype of `app/ApiAdmin/Resources/AbstractRestResource.php` |
| [multi-panels](multi-panels.md) | Multiple admin panels in one MoonShine app | github.com/moonshine-software/multi-panels | Dependency, `1.0.0` installed; written against an earlier beta |
| [moonshine-builder](moonshine-builder.md) | Code generation for MoonShine resources from SQL/JSON/models | github.com/dev-lnk/moonshine-builder | Historical: design inspiration only, not a dependency |
| [vendor-moonshine](vendor-moonshine.md) | MoonShine framework source architecture (Core, Crud, Laravel, UI, Support, Contracts) | vendor/moonshine/moonshine/src/ | Snapshot from before the MoonShine 5 migration |
