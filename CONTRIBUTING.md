# Contributing

Bug reports, focused pull requests and documentation improvements are welcome.
This guide follows the approach in the [MoonShine contribution guide](https://getmoonshine.app/docs/resource/getting-started/contribution), with setup and checks specific to this application.

## Report a bug or propose a change

Use [this repository's issues](https://github.com/moonshine-software/v/issues).
Include the source commit, PHP/Node/Docker versions, reproduction steps and the
expected and actual results. Use a minimal example API and synthetic data.
Remove credentials, personal data and tenant secrets from logs and screenshots.
Report security vulnerabilities privately as described in [SECURITY.md](SECURITY.md).

For a substantial feature, open an issue before implementing it so its scope can
be discussed. Keep unrelated fixes in separate pull requests.

## Development

Follow [Getting Started](docs/getting-started.md) for source setup. Requirements
are PHP 8.5, Composer, Node.js 22.12+ (24 recommended), and installed Chrome for
browser tests. Use the committed lock files when installing dependencies.

Run the PHP quality gate before submitting a pull request:

```sh
XDEBUG_MODE=off composer ai:validate
```

For frontend, installer or browser behavior changes, also run:

```sh
npm run test:installer
XDEBUG_MODE=off npm run test:e2e
npm run build
```

UI, authentication and MoonShine dependency changes also require the
[production smoke test](docs/production.md#automated-acceptance). Installer
changes should pass `npm run test:installer:docker` against a running local Docker.

## Pull requests

- Describe the problem, the resulting behavior and the checks you ran.
- Add regression tests for changed behavior and update the relevant documentation.
- Follow the surrounding code and the project's [agent conventions](AGENTS.md).
  Use existing MoonShine UI components and keep PHPStan exclusions and ignores empty.
- Change generation templates and generators together; generated services and
  resources belong to each installation and must not be committed.
- Do not commit `.env`, MCP tokens, local databases, uploads or installation state.

Contributions are made under the project's [MIT license](LICENSE.md).
