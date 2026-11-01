# moonshine-api-admin

> Build an admin panel for your APIs with an AI agent — or onboard a service directly in the browser.

Connect a REST API, describe its resources, and generate a MoonShine admin UI with forms, tables, filters and relations. Agents configure it through the Model Context Protocol (MCP). The repository is a runnable Laravel application; its API Admin module lives in `app/ApiAdmin/`.

Free and MIT-licensed. Built on **PHP 8.5, Laravel 13 and MoonShine 5**. MoonShine currently uses the `5.x-dev` branch, with an exact commit pinned in `composer.lock`. Review the [release checks](docs/release-readiness.md) and [production guide](docs/production.md) before deploying publicly.

## Quick Start

The repository is currently private. Collaborators with access can use an authenticated checkout and follow [Source Installation](docs/getting-started.md#source-installation). Anonymous installation will be available after the repository becomes public.

For the public release, install **Node.js 22.12+** (24 recommended), **Git**, and start **Docker** (Engine 26+, Compose 2.38.2+). Then run this same command in macOS/Linux terminals or Windows PowerShell:

```sh
npx --yes --package=https://github.com/moonshine-software/v/archive/refs/heads/main.tar.gz moonshine-v-install
```

After publication, the installer downloads the public source over HTTPS; no GitHub account or SSH key is required. See [installation details](docs/installation.md).

The interactive installer:

1. Asks for an installation directory and local port.
2. Builds the application and starts PostgreSQL, Redis, web, queue and scheduler services.
3. Creates unique secrets and prompts you to create the administrator.
4. Verifies MCP and connects any combination of **Claude Code, Codex, Cursor, Gemini CLI and Windsurf**.

Open **http://127.0.0.1:8080/admin** (or your chosen port), sign in and select **Add service**. PHP and Composer run inside Docker. Agent applications must be installed separately; Codex registration requires its CLI on `PATH`.

The wizard creates a local installation. See [installation details](docs/installation.md) for prerequisites, saved state, recovery and MCP setup, or [Getting Started](docs/getting-started.md) to run from source without Docker.

## What You Can Build

- **REST admin panels** for services written in any language, with configurable response envelopes, pagination and filtering.
- **Related resources** using HTTP-backed BelongsTo, HasMany, HasOne and BelongsToMany fields.
- **Custom API workflows** using declarative mutation routes and upstream validation error mapping.
- **Multiple panels** with separate service configuration, authentication and optional OAuth2/PKCE sign-in.
- **Native Eloquent panels** for models in the same Laravel application, in trusted local mode.
- **Tenant-scoped hosted access** through authenticated MCP and explicit administrator memberships.

## First Service

In the browser, **Add service** probes the endpoints you provide and shows the proposed configuration for review before generating files.

With an MCP-connected agent, a request can look like this:

```text
Connect my API at https://api.example.com/v1 as "catalog".
Probe /products, inspect its response and pagination, then create a Product
resource with id, name and price. Enable only the actions the API supports.
```

Replace the URL with your API. The agent follows `register_service` → `probe_endpoint` → `create_resource`; generated panels update without restarting the application.

For a runnable example, use the bundled [Blog and Shop APIs](examples/README.md). The [MCP walkthrough](docs/mcp-workflow.md) covers field options, filters, mutation routing and the separate Eloquent flow.

## Modes and Deployment

| Mode | Intended use | Access |
|------|--------------|--------|
| Local | A trusted operator manages REST APIs or models in this app | Browser login and local stdio MCP |
| Hosted | Multiple tenants manage their REST services | Tenant-bound MCP tokens and browser memberships |

Hosted service and panel names must be unique across the installation. Hosted generation restricts field types, methods and validation rules; custom PHP/Blade generators, native File/Image fields and local Eloquent generation are disabled. See [hosted security](docs/hosted-security.md).

Public deployments use the separate production Compose stack, HTTPS ingress and backups. The local installer binds HTTP to loopback and stores its secrets in `.moonshine-install/`; keep that directory and the Docker volumes when restarting or upgrading.

## Development

After [source setup](docs/getting-started.md), run the complete PHP quality gate and installer tests:

```sh
XDEBUG_MODE=off composer ai:validate
npm run test:installer
npm run test:e2e
npm run build
```

The PHP gate checks formatting, refactoring rules, dependency advisories, static analysis and PHPUnit. Browser tests run in your installed Chrome against an isolated copy of the repository. Installer CI runs on Linux, macOS and Windows. See [Make targets](docs/getting-started.md#make-targets) for development shortcuts.

## Documentation

| Guide | Contents |
|-------|----------|
| [Getting Started](docs/getting-started.md) | Source installation, browser onboarding and examples |
| [Interactive Installation](docs/installation.md) | One-command setup, MCP clients and recovery |
| [MCP Workflow](docs/mcp-workflow.md) | REST and Eloquent generation, fields and mutation routes |
| [MCP Tools](docs/mcp-tools.md) | Tool schemas and parameters |
| [Configuration](docs/configuration.md) | Response mapping, service settings and authentication |
| [Architecture](docs/architecture.md) | Modules, generated code and dependency rules |
| [Production](docs/production.md) | Deployment, readiness, backup and restore |
| [Hosted Security](docs/hosted-security.md) | Tenant membership, restrictions and upgrades |
| [Release Readiness](docs/release-readiness.md) | Recorded release verification and limitations |

Additional references: [detailed MCP schemas](docs/api-admin/mcp-tools.md), [ServiceConfig](docs/api-admin/service-config.md), [development Docker](docs/api-admin/docker-quickstart.md), [smoke checklist](docs/SMOKE_CHECKLIST.md).

## License

[MIT](LICENSE.md). See [Contributing](CONTRIBUTING.md) and [Security](SECURITY.md).
