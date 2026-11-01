# moonshine-api-admin

Free, MIT-licensed admin panel for microservices. A Laravel + MoonShine application where an AI agent (via MCP) generates and manages REST-based admin resources for your microservices.

## Key Features

- **REST over HTTP** -- REST service data flows through HTTP. Your microservice dictates the API format.
- **Local Eloquent panels** -- native MoonShine resources for models in the same application, in trusted local mode.
- **Stack-free** -- works with any backend (Django, Express, Spring Boot, Laravel, etc.)
- **Multi-panel** -- each microservice gets its own isolated admin panel with separate URL prefix, layout, and menu.
- **MCP code generation** -- AI agent generates PHP resource files, configs, and layouts via MCP tools.
- **Hosted mode** -- tenant-scoped admin routes and HTTPS Streamable HTTP MCP with tenant-bound developer tokens.
- **Built-in instructor** -- `/api-admin/instructor` probes a new API and proposes the first service/resource before generation.
- **Two-layer validation** -- Laravel rules (client-side) + API 422 error mapping (server-side).
- **Configurable response mapping** -- map any API pagination format (Laravel, Django REST, custom).
- **Hot-reload** -- after MCP generates files, autoload and caches refresh automatically.

## Architecture

```
Your Microservices          moonshine-api-admin           Browser
+-------------+           +-----------------+          +--------+
| Blog API    |<-- HTTP --| AbstractRest    |<-- HTTP--| Admin  |
| Shop API    |           | Resource        |          | Panel  |
| ...         |           +-----------------+          +--------+
              |           | ServiceConfig   |
              |           | ConfigurableApi |
              |           | Caster          |
              +---------->| REST Fields     |
                          +-----------------+
                          | MCP Server      |<-- Claude AI Agent
                          +-----------------+
```

## Quick Start

### 1. Install

Use the [interactive installer](../installation.md) or [run from source](../getting-started.md).

### 2. Register a service (via instructor, MCP, or manually)

For a guided first run, open `/api-admin/instructor` after signing in. The
instructor probes safe GET endpoints, proposes a `register_service` payload and
an initial `create_resource` spec, then waits for confirmation before writing
generated files.

```php
// config/api-admin.php
'services' => [
    'blog' => [
        'name' => 'blog',
        'base_url' => env('BLOG_API_URL', 'https://api.blog.example.com/v1'),
        'api_key' => env('BLOG_API_KEY'),
        'panel' => 'blog-admin',
        'auth_type' => 'bearer',
        'realtime' => [
            'enabled' => true,
            'webhook_secret' => env('BLOG_WEBHOOK_SECRET', ''),
            'refresh_strategy' => 'auto',
        ],
        'response_mapping' => [
            'data_key' => 'data',
            'total_key' => 'total',
            'per_page_key' => 'per_page',
            'primary_key' => 'id',
        ],
    ],
],
```

Hosted deployments set `API_ADMIN_HOSTED_MODE=hosted` and issue tenant-bound
MCP tokens. A developer `.mcp.json` points at
`https://<host>/t/<tenant>/api-admin/mcp` and sends only that token; service
secrets remain in tenant-scoped hosted storage. Run `health_check({})` after
provisioning to verify tenant-visible services and deployment diagnostics.

### 3. Create a resource

Agents call `create_resource`, which generates a resource class and index, form and detail pages under `app/MoonShine/ApiAdmin/Resources/{Service}/{Resource}/`. Fields live on the pages. The generated resource looks like this:

```php
namespace App\MoonShine\ApiAdmin\Resources\Blog\Post;

use App\ApiAdmin\Resources\MultiPanelRestResource;
use App\ApiAdmin\ServiceConfig\ServiceConfig;
use App\ApiAdmin\Providers\ApiAdminServiceProvider;
use App\MoonShine\ApiAdmin\Resources\Blog\Post\Pages\PostDetailPage;
use App\MoonShine\ApiAdmin\Resources\Blog\Post\Pages\PostFormPage;
use App\MoonShine\ApiAdmin\Resources\Blog\Post\Pages\PostIndexPage;

class PostResource extends MultiPanelRestResource
{
    protected string $panel = 'blog-admin';
    protected string $title = 'Blog Posts';

    protected function resolveServiceConfig(): ServiceConfig
    {
        return ApiAdminServiceProvider::resolveService('blog');
    }

    protected function endpoint(): string
    {
        return '/posts';
    }

    protected function pages(): array
    {
        return [
            PostIndexPage::class,
            PostFormPage::class,
            PostDetailPage::class,
        ];
    }
}
```

## Documentation

| Doc | Description |
|-----|-------------|
| [MCP Tools Reference](mcp-tools.md) | register_service, create_resource, instructor_status, data tools |
| [ServiceConfig Format](service-config.md) | Response mapping, auth types, configuration |
| [Docker Quickstart](docker-quickstart.md) | Run with Docker in 5 minutes |

## REST Relation Fields

| Field | Description |
|-------|-------------|
| `RestBelongsTo` | Select field loading options via HTTP |
| `RestHasMany` | Inline table of related items via HTTP |
| `RestHasOne` | Single related item display via HTTP |
| `RestBelongsToMany` | Multi-select with attach/detach via HTTP |

## Testing

```bash
php vendor/bin/phpunit tests/ApiAdmin/
```

## License

MIT
