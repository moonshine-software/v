# Devkit RestApi Reference

> Source: https://github.com/moonshine-software/devkit/tree/4.x/app/MoonShine/RestApi
> Created: 2026-03-27
> Updated: 2026-03-27

## Overview

The Devkit RestApi module provides an abstract layer for building MoonShine admin panel resources backed by REST APIs instead of Eloquent models. It consists of three files: `AbstractRestResource`, `JsonApiCaster`, and `WithRestBelongsTo` trait.

This allows MoonShine to manage data from external APIs with the same CRUD interface used for database models.

## Core Concepts

- **AbstractRestResource**: Abstract class extending `CrudResource` that replaces Eloquent operations with HTTP client calls (GET, POST, PUT, DELETE).
- **JsonApiCaster**: A `DataCasterContract` implementation that casts raw JSON API responses into `MixedDataWrapper` objects compatible with MoonShine's type system.
- **WithRestBelongsTo**: Trait for handling BelongsTo-style relationships via REST API endpoints with async select fields.

## API / Interface

### AbstractRestResource

```php
namespace App\MoonShine\RestApi;

abstract class AbstractRestResource extends CrudResource
{
    use WithRestBelongsTo;

    protected ?string $casterKeyName = 'id';

    // Must return the API base URL
    abstract function baseUrl(): string;

    // Must return a closure that builds the list endpoint URL from params
    // Signature: Closure(array<string, mixed> $params): string
    abstract function listEndpoint(): Closure;

    // Must return a closure that builds the item endpoint URL from key and params
    // Signature: Closure(int|string|null $key, array<string, mixed> $params): string
    abstract function itemEndpoint(): Closure;

    // Override to customize the HTTP client (headers, auth, etc.)
    protected function modifyHttpClient(): Factory|PendingRequest;

    // Returns the configured HTTP client
    protected function getHttpClient(): Factory|PendingRequest;

    // Returns JsonApiCaster instance
    public function getCaster(): DataCasterContract;

    // Fetches a single item by ID via GET
    public function findItem(bool $orFail = false): ?DataWrapperContract;

    // Fetches list of items via GET, supports QueryTags
    public function getItems(): iterable|Collection|LazyCollection|CursorPaginator|Paginator;

    // Deletes multiple items by IDs (iterates delete() or uses MassDestroyHandler)
    public function massDelete(array $ids): void;

    // Deletes a single item via HTTP DELETE
    public function delete(DataWrapperContract $item, ?FieldsContract $fields = null): bool;

    // Creates (POST) or updates (PUT) an item, applies field handlers
    public function save(DataWrapperContract $item, ?FieldsContract $fields = null): DataWrapperContract;

    // Field apply closure for setting request values on item data
    public function fieldApply(FieldContract $field): Closure;
}
```

### JsonApiCaster

```php
namespace App\MoonShine\RestApi;

final readonly class JsonApiCaster implements DataCasterContract
{
    public function __construct(
        private string $keyName,    // Primary key field name (default: 'id')
        private int $perPage,       // Items per page for pagination
        private Closure $url        // URL builder closure for pagination links
    );

    // Casts raw data (array or object) into MixedDataWrapper
    public function cast(mixed $data): DataWrapperContract;

    // Casts paginated API response into PaginatorContract
    // Expects response with: data, links, path, from, to, total, last_page, first_page_url, last_page_url
    public function paginatorCast(mixed $data): ?PaginatorContract;
}
```

### WithRestBelongsTo Trait

```php
trait WithRestBelongsTo
{
    // Async method handler for BelongsTo field data fetching
    #[AsyncMethod]
    public function belongsTo(Request $request, JsonResponse $response): JsonResponse;

    // Generates async URL for BelongsTo field
    public function getBelongsToUrl(
        string $endpoint,
        ?string $wrapper = null,
        string $key = 'id',
        string $name = 'name',
    ): string;

    // Creates a Select field configured as a relation select with async loading
    public function relationSelect(
        string $endpoint,      // API endpoint to fetch options
        string $label,         // Field label
        ?string $column = null,// Column name (defaults to snake_case of label)
        ?string $wrapper = null,// JSON wrapper key for response data
        string $key = 'id',    // Value key in option data
        string $name = 'name', // Label key in option data
        string $defaultKey = 'id',    // Default value key from current item
        string $defaultName = 'name', // Default label key from current item
    ): Select;
}
```

## Usage Patterns

### Creating a REST Resource

```php
class PostResource extends AbstractRestResource
{
    function baseUrl(): string
    {
        return 'https://api.example.com/v1';
    }

    function listEndpoint(): Closure
    {
        return fn(array $params): string => '/posts?' . http_build_query($params);
    }

    function itemEndpoint(): Closure
    {
        return fn(int|string|null $key, array $params): string => '/posts/' . ($key ?? '');
    }

    protected function modifyHttpClient(): Factory|PendingRequest
    {
        return Http::asJson()
            ->baseUrl($this->baseUrl())
            ->withToken(config('services.api.token'));
    }
}
```

### Using Relation Selects

```php
// Inside a resource's fields() method:
$this->relationSelect(
    endpoint: '/categories',
    label: 'Category',
    column: 'category_id',
    wrapper: 'data',  // if response wraps items in "data" key
    key: 'id',
    name: 'title',
);
```

### Custom Save/Destroy Handlers

Use PHP Attributes to delegate save/delete to service classes:

```php
use MoonShine\Crud\Attributes\SaveHandler;
use MoonShine\Crud\Attributes\DestroyHandler;
use MoonShine\Crud\Attributes\MassDestroyHandler;

#[SaveHandler(service: PostService::class, method: 'save')]
#[DestroyHandler(service: PostService::class, method: 'destroy')]
#[MassDestroyHandler(service: PostService::class, method: 'massDestroy')]
class PostResource extends AbstractRestResource { ... }
```

## Configuration

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `$casterKeyName` | `?string` | `'id'` | Primary key field name in API responses |
| `$itemsPerPage` | `int` | inherited | Items per page (from CrudResource) |

## Best Practices

1. Always override `modifyHttpClient()` to add authentication headers, timeouts, and error handling.
2. Use `$casterKeyName` when the API uses a non-standard primary key (e.g., `uuid`, `_id`).
3. The `listEndpoint()` closure receives query parameters including page, filters, and query tag data — build the URL accordingly.
4. The `itemEndpoint()` closure receives both the item key and request params — useful for nested API routes.
5. API responses for pagination must include Laravel-style pagination keys (`data`, `links`, `total`, `last_page`, etc.).

## Common Pitfalls

- **Missing pagination structure**: If the API doesn't return `links` in the list response, `paginatorCast()` returns null and items won't paginate.
- **Empty data handling**: `JsonApiCaster::cast()` returns an empty `MixedDataWrapper` for empty/null data — ensure your API doesn't return unexpected empty responses for existing items.
- **Field apply order**: Fields are applied in three phases: `beforeApply`, `apply`, `afterApply` — custom field logic must respect this pipeline.
- **Save response**: The `save()` method expects the API to return `{'id': ...}` in the response JSON. If your API uses a different key, override the save method.
