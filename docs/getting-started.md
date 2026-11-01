[Back to README](../README.md) · [Interactive Installation →](installation.md)

# Getting Started

For an interactive Docker installation with automatic MCP setup, follow
[Interactive Installation](installation.md). The steps below run the application
directly from source for development. This repository is a complete Laravel
application; there is no separate Composer package installation documented here.

## Prerequisites

- PHP 8.5 and Composer 2; `composer install` checks required PHP extensions.
- Node.js 22.12+ (24 recommended) and npm.
- Git and an API to connect, or the bundled example APIs.
- An MCP-capable agent is optional: browser onboarding also creates REST panels.

## Source Installation

Run these commands for a **new checkout**. Keep an existing installation's `.env`
and encryption key when upgrading.

```sh
git clone https://github.com/moonshine-software/v.git
cd v
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
npm ci
npm run build
php artisan moonshine:user
php artisan serve --host=127.0.0.1 --port=8000
```

The shell example above uses macOS/Linux commands. On Windows, use the
[cross-platform Docker installer](installation.md) or a WSL development shell.

Open **http://127.0.0.1:8000/admin** and sign in with the administrator you just
created. SQLite, database-backed sessions/cache and a database queue are the
defaults in `.env.example`. To process queued work, run `php artisan queue:work`
in another terminal. `composer run dev` starts the development services together.

The default database seeder creates no demo users or administrators. Create
administrators explicitly with `moonshine:user`.

The repository ships no services: the service hub starts empty, and every
installation generates its own through MCP or **Add service**.

## Connect an MCP Client

For the source installation, use a stdio server configuration. Replace the
artisan path with the absolute path to your checkout:

```json
{
  "mcpServers": {
    "moonshine-v": {
      "command": "php",
      "args": ["/absolute/path/to/v/artisan", "api-admin:mcp-server"]
    }
  }
}
```

This is the JSON format used by clients such as Claude Code and Cursor. Codex
uses its native `codex mcp add` command; the [installer](installation.md) handles
client-specific configuration and Docker execution automatically.

The root `.mcp.json.example` includes a development Docker configuration using
the `moonshine-v-fpm` container and a host configuration using an absolute
artisan path. Keep only the applicable server. For an installer-managed stack,
use its generated `.moonshine-install/mcp.json`.

For hosted deployments, the operator supplies a tenant URL such as
`https://admin.example.com/t/acme/api-admin/mcp` and a tenant-bound bearer token.
Configure the client's HTTP transport and `Authorization` header. Environment
variable interpolation is client-specific; do not assume every client expands
`${API_ADMIN_MCP_TOKEN}`. Hosted clients do not need PHP or this checkout.

## Browser Onboarding

After signing in to the admin hub, choose **Add service**. The legacy
`/api-admin/instructor` URL also redirects to the Instructor page inside the
MoonShine shell.

Enter the API base URL, authentication details and representative endpoint paths.
The instructor probes with GET requests and shows the proposed service and first
resource for review. Generation runs after confirmation. Credentials are
redacted from review payloads; re-enter a required token at confirmation.

## Try the Example APIs

Use a disposable development checkout: the scenario registers `blog` and `shop`
and regenerates their bundled resource definitions.

In another terminal, from the repository root:

```sh
bash examples/start-apis.sh
php examples/run-mcp-scenario.php
```

The start script launches Blog (9101), Shop (9102), OAuth IdP (9201), and Session
Auth (9202) fixtures. The scenario uses Blog and Shop. Open
**http://127.0.0.1:8000/blog-admin** and **http://127.0.0.1:8000/shop-admin**.
Blog includes `belongs_to`, `has_many`, `has_one` and `belongs_to_many` examples.

These host-native commands target the source installation. For APIs reached
from Docker, use a container-accessible address; `127.0.0.1` inside the app
container refers to that container. See [Docker networking](installation.md#connecting-apis).

Stop the fixtures when finished:

```sh
bash examples/stop-apis.sh
```

## Verify Your First Resource

1. Call `list_services` through MCP and check the registered service.
2. Open the panel URL returned by generation and select the resource.
3. Check that the list, filters and pagination match upstream API behavior.
4. Try supported create/edit/delete operations against test data.

Generated registry state is stored under `storage/api-admin/generated/` by
default; registration does not require editing `config/api-admin.php`.
In hosted mode, also call `health_check` and resolve deployment warnings about
cache, sessions, queues and broadcasting before serving tenants.

## Quality Checks

```sh
XDEBUG_MODE=off composer ai:validate
npm run test:installer
npm run test:e2e
npm run build
```

Disable Xdebug for validation: PHP 8.5 CLI with Xdebug can abort the test suite
with `zend_mm_heap corrupted`. `make check` covers linting and tests;
`composer ai:validate` is the full PHP release gate.

## Make Targets

```bash
make help              # Show all targets

# Development
make install           # Install PHP + Node dependencies
make setup             # Full project setup
make dev               # Start dev server (Laravel + Vite + queue + logs)

# Testing
make test              # Run all tests
make test-module       # Run API Admin module tests only
make test-module-unit  # Run API Admin unit tests
make test-module-integration  # Run API Admin integration tests

# Code Quality
make lint              # Pint dry-run (all)
make lint-module       # Pint dry-run (API Admin module only)
make lint-fix          # Auto-fix style (all)
make check             # Lint + test (all)
make check-module      # Lint + test (API Admin module only)

# API Admin
make reload            # Hot-reload after MCP code generation
make docker-up         # Start Docker containers
make docker-down       # Stop Docker containers
make docker-logs       # Tail container logs
```

The Docker Make targets operate on the development stack. For the interactive
installer's separate stack, use the commands in [Installation](installation.md).

## See Also

- [MCP Workflow](mcp-workflow.md) — create and refine REST or Eloquent resources
- [Configuration](configuration.md) — response mapping and authentication
- [Example APIs](../examples/README.md) — fixture endpoints and authentication scenarios
