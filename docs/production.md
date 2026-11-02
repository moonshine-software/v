[← Architecture](architecture.md) · [Back to README](../README.md) · [Hosted Security →](hosted-security.md)

# Production deployment

Use the standalone `docker/compose.production.yml`. The older
`docker/docker-compose.yml` is for local development. Production requires a TLS
ingress on the Docker host forwarding to `127.0.0.1:8080`; the application port is
never published on all interfaces. The supplied web configuration sets HTTPS for
FastCGI and session cookies are secure. Do not expose this loopback HTTP port via
an external port forward. Configure the ingress with the public hostname and a
valid certificate before accepting users.

Set `API_ADMIN_TRUSTED_PROXIES` to the exact ingress address seen by the web
container (often the Docker network gateway), or controlled CIDRs, separated by
commas. Leave it empty for direct local access. Never use `*`. The ingress must
overwrite `X-Forwarded-For` with the client address; Laravel trusts that header
only from the configured proxies. Forwarded Host and Proto headers are not
trusted. This keeps browser/MCP limits separate for different clients behind the
ingress. Verify the observed address and rate limits before opening public access.

Use Docker Engine 26+ and current Docker Compose (validated with 2.38.2). The web
container mounts only the `app/public` subdirectory of the state volume, read-only;
it cannot access generated config or secrets. Public uploads are served under
`/storage`, with PHP/script extensions, hidden files and symlinks denied. This uses
Docker's [volume subpath support](https://docs.docker.com/engine/storage/volumes/).

## Build and start

Build both images from the same reviewed commit and lock files. Publish/tag them
with an immutable release identifier (prefer image digests in the environment).

```sh
docker build --target production -f docker/Dockerfile -t your-registry/moonshine-v:RELEASE .
docker build --target web -f docker/Dockerfile -t your-registry/moonshine-v-web:RELEASE .
cp docker/.env.production.example docker/.env.production
chmod 600 docker/.env.production
```

Fill `APP_IMAGE`, `WEB_IMAGE`, `APP_URL` (HTTPS), `APP_KEY`, `DB_PASSWORD` and
`REDIS_PASSWORD`. Generate a fresh 32-byte APP_KEY (`base64:` followed by the
output of `openssl rand -base64 32`) and independent random backend passwords.
Missing values stop Compose before deployment. Never use the repository's test
key. Keep the environment file and APP_KEY in a separate encrypted secret backup.

```sh
bash docker/production.sh config --quiet
bash docker/production.sh up -d --wait --wait-timeout 180
bash docker/production.sh exec api-admin-fpm php artisan moonshine:user
```

The last command creates the initial platform operator interactively. No default
admin credentials or demo service panels are enabled. Choose hosted mode and MCP
transport explicitly in the environment only after tenant/operator provisioning;
create tenants, memberships and MCP tokens with the operator commands in
[Hosted Security](hosted-security.md#provision-a-tenant).

The `init` job seeds the dedicated application state volume and sets its owner;
`migrate` applies database migrations before FPM starts. FPM, queue and scheduler
run as UID 82, with a read-only source tree and no capabilities. Only `storage`,
temporary files and each process's bootstrap cache are writable. PostgreSQL and
Redis are on an internal network with no published ports. Their official
entrypoints initialize private data volume ownership before dropping root.

Queue workers receive 90 seconds to stop; their job timeout is 60 seconds. Keep
the Redis queue retry interval above that timeout. `schedule:work` runs one
scheduler. Do not scale it without reviewing scheduled tasks' locking. Image
health checks pass through real FPM to Laravel and query PostgreSQL and Redis.
The web `/up` endpoint runs Laravel rather than returning a synthetic status;
`/ready` and web container health also check PostgreSQL and Redis. Configure the
ingress readiness probe against `/ready`. Docker restart policies restart
exited processes but do not automatically restart an unhealthy running process.

The Dockerfile builds frontend assets from `package-lock.json`; the web image
contains the matching assets, with no bind mount to a developer checkout.
Infrastructure image families are PostgreSQL 18, Redis 8.6 and Angie 1.12.1.
Pin resolved digests for a release and update them as part of patch maintenance.
References: [PostgreSQL image](https://hub.docker.com/_/postgres),
[Redis image](https://hub.docker.com/_/redis),
[Angie images](https://en.angie.software/angie/docs/installation/docker/).

## Generated state and updates

All generated state is persisted in `app_state`. The image links the generated
resource/config/layout/custom PHP/Blade directories, specs, `config/api-admin.php`,
`config/moonshine.php` and `.env` into `storage/runtime`. The initial seed is copied
once. Existing state is never overwritten during container recreation or image
upgrade. Core application source and `vendor` stay immutable.

Generated config files are therefore part of the deployment data. On an upgrade,
compare new `config/api-admin.php` and `config/moonshine.php` defaults with the
persisted files and apply new keys deliberately in maintenance. Test the merged
config in a restored staging copy before deploying. Do not run `config:cache` or
`route:cache` on a stack that allows live onboarding; generated services and
panels must be discovered on each request. Restart queue/scheduler after updates
so long-lived processes load the new code. Do not use `composer update` inside
the deployed container.

`make optimize` clears config/route caches and caches only views, preserving live
service and panel discovery.

## Backup, restore and rollback

Backups contain encrypted credentials, generated executable code and Redis
session/queue state. Restrict access, encrypt at rest, copy off-host and apply a
retention policy. APP_KEY must be escrowed separately: a database backup alone
cannot recover encrypted credentials. Do not rotate APP_KEY without a tested
re-encryption plan.

```sh
bash docker/backup.sh /secure/backups/moonshine-v-RELEASE-TIMESTAMP
```

The script stops all application writers, dumps PostgreSQL, archives the entire
application state volume, stops Redis and archives its RDB and AOF data (including queued jobs), records image
IDs and restarts the application. If a step fails, writers remain stopped for
investigation. This is a planned maintenance backup; use a coordinated external
backup system if zero downtime is required.

`PRODUCTION_ENV_FILE` selects a different environment file. When the deployment
also requires a Compose override, set `PRODUCTION_COMPOSE_OVERRIDE` to that file;
the shared wrapper applies it to every backup/restore command. Docker's standard
`DOCKER_CONTEXT` selects the target engine. Installer-managed local stacks require
their saved context and local override; use the [installer recovery instructions](installation.md#public-deployment-and-upgrades).

To test restoration, use a separate Compose project and ingress port, restore the
saved environment/APP_KEY and matching image versions, initialize the stack,
then run:

```sh
PRODUCTION_ENV_FILE=/secure/staging.env bash docker/restore.sh /secure/backups/moonshine-v-RELEASE-TIMESTAMP
```

Restore replaces the selected project's database, state and Redis data. Verify
the project name before running. The restore tool assumes a trusted backup made
by this deployment and stops application writers until restoration completes.
Validate login, service credentials, generated resources and pending jobs before
reopening ingress. Restoring an earlier Redis snapshot may replay previously
processed work: jobs must tolerate retries and upstream side effects require
reconciliation.

Before upgrade, take a backup and record the old `APP_IMAGE`/`WEB_IMAGE` digests.
Start the new images and verify readiness. On failure, stop ingress/writers, set
the prior image pair, restore the matching pre-upgrade database/state/Redis backup
and start the stack. Do not blindly run down-migrations against changed data.
Rollback discards writes after the snapshot; choose a maintenance window or a
forward fix when that loss is unacceptable.

## Automated acceptance

```sh
docker build --target production -f docker/Dockerfile -t moonshine-v:production-smoke .
docker build --target web -f docker/Dockerfile -t moonshine-v:web-smoke .
bash docker/smoke.sh
```

The smoke uses a randomly named disposable Compose project and credentials. It
checks PostgreSQL/Redis migrations, HTTP login and readiness, every writable
generated path, state after container recreation, encrypted database data and
Redis after backup/damage/restore, and readiness failure during a Redis outage.
It removes only its disposable volumes and leaves logs in the printed temporary
directory. `SMOKE_PORT` changes its default loopback port 18080.

GitHub Actions runs the full PHP quality gate plus an audit including dev
dependencies, npm audit/build, both Docker image builds and this production smoke.

## See Also

- [Getting Started](getting-started.md)
- [Hosted Security](hosted-security.md)
