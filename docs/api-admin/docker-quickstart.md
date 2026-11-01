[Back to README](../../README.md) · [Interactive Installation](../installation.md)

# Development Docker

For the interactive installation and automatic MCP setup, use the
[one-command installer](../installation.md). This page describes the separate
development Compose stack for contributors working from a source checkout.

This quickstart starts the local development stack. Public deployments must use
the separate [production guide](../production.md), which covers mandatory
credentials, immutable images, generated state, workers, readiness and recovery.

## Prerequisites

- Docker and Docker Compose installed
- Docker Compose 2.30.0+ for lifecycle hooks
- A checkout of this repository and Node.js 22.12+ / npm for host-mounted assets
- Your microservice(s) accessible from the Docker network

## 1. Create environment file

```bash
cp docker/.env.example docker/.env
node -e "console.log('base64:' + require('node:crypto').randomBytes(32).toString('base64'))"
npm ci
npm run build
```

Put the generated key into `APP_KEY` in `docker/.env`. The values below are
development examples; keep your actual key private:

```env
APP_KEY=base64:your-key-here
APP_URL=http://localhost:8080
APP_PORT=8080
HTTPS_PORT=8443

DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=moonshine_api_admin
DB_USERNAME=moonshine_api_admin
DB_PASSWORD=moonshine_api_admin

CACHE_STORE=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
REDIS_CLIENT=phpredis
REDIS_HOST=redis

BLOG_API_URL=https://api.blog.example.com/v1
BLOG_API_KEY=your-blog-api-key

SHOP_API_URL=https://api.shop.example.com/api
SHOP_API_KEY=your-shop-api-key
```

## 2. Start the container

```bash
docker compose --env-file docker/.env -f docker/docker-compose.yml up -d --build
```

Create the administrator, then open `http://localhost:8080/admin`:

```sh
docker compose --env-file docker/.env -f docker/docker-compose.yml exec api-admin-fpm php artisan moonshine:user
```

The compose stack starts four runtime services:

| Service | Purpose | Host access |
|---------|---------|-------------|
| `angie` | Angie reverse proxy for HTTP/static files/FastCGI | `http://localhost:8080` |
| `api-admin-fpm` | PHP-FPM Laravel + MoonShine + MCP runtime | Docker network only |
| `postgres` | Laravel metadata, tenants, tokens, sessions/jobs when configured | `127.0.0.1:5432` |
| `redis` | Cache, sessions, queue backend | `127.0.0.1:6379` |

Database migrations run automatically in the one-shot
`moonshine-api-admin-migrate` service before the web container starts.

## TLS Certificates

The Angie image includes ACME support, so the Docker stack does not need a
separate Certbot container. Certificate state is persisted in the
`angie_acme` named volume mounted at `/var/lib/angie/acme`. Compose runs a
narrow `post_start` hook as root to make that volume writable by Angie without
running the whole service as root.

For an ACME integration test on a domain you control, enable the provided example.
This does not turn the development stack into a production deployment:

```bash
cp docker/angie/conf.d/tls-acme.conf.example docker/angie/conf.d/tls-acme.conf
```

Then edit `docker/angie/conf.d/tls-acme.conf`:

- replace `example.com` and `www.example.com` with your exact domain names
- replace `admin@example.com` with the ACME account email
- keep the Let's Encrypt staging directory for the first validation
- set `APP_PORT=80`, `HTTPS_PORT=443`, and `APP_URL=https://your-domain`

After staging issuance works, switch the ACME directory in
`tls-acme.conf` to `https://acme-v02.api.letsencrypt.org/directory`.

## 3. Configure via MCP

Connect your AI agent to the MCP server and use the tools to register services and create resources.

## Hosted Mode

Local mode remains the default. Hosted configuration uses the variables below;
deploy it with the [production stack](../production.md) and follow
[hosted security](../hosted-security.md) for explicit memberships and restrictions:

```env
API_ADMIN_HOSTED_MODE=hosted
API_ADMIN_HOSTED_MCP_ENABLED=true
API_ADMIN_TENANT_ROUTE_PREFIX=t/{tenant}

DB_CONNECTION=pgsql
DB_HOST=your-managed-postgres-host
DB_PORT=5432
DB_DATABASE=moonshine_api_admin
DB_USERNAME=moonshine_api_admin
DB_PASSWORD=change-me

CACHE_STORE=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
BROADCAST_CONNECTION=reverb
```

Developers then point their local `.mcp.json` at the hosted MCP URL and a
tenant-bound token:

```json
{
  "mcpServers": {
    "api-admin": {
      "url": "https://admin.example.com/t/acme/api-admin/mcp",
      "headers": {
        "Authorization": "Bearer ${API_ADMIN_MCP_TOKEN}"
      }
    }
  }
}
```

Run `health_check({})` after provisioning a tenant and token. In hosted mode it
returns tenant-scoped service status and deployment diagnostics for cache,
session, queue, and broadcasting. Resolve any degraded backend warnings before
serving production tenants.

## Hot-Reload

After the MCP agent generates new files, hot-reload applies changes without restarting:

MCP generation already triggers reload. The commands below are operator tools
for troubleshooting:

```bash
# From inside the container
php artisan api-admin:reload

# Or from outside
docker exec moonshine-api-admin-fpm php artisan api-admin:reload
```

## Ports

| Service | Port |
|---------|------|
| Admin Panel | 8080 (configurable via `APP_PORT`) |
| HTTPS | 8443 by default, 443 for production ACME (configurable via `HTTPS_PORT`) |
| PostgreSQL | 5432 on localhost only (configurable via `POSTGRES_PORT`) |
| Redis | 6379 on localhost only (configurable via `REDIS_PORT`) |

## Volumes

The development stack mounts these paths:

- `app/MoonShine/` -- generated resources and layouts
- `storage/api-admin/` -- a named volume for generated registry state
- `public/` -- host assets, read-only in the web container
- `angie_acme` -- Angie ACME account, certificates, and keys

It does not mount the whole `config/` directory or persist every writable
application path. Use the production stack for durable generated state,
workers, scheduler, backups and recovery.

## Stopping

```bash
docker compose --env-file docker/.env -f docker/docker-compose.yml down
```

## See Also

- [Interactive Installation](../installation.md) — automatic local setup and MCP clients
- [Production](../production.md) — public deployment and backups
- [Getting Started](../getting-started.md) — source setup and example APIs
