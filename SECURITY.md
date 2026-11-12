# Security Policy

## Supported code

Report vulnerabilities against the latest `1.x` release or current `main`
branch and include the exact version, commit or image digest. Use committed
lock files; MoonShine is pinned to a stable release. Older releases are not
maintained as separate security branches.

## Reporting a vulnerability

Please do not publish vulnerabilities, credentials or exploit details in public
issues, discussions or pull requests.

Use [GitHub's private vulnerability reporting form for this repository](https://github.com/moonshine-software/v/security/advisories/new).
If GitHub does not offer the form, ask a repository maintainer for a private
contact channel without sharing the vulnerability details publicly.

Include the affected commit, deployment mode (`local` or `hosted`), prerequisites,
reproduction steps, expected and actual behavior, and the impact. Prefer a small
proof of concept with synthetic data. Never send production APP_KEY values,
upstream credentials, MCP bearer tokens, session cookies or customer data.

Coordinate public disclosure with the maintainers after a fix is available.

## Deployment boundaries

Local mode trusts its operator and stdio MCP client to generate executable PHP
and Blade. Use hosted mode for tenant-scoped access by untrusted developers.
Hosted browser access requires explicit tenant membership, and hosted MCP tokens
must be scoped to the required tools.

Public deployments require HTTPS, independent secrets, restricted ingress and
tested backups. Hosted realtime requires signed webhooks and private channels.
Follow the [production guide](docs/production.md) and
[hosted security guide](docs/hosted-security.md) before exposing an installation.

For a vulnerability in MoonShine itself, also consult the
[upstream repository's security page](https://github.com/moonshine-software/moonshine/security).
