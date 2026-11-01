# MoonShine Multi-Panels Reference

> Source: https://github.com/moonshine-software/multi-panels
> Created: 2026-03-27
> Updated: 2026-03-27

## Overview

MoonShine Multi-Panels is an experimental package for creating multiple independent admin panels within a single MoonShine 4.4+ application. Each panel gets its own URL prefix, layout, color palette, menu, and configuration while sharing the same Laravel application.

**Status:** Experimental Beta — API may change.

**Requirements:** MoonShine 4.4+, Laravel 10+, PHP 8.2+

## Core Concepts

- **Panel**: An independent admin interface with its own prefix, title, layout, palette, and middleware. Defined as a keyed array in `config/moonshine.php`.
- **MultiPanelResource**: Abstract resource class that binds a resource to a specific panel via `$panel` property.
- **MultiPanelPage**: Abstract page class that binds a page to a specific panel.
- **MoonShineRouter**: Custom router that prefixes all routes with the current panel name.
- **MultiPanelMiddleware**: Middleware that detects the current panel from the URL and applies its configuration overrides.

## Installation

```bash
composer require moonshine/multi-panels
```

Register provider if not auto-discovered:
```php
// bootstrap/providers.php
return [
    App\Providers\AppServiceProvider::class,
    MoonShine\MultiPanels\Providers\MultiPanelServiceProvider::class,
];
```

## Configuration

### Step 1: Disable default MoonShine routes

```php
// config/moonshine.php
'use_routes' => false,
```

### Step 2: Add MultiPanelMiddleware

```php
// config/moonshine.php — add to global middleware or per-panel
'middleware' => [
    // ... other middleware
    \MoonShine\MultiPanels\Http\Middleware\MultiPanelMiddleware::class,
],
```

### Step 3: Define panels

```php
// config/moonshine.php
'panels' => [
    'admin' => [
        'prefix' => 'admin',
        'title' => 'Admin Panel',
        'layout' => AdminLayout::class,
        'palette' => BluePalette::class,
        'logo' => '/images/admin-logo.png',
        // any other moonshine.php setting can be overridden here
    ],
    'client' => [
        'prefix' => 'client',
        'title' => 'Client Area',
        'layout' => ClientLayout::class,
        'palette' => GreenPalette::class,
    ],
],
```

### Panel Configuration Options

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `prefix` | `string` | panel key | URL prefix. Use `''` for root. |
| `title` | `string` | — | Panel display title |
| `layout` | `class-string` | global default | Custom layout class |
| `palette` | `class-string` | global default | Color palette class |
| `logo` | `string` | global default | Logo path |
| `logo_small` | `string` | global default | Small logo path |
| `middleware` | `array` | global default | Panel-specific middleware |
| `domain` | `string` | `''` | Domain-based routing |
| *any key* | mixed | global default | Any `moonshine.php` config key |

## API / Interface

### MultiPanelResource

```php
namespace MoonShine\MultiPanels;

abstract class MultiPanelResource extends ModelResource
{
    protected string $panel = 'admin'; // Panel this resource belongs to

    public function getRouter(): RouterContract;
    // Returns router with panelName parameter injected
}
```

### MultiPanelPage

```php
namespace MoonShine\MultiPanels;

abstract class MultiPanelPage extends Page
{
    protected string $panel = 'admin'; // Panel this page belongs to

    public function getRouter(): RouterContract;
    // Returns router with panelName parameter injected
}
```

### MoonShineRouter

```php
namespace MoonShine\MultiPanels;

final class MoonShineRouter extends BaseRouter
{
    public function to(string $name = '', array $params = []): string;
    // Prepends panel prefix to route names: "moonshine.{panel}.{name}"
}
```

### MultiPanelMiddleware

```php
namespace MoonShine\MultiPanels\Http\Middleware;

final class MultiPanelMiddleware
{
    public function handle(Request $request, Closure $next): Response;
    // Detects current panel from URL, applies its config overrides
    // Returns 404 if panel not found
}
```

### MultiPanelServiceProvider

```php
namespace MoonShine\MultiPanels\Providers;

class MultiPanelServiceProvider extends ServiceProvider
{
    public function register(): void;
    // - Adds Request::macro('getPanelName') for detecting current panel
    // - Binds RouterContract to MoonShineRouter
    // - Loads routes from routes/routes.php
}
```

### Request Macro: getPanelName()

```php
// Available on any Request instance
$panelName = request()->getPanelName();
// Detects panel by matching URL path prefix against configured panels
// Falls back to extracting from route name: "moonshine.{panel}.{...}"
```

## Usage Patterns

### Binding a Resource to a Panel

```php
class CarResource extends MultiPanelResource
{
    protected string $panel = 'test';

    protected function fields(): iterable
    {
        return [
            ID::make(),
            Text::make('Name'),
        ];
    }
}
```

### Binding a Page to a Panel

```php
class DashboardPage extends MultiPanelPage
{
    protected string $panel = 'client';

    protected function components(): iterable
    {
        return [
            // page components
        ];
    }
}
```

### Custom Layout per Panel

```php
class ClientLayout extends AppLayout
{
    protected function getMenu(): array
    {
        return [
            // Custom menu for client panel
        ];
    }
}
```

## How It Works

1. `MultiPanelServiceProvider` registers a `getPanelName()` macro on Laravel's `Request` class.
2. Routes are registered for each panel with a unique prefix and route name group (`moonshine.{panelKey}.*`).
3. `MultiPanelMiddleware` intercepts requests, detects the current panel, and overrides the global MoonShine config with panel-specific settings.
4. `MoonShineRouter` ensures all generated URLs include the correct panel prefix.
5. Resources and pages use `$panel` property to declare which panel they belong to.

## Common Pitfalls

- **Must disable default routes**: Set `'use_routes' => false` in `moonshine.php` or routes will conflict.
- **Middleware required**: Without `MultiPanelMiddleware`, panel config overrides won't apply.
- **Empty prefix**: Use `'prefix' => ''` explicitly for a panel at the root URL — if omitted, the panel key becomes the prefix.
- **Route name collisions**: Each panel's routes are namespaced as `moonshine.{panelKey}.*` — avoid duplicate panel keys.
