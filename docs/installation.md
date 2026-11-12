[← Getting Started](getting-started.md) · [Back to README](../README.md) · [MCP Workflow →](mcp-workflow.md)

# Interactive Installation

The installer creates a local Docker installation and registers its MCP server
with the agents you select. It works from macOS/Linux terminals and native
Windows PowerShell. On Windows, Docker Desktop must use Linux containers.

## Prerequisites

- Node.js 22.12+ and npm (`npx` is included); Node.js 24 is recommended.
- Git on `PATH`.
- A running local Docker Engine 26+ and Docker Compose 2.38.2+.
- Internet access to GitHub, npm, Composer and the container image registries.
- Your chosen agent applications. Codex also needs its `codex` CLI on `PATH`.

The wizard checks prerequisites and reports missing tools before creating the
installation. Install Node/Git/Docker using your OS's normal installation method,
start Docker, then open a new terminal. It does not install system software or
request administrator privileges. PHP, Composer, PostgreSQL and Redis run inside
containers. The first image build can take several minutes.

## Run the Wizard

```sh
npx --yes --package=https://github.com/moonshine-software/v/archive/refs/tags/v1.0.0.tar.gz moonshine-v-install
```

If Windows PowerShell blocks `npx.ps1` under its execution policy, use `npx.cmd`
with the same arguments. No execution-policy change is needed.

The installer and source checkout use public HTTPS; no GitHub account, SSH key
or published npm package is required. Check access with
`git ls-remote https://github.com/moonshine-software/v.git HEAD` if npm reports a
Git error. Network or repository access failures stop installation without
switching to SSH. Run it directly in a terminal so the wizard and administrator
password prompt can read input. To inspect it first, review
[scripts/install.mjs](../scripts/install.mjs), or clone the repository and run
`node scripts/install.mjs`.

The wizard asks for:

1. A new installation directory (default: `~/moonshine-v`). Existing unrelated
   directories are rejected.
2. A local HTTP port (default: `8080`).
3. MCP clients: enter several numbers, such as `1,2,3`, or `all` / `none`.
4. Confirmation of the directory, Docker context and selected clients.
5. Administrator username/email, name and password after the stack is healthy.

It clones the `v1.0.0` release tag, records the source commit, builds matching app/web
images, creates unique encryption/database/Redis secrets, runs migrations, starts
the stack and verifies the MCP handshake and generation tool list. Open the
printed `/admin` URL and sign in. Use **Add service** or the connected agent to
generate your first REST resource.

## MCP Clients

| Selection | Integration |
|-----------|-------------|
| Claude Code | Adds a server in `~/.claude.json`; respects `CLAUDE_CONFIG_DIR` |
| Codex | Uses `codex mcp add`; backs up its `config.toml` and respects `CODEX_HOME` |
| Cursor | Adds a server in `~/.cursor/mcp.json` |
| Gemini CLI | Adds a server in `~/.gemini/settings.json` |
| Windsurf | Adds a server in `~/.codeium/windsurf/mcp_config.json` |

Close selected clients while updating configuration, then reopen them and
approve the MCP connection in the client. Registration does not bypass tool
approval or organization policies. JSON files retain unrelated settings and
servers; existing files receive a timestamped `.moonshine-backup-*` copy.
Replacement of a same-named server requires confirmation. Invalid JSON or JSON
with comments is left untouched and reported for manual configuration.

Each installation gets a unique server name (`moonshine-…`). The MCP command uses
the absolute Docker executable, saved Docker context and absolute Compose paths.
It runs `exec -T` over stdio; the application must be running when the agent
connects. The generated `.moonshine-install/mcp.json` is also available for manual
setup in other clients. Client-specific formats may require adapting that snippet.

The formats follow the client references for [Claude Code](https://code.claude.com/docs/en/mcp),
[Cursor](https://docs.cursor.com/context/model-context-protocol),
[Gemini CLI](https://github.com/google-gemini/gemini-cli/blob/main/docs/tools/mcp-server.md)
and [Windsurf](https://docs.windsurf.com/windsurf/cascade/mcp).
Codex registration uses its installed CLI's `mcp add` interface.

## Saved State and Recovery

The installation directory contains the checkout plus `.moonshine-install/`:

| File | Purpose |
|------|---------|
| `state.json` | Source commit, installation path, Docker context and project identity |
| `runtime.env` | Encryption key, passwords, image tags and port |
| `compose.local.json` | Local environment, HTTP cookies and host API networking |
| `mcp.json` | Generated connection configuration |

The state directory is excluded from Git and Docker build context. Keep it
private and back it up securely: losing `APP_KEY` can make encrypted application
data unreadable. Application files/uploads, PostgreSQL and Redis data live in
separate named Docker volumes belonging to the generated Compose project.

Run the same wizard again and choose the **same directory** to resume a failed
build or add MCP clients. It preserves secrets, project identity, port and data,
and skips administrator creation when a user already exists. It does not pull
updates or reset a checkout. Missing secret files, a moved installation or a
different Docker context require restoring the original state before resuming.
If cloning failed before state was created, use a new empty destination.

If MCP registration fails, the application remains running; fix the reported
client configuration and rerun. No cleanup step removes application volumes.

## Start, Stop and Logs

From the installation directory, these commands work in all supported shells:

```sh
node scripts/install.mjs --status
node scripts/install.mjs --stop
node scripts/install.mjs --start
node scripts/install.mjs --logs
```

Stopping preserves containers and volumes. Stop the log stream with Ctrl+C.
For direct Compose commands, use both the production file and the generated
local override, and pass the installer environment explicitly:

```sh
docker compose --env-file .moonshine-install/runtime.env -f docker/compose.production.yml -f .moonshine-install/compose.local.json ps
```

Select the same Docker context used during installation. The default development
Make targets and `docker/production.sh` use different environment-file defaults;
do not use them to manage this stack without supplying its environment.

## Connecting APIs

The panel binds to `127.0.0.1` only. To connect an API running on the host from
inside the app container, use `http://host.docker.internal:PORT`, and ensure that
the API listens on an interface reachable from Docker. The installer adds the
host-gateway mapping on Linux as well. Host firewall rules still apply.

Use a Docker network hostname for APIs in other containers on a shared network,
or the normal HTTPS URL for a remote API. Container `localhost` is never the
host's loopback address. The bundled example start script binds to host loopback;
its native source-development instructions are in [Getting Started](getting-started.md).

## Public Deployment and Upgrades

The wizard configures **local mode** with `APP_ENV=local`, HTTP cookies and a
separate `web-local` image for loopback access. The production web image keeps
HTTPS forwarding enabled.
It does not provision a domain, TLS ingress, hosted tenants or remote MCP tokens.
For a public installation, follow [Production](production.md), including HTTPS,
secure cookies, backup/restore and image-based upgrades. Do not publish the local
HTTP port or carry its cookie override into a public deployment.

The production backup procedure covers PostgreSQL, all application state and
Redis persistence. In Bash, from the installation directory, select the saved
Docker context and supply both the installation environment and its local
Compose override before running the recovery scripts:

```sh
export DOCKER_CONTEXT="$(node -p 'JSON.parse(require("node:fs").readFileSync(".moonshine-install/state.json", "utf8")).context')"
export PRODUCTION_ENV_FILE="$PWD/.moonshine-install/runtime.env"
export PRODUCTION_COMPOSE_OVERRIDE="$PWD/.moonshine-install/compose.local.json"
bash docker/backup.sh /secure/backups/moonshine-v-TIMESTAMP
```

Keep `runtime.env` separately; copying the checkout alone is not a data backup.
To restore that backup, with the same environment selected:

```sh
bash docker/restore.sh /secure/backups/moonshine-v-TIMESTAMP
```

Restore replaces the installation's database, generated state and Redis data.
The local override must be used throughout backup and restore so the stack
restarts with loopback HTTP and the correct cookie settings.
Moving to hosted mode also requires the [Hosted Security](hosted-security.md)
membership and upgrade steps.

## See Also

- [Getting Started](getting-started.md) — source development and example APIs
- [MCP Workflow](mcp-workflow.md) — service and resource generation
- [Production](production.md) — public deployment and recovery
