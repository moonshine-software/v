<?php

declare(strict_types=1);

namespace App\ApiAdmin\Resources;

use App\ApiAdmin\Http\PathSegment;
use App\ApiAdmin\ServiceConfig\ResponseMapping;
use App\ApiAdmin\ServiceConfig\ServiceConfig;
use App\ApiAdmin\Tenancy\TenantContext;
use App\ApiAdmin\Tenancy\TenantKeyBuilder;
use App\ApiAdmin\Tenancy\TenantRoutes;
use App\ApiAdmin\TypeCasts\ConfigurableApiCaster;
use Closure;
use GuzzleHttp\Psr7\Response as GuzzlePsr7Response;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Str;
use MoonShine\Contracts\Core\CrudPageContract;
use MoonShine\Contracts\Core\DependencyInjection\CoreContract;
use MoonShine\Contracts\Core\DependencyInjection\FieldsContract;
use MoonShine\Contracts\Core\DependencyInjection\RouterContract;
use MoonShine\Contracts\Core\Paginator\PaginatorContract;
use MoonShine\Contracts\Core\TypeCasts\DataCasterContract;
use MoonShine\Contracts\Core\TypeCasts\DataWrapperContract;
use MoonShine\Contracts\UI\FieldContract;
use MoonShine\Core\TypeCasts\MixedDataWrapper;
use MoonShine\Crud\Collections\Fields;
use MoonShine\Crud\Resources\CrudResource;
use MoonShine\UI\Fields\File;
use MoonShine\UI\Fields\Json;
use Override;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Abstract REST resource — extends CrudResource with HTTP-based CRUD operations.
 *
 * All data flows through HTTP. No Eloquent, no database queries.
 * Subclasses define the service config and endpoint patterns.
 *
 * Generic params mirror the CrudResource declaration so subclasses (and
 * the IDE / phpactor / phpstan) see a fully-resolved parent contract:
 *   TCore       — DI container the resource is bound to (default CoreContract)
 *   TData       — the wrapped item shape (mixed for REST since payloads are dynamic)
 *   TIndexPage  — null until a concrete subclass wires its own IndexPage
 *   TFormPage   — same for the form page
 *   TDetailPage — same for the detail page
 *   TException  — the exception thrown on item-not-found, defaults to Throwable
 *   TFields     — Fields collection used by the parent (default Fields)
 *
 * @extends CrudResource<CoreContract, mixed, null|CrudPageContract, null|CrudPageContract, null|CrudPageContract, Throwable, Fields>
 */
abstract class AbstractRestResource extends CrudResource
{
    #[Override]
    protected ?string $casterKeyName = 'id';

    /**
     * Declarative CQRS mutation routing. Set via the generator's `mutations`
     * param — lets a resource that lives behind a non-CRUD API (e.g. one
     * that splits updates across `PUT /tasks/{id}/assignee`,
     * `POST /tasks/{id}/status-transitions`) work without overriding
     * performCreate / performUpdate manually.
     *
     * Shape:
     *   [
     *     'create' => [
     *       'method' => 'POST',                    // http verb (default POST)
     *       'path'   => '/tasks',                  // endpoint path (default: endpoint())
     *       'body_from' => ['title','description'] // fields to include (default: all data)
     *     ],
     *     'update' => [                            // list of mutations dispatched in order
     *       [
     *         'method' => 'POST',
     *         'path'   => '/tasks/{id}/status-transitions',
     *         'body'   => ['target_status' => '{status}'],  // {placeholders} pull from $data
     *         'when_present' => 'status',          // skip this mutation if column not in $data
     *         'ignore_status' => [409],            // HTTP codes to treat as success
     *       ],
     *       [
     *         'method' => 'PUT',
     *         'path'   => '/tasks/{id}/assignee',
     *         'body'   => ['assignee_id' => '{assignee_id}'],
     *         'when_present' => 'assignee_id',
     *       ],
     *     ],
     *   ]
     *
     * After running all update mutations, the base performUpdate re-fetches
     * the item via GET so the form sees the canonical post-update state.
     *
     * @var array<string, mixed>
     */
    protected array $mutations = [];

    /**
     * Default query parameters attached to every outgoing request of the
     * given kind, used for APIs that require scope params to return
     * anything sensible (multi-tenant, scoped resources, etc.).
     *
     * Shape:
     *   [
     *     'index'  => ['for_entity' => 'project'],   // GET /resource
     *     'detail' => ['include' => 'status,tags'],  // GET /resource/{id}
     *   ]
     *
     * User-driven filters, sort, search and pagination always win — these
     * defaults ride on every request but don't override explicit user
     * choices. Set via the `default_query` MCP param or by overriding
     * this property in a subclass.
     *
     * @var array<string, array<string, mixed>>
     */
    protected array $defaultQuery = [];

    /**
     * HTTP method used for index/list requests. Most APIs expose a GET list
     * endpoint; a few internal APIs use POST with a JSON body for list/search.
     */
    protected string $indexMethod = 'GET';

    /**
     * Static JSON body merged into POST list requests. Required keys such as
     * `ids: []` stay present for index requests; runtime params win when they
     * intentionally narrow the list (e.g. detail_strategy=list with
     * detail_list_key_param=ids).
     *
     * @var array<string, mixed>
     */
    protected array $indexBody = [];

    private ?ServiceConfig $resolvedServiceConfig = null;

    /**
     * Runtime-supplied endpoint that wins over endpoint() for relation
     * resources whose endpoint carries `{parent_id}` placeholders. Set by
     * EndpointBinder before a MCP data-read tool calls getItems/findItem,
     * and cleared after the call returns.
     */
    private ?string $endpointOverride = null;

    /**
     * Return the ServiceConfig for this resource's microservice.
     */
    abstract protected function resolveServiceConfig(): ServiceConfig;

    /**
     * Return the API endpoint path for list operations (e.g. '/posts').
     */
    abstract protected function endpoint(): string;

    /**
     * Returns the effective endpoint — runtime override wins over the
     * subclass-declared `endpoint()`. Internal callers consult this so the
     * override, when set, flows into HTTP requests and placeholder guards.
     */
    protected function resolvedEndpoint(): string
    {
        return $this->endpointOverride ?? $this->endpoint();
    }

    /**
     * Override the endpoint this resource resolves for the duration of a
     * single MCP tool call. `null` clears the override.
     */
    public function setEndpointOverride(?string $endpoint): void
    {
        $this->endpointOverride = $endpoint;
    }

    /**
     * Return the raw declared endpoint (ignores the runtime override).
     * Used by EndpointBinder to detect placeholders before substitution.
     */
    public function getDeclaredEndpoint(): string
    {
        return $this->endpoint();
    }

    /**
     * Optional resource-level override for the service's detail_strategy.
     *
     * Use when one resource in a service needs a different single-item
     * fetch than the rest — e.g. most of the service exposes GET /{id}
     * (service-level `detail_strategy: "endpoint"`) but a few lookup
     * tables (statuses, tags, categories) only expose a list + write
     * endpoints. Returning "list" here routes that resource through
     * findItemViaListFilter() without affecting siblings.
     *
     * Null (default) keeps the service-level mapping. Generated via
     * MCP: `detail_strategy` on create_resource / update_resource.
     */
    protected function getDetailStrategyOverride(): ?string
    {
        return null;
    }

    /**
     * Optional resource-level override for the service's
     * detail_list_key_param. Only consulted when the effective detail
     * strategy (service or resource override) is "list".
     *
     * Null (default) inherits from service-level config, which itself
     * falls back to primary_key. Generated via MCP:
     * `detail_list_key_param` on create_resource / update_resource.
     */
    protected function getDetailListKeyParamOverride(): ?string
    {
        return null;
    }

    /**
     * Return the API endpoint path for a single item (e.g. '/posts/{id}').
     * Override if the item endpoint differs from endpoint()/{id}.
     */
    protected function itemEndpoint(int|string|null $key = null): string
    {
        $base = rtrim($this->resolvedEndpoint(), '/');

        return $key !== null ? $base.'/'.PathSegment::encode($key) : $base;
    }

    /**
     * Public accessor for relation fields/controllers that need item endpoint resolution.
     */
    public function resolveItemEndpoint(int|string|null $key = null): string
    {
        return $this->itemEndpoint($key);
    }

    /**
     * Get the resolved ServiceConfig (cached per instance).
     */
    public function getServiceConfig(): ServiceConfig
    {
        if (! $this->resolvedServiceConfig instanceof ServiceConfig) {
            $this->resolvedServiceConfig = $this->resolveServiceConfig();

            Log::debug('AbstractRestResource: resolved service config', [
                'resource' => static::class,
                'service' => $this->resolvedServiceConfig->name,
                'base_url' => $this->resolvedServiceConfig->baseUrl,
            ]);
        }

        return $this->resolvedServiceConfig;
    }

    /**
     * @return array{
     *     enabled: bool,
     *     service: string,
     *     resource: string,
     *     page: string,
     *     channels: list<string>,
     *     private: bool,
     *     eventName: string,
     *     refreshStrategy: string,
     *     debounceMs: int,
     *     debug: bool,
     * }
     */
    public function realtimeRefreshMetadata(string $page = 'index'): array
    {
        try {
            $service = $this->getServiceConfig();
        } catch (Throwable $e) {
            Log::warning('AbstractRestResource: realtime metadata service resolution failed', [
                'resource_class' => static::class,
                'exception' => $e::class,
            ]);

            return $this->disabledRealtimeMetadata($page);
        }

        $resourceKey = $this->realtimeResourceKey();
        if ($resourceKey === '') {
            Log::warning('AbstractRestResource: realtime metadata resource key unavailable', [
                'resource_class' => static::class,
                'service' => $service->name,
            ]);

            return $this->disabledRealtimeMetadata($page, $service->name);
        }

        $metadata = [
            'enabled' => $service->realtime->enabled,
            'service' => $service->name,
            'resource' => $resourceKey,
            'page' => $page !== '' ? $page : 'index',
            'channels' => [
                TenantKeyBuilder::realtimeChannel($service->tenantId, $service->name, $resourceKey),
            ],
            'private' => $service->tenantId !== TenantContext::LOCAL_ID,
            'eventName' => 'api-admin.upstream-resource-mutated',
            'refreshStrategy' => $service->realtime->refreshStrategy,
            'debounceMs' => $service->realtime->refreshDebounceMs,
            'debug' => config('app.debug') === true,
        ];

        Log::debug('AbstractRestResource: built realtime page metadata', [
            'resource_class' => static::class,
            'tenant_id' => $service->tenantId,
            'service' => $metadata['service'],
            'resource' => $metadata['resource'],
            'page' => $metadata['page'],
            'channels' => $metadata['channels'],
            'enabled' => $metadata['enabled'],
        ]);

        return $metadata;
    }

    private function realtimeResourceKey(): string
    {
        $segments = array_filter(
            explode('/', trim($this->endpoint(), '/')),
            static fn (string $segment): bool => $segment !== '' && ! str_starts_with($segment, '{'),
        )
            |> array_values(...);

        $resource = end($segments);

        return is_string($resource) ? Str::of($resource)->kebab()->toString() : '';
    }

    /**
     * @return array{
     *     enabled: false,
     *     service: string,
     *     resource: string,
     *     page: string,
     *     channels: list<string>,
     *     private: bool,
     *     eventName: string,
     *     refreshStrategy: string,
     *     debounceMs: int,
     *     debug: bool,
     * }
     */
    private function disabledRealtimeMetadata(string $page, string $service = '', string $resource = ''): array
    {
        return [
            'enabled' => false,
            'service' => $service,
            'resource' => $resource,
            'page' => $page !== '' ? $page : 'index',
            'channels' => [],
            'private' => false,
            'eventName' => 'api-admin.upstream-resource-mutated',
            'refreshStrategy' => 'disabled',
            'debounceMs' => 750,
            'debug' => config('app.debug') === true,
        ];
    }

    #[Override]
    public function getRouter(): RouterContract
    {
        return parent::getRouter()->withParams([
            'panelName' => $this->resolvePanelNameForRouter(),
            ...TenantRoutes::routeParams(),
        ]);
    }

    private function resolvePanelNameForRouter(): string
    {
        try {
            return $this->getServiceConfig()->panel;
        } catch (Throwable) {
            $parts = explode('\\', static::class);
            $resourcesIndex = array_search('Resources', $parts, true);
            $serviceSegment = $resourcesIndex === false ? null : ($parts[$resourcesIndex + 1] ?? null);

            if (is_string($serviceSegment)) {
                $service = strtolower($serviceSegment);
                $panel = config("api-admin.services.{$service}.panel");

                if (is_string($panel) && $panel !== '') {
                    return $panel;
                }
            }

            return request()->getPanelName();
        }
    }

    /**
     * Get the configured HTTP client with base URL and auth headers.
     */
    public function httpClient(): PendingRequest
    {
        return $this->modifyHttpClient(
            $this->getServiceConfig()->httpClient(),
        );
    }

    /**
     * Override to customize the HTTP client (add timeouts, retries, etc.).
     */
    protected function modifyHttpClient(PendingRequest $client): PendingRequest
    {
        return $client->timeout(30);
    }

    /**
     * Get the DataCaster for this resource.
     */
    #[Override]
    public function getCaster(): DataCasterContract
    {
        return new ConfigurableApiCaster(
            mapping: $this->getServiceConfig()->responseMapping,
            perPage: $this->getItemsPerPage(),
            currentPage: max(1, $this->getPaginatorPage() ?: 1),
        );
    }

    /**
     * Ensure custom REST relation fields know their parent resource.
     */
    #[Override]
    public function getIndexFields(): FieldsContract
    {
        $fields = parent::getIndexFields();

        return $this->attachParentResourceToFields($fields);
    }

    /**
     * Ensure custom REST relation fields know their parent resource.
     */
    #[Override]
    public function getFormFields(bool $withOutside = false): FieldsContract
    {
        $fields = parent::getFormFields($withOutside);

        return $this->attachParentResourceToFields($fields);
    }

    /**
     * Ensure custom REST relation fields know their parent resource.
     *
     * REST resources include outside fields (RestHasOne / RestHasMany) on detail
     * pages by default — unlike Eloquent resources, where outside relations are
     * usually rendered separately via dedicated pages. The fields render as a
     * vertical preview (see RestHasOne::resolvePreview / RestHasMany inline preview).
     */
    #[Override]
    public function getDetailFields(bool $withOutside = true, bool $onlyOutside = false): FieldsContract
    {
        $fields = parent::getDetailFields($withOutside, $onlyOutside);

        return $this->attachParentResourceToFields($fields);
    }

    /**
     * Ensure custom REST relation fields know their parent resource.
     */
    #[Override]
    public function getOutsideFields(): FieldsContract
    {
        $fields = parent::getOutsideFields();

        return $this->attachParentResourceToFields($fields);
    }

    /**
     * Abort with 404 when a relation-only resource (with `{parent_id}` placeholder
     * in its endpoint) is reached through a standalone panel URL. Those resources
     * are meant to be driven only through RestHasOne/RestHasMany with the parent
     * key substituted — a direct visit is a routing mistake, not a real page.
     */
    public function guardAgainstUnresolvedEndpoint(): void
    {
        $endpoint = $this->resolvedEndpoint();

        if (str_contains($endpoint, '{') && str_contains($endpoint, '}')) {
            throw new NotFoundHttpException(
                "Resource '".static::class."' has an unresolved endpoint placeholder in '{$endpoint}' "
                .'and cannot be accessed as a standalone page. It is a relation-only resource — '
                .'reach it through the parent resource (e.g. RestHasOne / RestHasMany).',
            );
        }
    }

    /**
     * Fetch a single item by ID via HTTP GET.
     */
    public function findItem(bool $orFail = false): ?DataWrapperContract
    {
        $this->guardAgainstUnresolvedEndpoint();

        $itemId = $this->getItemID();

        Log::debug('AbstractRestResource::findItem', [
            'resource' => static::class,
            'item_id' => $itemId,
            'or_fail' => $orFail,
        ]);

        if ($itemId === null) {
            if ($orFail) {
                Log::warning('AbstractRestResource::findItem — no item ID, failing', [
                    'resource' => static::class,
                ]);

                throw new NotFoundHttpException('Item not found: no ID provided');
            }

            return null;
        }

        $detailQuery = is_array($this->defaultQuery['detail'] ?? null)
            ? $this->defaultQuery['detail']
            : [];

        $mapping = $this->getServiceConfig()->responseMapping;

        // Resource-level detail_strategy wins over the service-level default.
        // Common shape: a service where most resources have GET /{id} but one
        // or two lookup tables (statuses, tags) expose only a list + write
        // endpoints. Without per-resource override we'd be forced to cripple
        // the whole service.
        $resourceDetailStrategy = $this->getDetailStrategyOverride();
        $effectiveStrategy = $resourceDetailStrategy ?? $mapping->detailStrategy;

        if ($effectiveStrategy === 'list') {
            return $this->findItemViaListFilter($itemId, $detailQuery, $mapping, $orFail);
        }

        $response = $this->httpClient()->get($this->itemEndpoint($itemId), $detailQuery);

        Log::debug('AbstractRestResource::findItem — response', [
            'resource' => static::class,
            'status' => $response->status(),
            'item_id' => $itemId,
        ]);

        if ($response->notFound()) {
            if ($orFail) {
                throw new NotFoundHttpException("Item not found: {$itemId}");
            }

            return null;
        }

        $response->throw();

        $data = $this->extractItemData($response);

        return $this->getCaster()->cast($data);
    }

    /**
     * Fetch list of items via HTTP GET, with pagination support.
     */
    public function getItems(): iterable|Collection|LazyCollection|CursorPaginator|Paginator|PaginatorContract
    {
        $this->guardAgainstUnresolvedEndpoint();

        $params = $this->buildListParams();

        $endpoint = $this->resolvedEndpoint();

        Log::debug('AbstractRestResource::getItems', [
            'resource' => static::class,
            'endpoint' => $endpoint,
            'params' => $params,
        ]);

        $response = $this->performIndexRequest($endpoint, $params);

        Log::debug('AbstractRestResource::getItems — response', [
            'resource' => static::class,
            'status' => $response->status(),
        ]);

        $response->throw();

        $json = $response->json();
        $mapping = $this->getServiceConfig()->responseMapping;

        // Cursor pagination — APIs shaped like `{data:[...], cursor:{next,...}}`
        // don't expose total/last_page. Build a Laravel CursorPaginator so
        // MoonShine's "next" button works; prev requires the client to
        // remember prior cursors (a Laravel limitation, not ours).
        if ($mapping->paginationType === 'cursor') {
            return $this->buildCursorPaginator($json, $mapping);
        }

        // Link-header pagination — GitHub/GitLab/Atlassian style. Body carries only
        // items, navigation lives in `Link:` header + total (optionally) in a header
        // like `X-Total-Count`. We parse both and feed MoonShine's native paginator.
        if ($mapping->paginationType === 'link_header') {
            return $this->buildLinkHeaderPaginator(
                $json,
                $response->header('Link'),
                $mapping->totalHeader !== '' ? $response->header($mapping->totalHeader) : '',
                $mapping,
            );
        }

        // Try paginated response first (length-aware)
        if ($this->isPaginationUsed() && $mapping->paginationType !== 'none') {
            $paginator = $this->getCaster()->paginatorCast($json);

            if ($paginator instanceof PaginatorContract) {
                Log::debug('AbstractRestResource::getItems — paginated', [
                    'resource' => static::class,
                    'total' => $paginator->getTotal(),
                    'current_page' => $paginator->getCurrentPage(),
                ]);

                return $paginator;
            }
        }

        // Fallback to simple collection
        $dataKey = $mapping->dataKey;
        $items = data_get($json, $dataKey, $json);

        Log::debug('AbstractRestResource::getItems — collection fallback', [
            'resource' => static::class,
            'items_count' => is_countable($items) ? count($items) : 'uncountable',
        ]);

        /** @var iterable<array-key, mixed> $itemsIter */
        $itemsIter = is_iterable($items) ? $items : [];

        return collect($itemsIter)->map(
            fn (mixed $item): DataWrapperContract => $this->getCaster()->cast($item),
        );
    }

    /**
     * Build a paginator from a cursor-shaped upstream response.
     *
     * Returns MoonShine's native `Paginator` (PaginatorContract) rather
     * than Laravel's `CursorPaginator` — MoonShine's IterableComponent
     * only recognises PaginatorContract instances; when we hand it a
     * Laravel paginator it falls back to `new Collection($this->items)`,
     * which (via Arrayable::toArray) turns the paginator's top-level
     * envelope keys (`data`, `path`, `per_page`) into three pseudo-items
     * and the table ends up with three empty rows.
     *
     * The emitted paginator is `simple: true` with a populated
     * `nextPageUrl` carrying the opaque cursor token — this is the shape
     * MoonShine's pagination UI already knows how to render (a "next"
     * button, no page-number strip, no total). The cursor token itself
     * is the upstream's next-page reference; we embed it in the URL so
     * hitting "next" re-enters `resolveIncomingCursor()` and refetches
     * with `?cursor=<token>`.
     */
    private function buildCursorPaginator(mixed $json, ResponseMapping $mapping): \MoonShine\Core\Paginator\Paginator
    {
        $rawItems = is_array($json) ? data_get($json, $mapping->dataKey, []) : [];
        $items = collect(is_array($rawItems) ? $rawItems : [])
            ->map(fn (mixed $item): DataWrapperContract => $this->getCaster()->cast($item));

        $nextValue = is_array($json) ? data_get($json, $mapping->nextCursorKey) : null;
        $nextString = is_string($nextValue) && $nextValue !== '' ? $nextValue : null;

        $path = \Illuminate\Pagination\Paginator::resolveCurrentPath();
        $nextPageUrl = null;
        if ($nextString !== null) {
            $nextPageUrl = request()->fullUrlWithQuery([$mapping->cursorQueryParam => $nextString]);
        }

        Log::debug('Cursor navigation prepared', [
            'resource' => static::class,
            'query_keys' => array_keys(request()->query()),
            'has_next' => $nextString !== null,
        ]);

        return new \MoonShine\Core\Paginator\Paginator(
            path: $path,
            links: [],
            data: $items,
            originalData: $items,
            currentPage: 1,
            from: null,
            to: null,
            perPage: $this->getItemsPerPage(),
            simple: true,
            nextPageUrl: $nextPageUrl,
            pageName: $mapping->cursorQueryParam,
            // pagination.blade.php reads every key unconditionally; omitting
            // any of them raises "Undefined array key" on render. Mirrors
            // what PaginatorCaster injects for the length-aware path so
            // our cursor paginator behaves the same in the template.
            translates: [
                'previous' => 'moonshine::pagination.previous',
                'next' => 'moonshine::pagination.next',
                'showing' => 'moonshine::pagination.showing',
                'to' => 'moonshine::pagination.to',
                'of' => 'moonshine::pagination.of',
                'results' => 'moonshine::pagination.results',
            ],
        );
    }

    /**
     * Build a paginator from a link-header-paginated upstream response (RFC 5988).
     *
     * Accepts:
     *   - body: standard `{ data: [...] }` shape (or raw `[...]` — governed by dataKey)
     *   - Link header with `rel="first"|"prev"|"next"|"last"` URLs
     *   - optional total-count header (X-Total-Count / X-Total)
     *
     * Derives `currentPage` / `lastPage` from the `?page=` query param inside each
     * parsed rel URL. Emits MoonShine `Paginator` in `simple: false` mode when
     * `total` + `lastPage` are known, else `simple: true` with a working "next".
     */
    private function buildLinkHeaderPaginator(
        mixed $json,
        mixed $linkHeader,
        mixed $totalHeader,
        ResponseMapping $mapping,
    ): \MoonShine\Core\Paginator\Paginator {
        $rawItems = is_array($json) ? data_get($json, $mapping->dataKey, $json) : $json;
        $items = collect(is_array($rawItems) ? $rawItems : [])
            ->map(fn (mixed $item): DataWrapperContract => $this->getCaster()->cast($item));

        $links = $this->parseLinkHeader(is_string($linkHeader) ? $linkHeader : '');

        $currentPage = max(1, $this->getPaginatorPage() ?: 1);
        $lastPage = isset($links['last']) ? (int) $this->queryParamFromUrl($links['last'], 'page') : null;
        $total = is_string($totalHeader) && $totalHeader !== '' && ctype_digit($totalHeader) ? (int) $totalHeader : null;

        $simple = $lastPage === null;
        // Link headers describe upstream pages; browser navigation must use the panel's authenticated proxy.
        $pageUrl = function (string $rel) use ($links): ?string {
            $page = $this->queryParamFromUrl($links[$rel] ?? '', 'page');

            return ctype_digit($page) && (int) $page > 0
                ? request()->fullUrlWithQuery(['page' => (int) $page])
                : null;
        };

        return new \MoonShine\Core\Paginator\Paginator(
            path: \Illuminate\Pagination\Paginator::resolveCurrentPath(),
            links: [],
            data: $items,
            originalData: $items,
            currentPage: $currentPage,
            from: null,
            to: null,
            perPage: $this->getItemsPerPage(),
            simple: $simple,
            total: $total,
            lastPage: $lastPage,
            firstPageUrl: $pageUrl('first'),
            prevPageUrl: $pageUrl('prev'),
            lastPageUrl: $pageUrl('last'),
            nextPageUrl: $pageUrl('next'),
            pageName: 'page',
            translates: [
                'previous' => 'moonshine::pagination.previous',
                'next' => 'moonshine::pagination.next',
                'showing' => 'moonshine::pagination.showing',
                'to' => 'moonshine::pagination.to',
                'of' => 'moonshine::pagination.of',
                'results' => 'moonshine::pagination.results',
            ],
        );
    }

    /**
     * Parse an RFC 5988 Link header into `{ first?, prev?, next?, last? } => URL`.
     * Format: `<url>; rel="next", <url>; rel="last"`. Unknown rels are dropped.
     *
     * @return array<string, string>
     */
    private function parseLinkHeader(string $header): array
    {
        if (trim($header) === '') {
            return [];
        }

        $out = [];
        foreach (explode(',', $header) as $segment) {
            if (! preg_match('/<([^>]+)>\s*;\s*rel="?([^";]+)"?/', $segment, $m)) {
                continue;
            }
            [$_, $url, $rel] = $m;
            $rel = trim($rel);
            if (in_array($rel, ['first', 'prev', 'next', 'last'], true)) {
                $out[$rel] = trim($url);
            }
        }

        return $out;
    }

    /**
     * Extract a single query-string parameter value from a URL. Returns '' if the URL
     * is malformed or the param is absent.
     */
    private function queryParamFromUrl(string $url, string $param): string
    {
        $query = parse_url($url, PHP_URL_QUERY);
        if (! is_string($query) || $query === '') {
            return '';
        }

        parse_str($query, $parsed);

        $value = $parsed[$param] ?? '';

        return is_string($value) ? $value : '';
    }

    /**
     * Hook for subclasses that build a cursor-paginated list to read the
     * incoming cursor token from the query string. Returning null signals
     * "first page" so the paginator starts at the beginning.
     *
     * Kept protected even though the abstract base doesn't call it — the
     * refactor will route cursor support through this helper once the
     * builder lands. PHPStan sees it as unused without the @phpstan-param
     * annotation below; the param name is the query-string key.
     */
    protected function resolveIncomingCursor(string $param): ?Cursor
    {
        $value = $this->getCore()->getRequest()->get($param);

        return is_string($value) && $value !== ''
            ? Cursor::fromEncoded($value)
            : null;
    }

    /**
     * Create (POST) or update (PUT) an item via HTTP.
     */
    public function save(DataWrapperContract $item, ?FieldsContract $fields = null): DataWrapperContract
    {
        $isNew = $item->getKey() === null;
        $data = $item->toArray();

        Log::info('AbstractRestResource::save', [
            'resource' => static::class,
            'is_new' => $isNew,
            'item_key' => $item->getKey(),
        ]);

        // Mirror ModelResource behavior: always resolve form fields when not provided
        $fields ??= $this->getFormFields()->onlyFields(withApplyWrappers: true);
        $this->assertHostedFieldsSafe($fields);
        $fields->fill($item->toArray(), $item);
        $data = $this->applyFields($data, $fields);

        Log::debug('AbstractRestResource::save — payload prepared', [
            'resource' => static::class,
            'is_new' => $isNew,
            'payload_keys' => array_keys($data),
        ]);

        $itemKey = $item->getKey() ?? '';
        $response = $isNew
            ? $this->performCreate($data)
            : $this->performUpdate($itemKey, $data);

        Log::debug('AbstractRestResource::save — response', [
            'resource' => static::class,
            'status' => $response->status(),
            'is_new' => $isNew,
        ]);

        // Mutations-powered update: performUpdate already threw on
        // non-ignored mutation failures per-step, and no single mutation
        // response is authoritative post-update state. Re-fetch via the
        // same strategy findItem() uses so `detail_strategy: "list"` (set
        // on the resource or inherited from the service) applies on
        // post-save too — otherwise a raw GET /{id} would 405 against
        // list-only catalogues (tags, statuses).
        if (! $isNew && $this->hasUpdateMutations()) {
            $responseData = $this->fetchCanonicalItemData($itemKey);
        } else {
            $response->throw();
            $responseData = $this->extractItemData($response);
        }

        if ($isNew) {
            $this->isRecentlyCreated = true;
        } else {
            $responseData = $this->normalizeCrudItem($responseData === [] ? $data : $responseData, $itemKey);
        }

        $result = $this->getCaster()->cast($responseData);

        // Update internal item state
        $this->setItem($result->getOriginal());

        // Run afterApply on fields (e.g. BelongsToMany sync)
        foreach ($fields as $field) {
            $field->afterApply($result);
        }

        Log::info('AbstractRestResource::save — completed', [
            'resource' => static::class,
            'result_key' => $result->getKey(),
            'is_new' => $isNew,
        ]);

        return $result;
    }

    /**
     * Fetch a single item by hitting the LIST endpoint with a key filter,
     * used for APIs that don't expose GET /resource/{id} (catalogues like
     * tags/statuses that are read as a whole list and written in-line).
     *
     * @param array<string, mixed> $detailQuery
     */
    private function findItemViaListFilter(
        int|string $itemId,
        array $detailQuery,
        ResponseMapping $mapping,
        bool $orFail,
    ): ?DataWrapperContract {
        // Resource-level override wins over service default — same escape
        // hatch as detailStrategy itself so per-resource configs stay
        // self-contained.
        $resourceKeyParam = $this->getDetailListKeyParamOverride();
        $keyParam = $resourceKeyParam !== null && $resourceKeyParam !== ''
            ? $resourceKeyParam
            : ($mapping->detailListKeyParam !== '' ? $mapping->detailListKeyParam : $mapping->primaryKey);

        $query = array_merge($detailQuery, [$keyParam => $itemId]);

        $response = $this->performIndexRequest($this->resolvedEndpoint(), $query);

        Log::debug('AbstractRestResource::findItem — list-strategy response', [
            'resource' => static::class,
            'status' => $response->status(),
            'item_id' => $itemId,
            'key_param' => $keyParam,
        ]);

        if ($response->notFound()) {
            if ($orFail) {
                throw new NotFoundHttpException("Item not found: {$itemId}");
            }

            return null;
        }

        $response->throw();

        $json = $response->json();
        $items = is_array($json) ? data_get($json, $mapping->dataKey, $json) : [];

        if (! is_array($items) || $items === []) {
            if ($orFail) {
                throw new NotFoundHttpException("Item not found in list for id={$itemId}");
            }

            return null;
        }

        $match = null;
        foreach ($items as $row) {
            if (! is_array($row)) {
                continue;
            }
            $rowId = data_get($row, $mapping->primaryKey);
            if (is_scalar($rowId) && (string) $rowId === (string) $itemId) {
                $match = $row;
                break;
            }
        }

        if ($match === null) {
            Log::warning('Requested record absent from list response', [
                'resource' => static::class,
                'item_id' => $itemId,
            ]);
            if ($orFail) {
                throw new NotFoundHttpException("Item {$itemId} not in list response");
            }

            return null;
        }

        return $this->getCaster()->cast($match);
    }

    /**
     * Hook: perform the HTTP create request for this resource.
     * If `$mutations['create']` is set, that declarative routing wins.
     * Otherwise defaults to `POST {endpoint}`. Subclasses may still
     * override for arbitrary custom behaviour.
     *
     * @param array<string, mixed> $data
     */
    protected function performCreate(array $data): Response
    {
        $create = $this->mutations['create'] ?? null;

        if (is_array($create)) {
            /** @var array<string, mixed> $createSpec */
            $createSpec = $create;

            return $this->dispatchMutation($createSpec, $data, null);
        }

        return $this->httpClient()->post($this->resolvedEndpoint(), $data);
    }

    /**
     * Hook: perform the HTTP update request for this resource.
     * If `$mutations['update']` is set (a list of mutation specs), dispatch
     * each in order, skip those whose `when_present` column isn't in the
     * payload, and treat `ignore_status` codes as success. Canonical
     * post-update state is then refetched by save() via
     * fetchCanonicalItemData() — which honours detail_strategy, so
     * list-only catalogues don't blow up on GET /{id}. Otherwise defaults
     * to `PUT {itemEndpoint}`.
     *
     * @param array<string, mixed> $data
     */
    protected function performUpdate(int|string $key, array $data): Response
    {
        $updates = $this->mutations['update'] ?? null;

        if (is_array($updates) && $updates !== []) {
            $lastResponse = null;

            foreach ($updates as $mutation) {
                if (! is_array($mutation)) {
                    continue;
                }

                /** @var array<string, mixed> $mutation */
                $when = $mutation['when_present'] ?? null;
                if (is_string($when) && $when !== '' && ! array_key_exists($when, $data)) {
                    continue;
                }

                $response = $this->dispatchMutation($mutation, $data, $key);
                $lastResponse = $response;

                if ($response->failed()) {
                    $ignored = $mutation['ignore_status'] ?? [];
                    if (! is_array($ignored) || ! in_array($response->status(), $ignored, true)) {
                        $response->throw();
                    }
                }
            }

            // save() picks up canonical state through fetchCanonicalItemData()
            // — strategy-aware, so list-only resources work here too. The
            // body of the returned response is discarded; only its status
            // is logged. Synthesize a 204 when every mutation was skipped
            // via when_present (nothing dispatched).
            return $lastResponse ?? new Response(new GuzzlePsr7Response(204));
        }

        return $this->httpClient()->put($this->itemEndpoint($key), $data);
    }

    /**
     * Whether this resource routes updates through declarative mutations
     * rather than a single PUT/PATCH. save() uses this to decide whether
     * to trust the update response body or refetch canonical state.
     */
    protected function hasUpdateMutations(): bool
    {
        $updates = $this->mutations['update'] ?? null;

        return is_array($updates) && $updates !== [];
    }

    /**
     * Fetch canonical single-item data as an array, honouring the effective
     * detail_strategy (resource override wins over the service-level
     * default). Empty array when not found — save() treats that as "no new
     * canonical state to merge" rather than fatal, since the upstream
     * mutation itself already succeeded.
     *
     * Used by save() for the post-mutation refetch so this path routes
     * identically to findItem() — without it, a list-only resource
     * (statuses, tags, categories) would 405 on GET /{id} after update.
     *
     * @return array<string, mixed>
     */
    protected function fetchCanonicalItemData(int|string $key): array
    {
        $mapping = $this->getServiceConfig()->responseMapping;
        $resourceDetailStrategy = $this->getDetailStrategyOverride();
        $effectiveStrategy = $resourceDetailStrategy ?? $mapping->detailStrategy;

        $detailQuery = is_array($this->defaultQuery['detail'] ?? null)
            ? $this->defaultQuery['detail']
            : [];

        if ($effectiveStrategy === 'list') {
            $wrapper = $this->findItemViaListFilter($key, $detailQuery, $mapping, orFail: false);

            if (! $wrapper instanceof DataWrapperContract) {
                return [];
            }

            $original = $wrapper->getOriginal();

            if (is_array($original)) {
                /** @var array<string, mixed> $original */
                return $original;
            }

            /** @var array<string, mixed> $toArray */
            $toArray = $wrapper->toArray();

            return $toArray;
        }

        $response = $this->httpClient()->get($this->itemEndpoint($key), $detailQuery);

        if ($response->notFound()) {
            return [];
        }

        $response->throw();

        return $this->extractItemData($response);
    }

    /**
     * Dispatch a single mutation spec against the HTTP client. Interpolates
     * `{column}` placeholders in path + body from the incoming $data (and
     * `{id}` from the route key). Field-subset projection uses `body_from`,
     * `body` (literal templated map), or the whole payload as fallback.
     *
     * @param array<string, mixed> $spec
     * @param array<string, mixed> $data
     */
    private function dispatchMutation(array $spec, array $data, int|string|null $key): Response
    {
        $rawMethod = is_string($spec['method'] ?? null) ? strtolower($spec['method']) : 'post';
        $method = in_array($rawMethod, ['get', 'post', 'put', 'patch', 'delete'], true) ? $rawMethod : 'post';
        $path = is_string($spec['path'] ?? null) && $spec['path'] !== ''
            ? $spec['path']
            : $this->resolvedEndpoint();

        $substitutions = $data;
        if ($key !== null) {
            $substitutions['id'] = $key;
        }

        $path = $this->substitutePlaceholders($path, $substitutions, path: true);

        $body = $this->projectMutationBody($spec, $data);

        if ($method === 'get' || $method === 'delete') {
            return $this->httpClient()->{$method}($path);
        }

        return $this->httpClient()->{$method}($path, $body);
    }

    /**
     * Project the request body for a mutation. Priority:
     *   1. `body` map — literal payload with {column} interpolation.
     *   2. `body_from` list — pick a subset of the form payload.
     *   3. No hint — send the whole form payload.
     *
     * @param array<string, mixed> $spec
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function projectMutationBody(array $spec, array $data): array
    {
        if (isset($spec['body']) && is_array($spec['body'])) {
            $out = [];
            foreach ($spec['body'] as $k => $v) {
                if (! is_string($k)) {
                    continue;
                }
                $out[$k] = is_string($v) ? $this->substitutePlaceholders($v, $data) : $v;
            }

            return $out;
        }

        if (isset($spec['body_from']) && is_array($spec['body_from'])) {
            $out = [];
            foreach ($spec['body_from'] as $column) {
                if (is_string($column) && array_key_exists($column, $data)) {
                    $out[$column] = $data[$column];
                }
            }

            return $out;
        }

        return $data;
    }

    /**
     * Replace `{column}` tokens in a string with the matching value from
     * $vars. Unknown tokens are left in place so they surface in logs and
     * don't silently evaluate to empty.
     *
     * @param array<string, mixed> $vars
     */
    private function substitutePlaceholders(string $template, array $vars, bool $path = false): string
    {
        return preg_replace_callback(
            '/\{([a-zA-Z_]\w*)\}/',
            static function (array $match) use ($vars, $path): string {
                $name = $match[1];
                if (! array_key_exists($name, $vars)) {
                    return $match[0];
                }
                $value = $vars[$name];

                if ($path && (is_scalar($value) || $value === null)) {
                    return PathSegment::encode((string) $value);
                }

                return is_scalar($value) || $value === null ? (string) $value : $match[0];
            },
            $template,
        ) ?? $template;
    }

    /**
     * Delete a single item via HTTP DELETE.
     */
    public function delete(DataWrapperContract $item, ?FieldsContract $fields = null): bool
    {
        $key = $item->getKey();

        Log::info('AbstractRestResource::delete', [
            'resource' => static::class,
            'item_key' => $key,
        ]);

        if ($key === null) {
            Log::warning('AbstractRestResource::delete — no key, skipping', [
                'resource' => static::class,
            ]);

            return false;
        }

        $response = $this->httpClient()->delete($this->itemEndpoint($key));

        Log::debug('AbstractRestResource::delete — response', [
            'resource' => static::class,
            'status' => $response->status(),
            'item_key' => $key,
        ]);

        $response->throw();

        Log::info('AbstractRestResource::delete — completed', [
            'resource' => static::class,
            'item_key' => $key,
        ]);

        return true;
    }

    /**
     * Payload-driven CREATE (MCP / API shim).
     *
     * The generator + UI path goes through save()/applyFields() so per-field
     * resolveOnApply (password hashing, cast helpers, …) runs. The MCP
     * `create_resource_item` tool does not render a form — its caller hands us
     * a ready-to-POST payload. Routing through the same performCreate() hook
     * preserves declarative `mutations.create` routing (per performCreate:853)
     * without invoking the UI-coupled field pipeline.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed> Upstream response body, with the
     *                              configured primary_key guaranteed at
     *                              top level if the upstream omitted it.
     */
    public function createItem(array $payload): array
    {
        $this->guardAgainstUnresolvedEndpoint();

        Log::info('AbstractRestResource::createItem', [
            'resource' => static::class,
            'payload_keys' => array_keys($payload),
        ]);

        $response = $this->performCreate($payload);
        $response->throw();

        $data = $this->extractItemData($response);

        Log::info('AbstractRestResource::createItem — completed', [
            'resource' => static::class,
            'status' => $response->status(),
        ]);

        return $this->normalizeCrudItem($data, null);
    }

    /**
     * Payload-driven UPDATE (MCP / API shim).
     *
     * Pre-checks existence via findItem(orFail: true) so a 404 from upstream
     * surfaces as NotFoundHttpException *before* we attempt the PUT. After
     * the mutation, refetches canonical state via fetchCanonicalItemData()
     * when declarative `mutations.update` is in play — list-only catalogues
     * (statuses, tags) cannot be re-read via GET /{id} and would 405 on a
     * naive post-update refetch.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function updateItem(int|string $id, array $payload): array
    {
        $this->guardAgainstUnresolvedEndpoint();

        Log::info('AbstractRestResource::updateItem', [
            'resource' => static::class,
            'item_id' => $id,
            'payload_keys' => array_keys($payload),
        ]);

        // Seed the request-scoped item id so findItem/getItemID agree; the
        // UI flow does the same via routing. Without this, findItem()
        // returns null even for existing records and we'd 404 spuriously.
        $this->setItemID($id);
        $existing = $this->findItem(orFail: false);
        if (! $existing instanceof DataWrapperContract) {
            throw new NotFoundHttpException("Item not found: {$id}");
        }

        $response = $this->performUpdate($id, $payload);

        if ($this->hasUpdateMutations()) {
            $canonical = $this->fetchCanonicalItemData($id);
        } else {
            $response->throw();
            $canonical = $this->extractItemData($response);
        }

        Log::info('AbstractRestResource::updateItem — completed', [
            'resource' => static::class,
            'item_id' => $id,
        ]);

        return $this->normalizeCrudItem($canonical, $id);
    }

    /**
     * Payload-driven DELETE (MCP / API shim).
     *
     * Pre-checks existence (same reason as updateItem — 404 surfaces before
     * the DELETE hits the wire) and then issues DELETE {itemEndpoint}. No
     * mutation-map routing here: the UI delete path is the single-call
     * shape too, and declarative delete mutations would need their own spec
     * key anyway.
     */
    public function deleteItem(int|string $id): void
    {
        $this->guardAgainstUnresolvedEndpoint();

        Log::info('AbstractRestResource::deleteItem', [
            'resource' => static::class,
            'item_id' => $id,
        ]);

        $this->setItemID($id);
        $existing = $this->findItem(orFail: false);
        if (! $existing instanceof DataWrapperContract) {
            throw new NotFoundHttpException("Item not found: {$id}");
        }

        $response = $this->httpClient()->delete($this->itemEndpoint($id));

        if ($response->notFound()) {
            throw new NotFoundHttpException("Item not found: {$id}");
        }

        $response->throw();

        Log::info('AbstractRestResource::deleteItem — completed', [
            'resource' => static::class,
            'item_id' => $id,
            'status' => $response->status(),
        ]);
    }

    /**
     * Ensure the configured primary_key is at the top level of the returned
     * payload. Some upstreams nest the id under `data`/`attributes` and
     * `extractItemData` may not have unwrapped it; others (204 No Content
     * on PUT) hand us back an empty body and we need the caller-supplied id
     * to be discoverable.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function normalizeCrudItem(array $data, int|string|null $fallbackKey): array
    {
        $primary = $this->getServiceConfig()->responseMapping->primaryKey;
        $primary = $primary !== '' ? $primary : 'id';

        if (! array_key_exists($primary, $data) && $fallbackKey !== null) {
            $data[$primary] = $fallbackKey;
        }

        return $data;
    }

    /**
     * Delete multiple items by IDs.
     */
    public function massDelete(array $ids): void
    {
        foreach ($ids as $id) {
            PathSegment::encode($id);
        }

        Log::info('AbstractRestResource::massDelete', [
            'resource' => static::class,
            'ids' => $ids,
            'count' => count($ids),
        ]);

        foreach ($ids as $id) {
            $wrapper = new MixedDataWrapper(
                [$this->getServiceConfig()->responseMapping->primaryKey => $id],
                $id,
            );

            $this->delete($wrapper);
        }

        Log::info('AbstractRestResource::massDelete — completed', [
            'resource' => static::class,
            'count' => count($ids),
        ]);
    }

    /**
     * Closure for applying a field's request value to the item data during save.
     */
    public function fieldApply(FieldContract $field): Closure
    {
        return static function (mixed $item) use ($field): mixed {
            $column = $field->getColumn();
            $hasDirectValue = request()->exists($column);

            if (! $field->hasRequestValue() && ! $field->getDefaultIfExists() && ! $hasDirectValue) {
                return $item;
            }

            $value = $field->getRequestValue();

            if ($value === false && $hasDirectValue) {
                $value = request()->input($column);
            } elseif ($value === false) {
                $value = null;
            }

            data_set($item, $column, $value);

            return $item;
        };
    }

    /**
     * Return an empty array as the data instance (no Eloquent model).
     *
     * @return array<string, mixed>
     */
    #[Override]
    public function getDataInstance(): mixed
    {
        return [];
    }

    /**
     * Extract item data from an HTTP response.
     * Override if your API wraps single items differently.
     *
     * @return array<string, mixed>
     */
    protected function extractItemData(Response $response): array
    {
        $json = $response->json();

        if (! is_array($json)) {
            // Some upstreams concatenate a stray error payload after the
            // real JSON (e.g. a post-save listener that fails and leaks
            // its exception into the response body). json_decode then
            // returns null and the caller sees "empty" data — no ID to
            // redirect to after create. Salvage the first well-formed
            // top-level JSON object from the raw body.
            $salvaged = $this->salvageLeadingJsonObject((string) $response->body());

            if (is_array($salvaged)) {
                Log::warning('AbstractRestResource::extractItemData — recovered from trailing garbage', [
                    'resource' => static::class,
                    'body_length' => strlen((string) $response->body()),
                ]);
                $json = $salvaged;
            } else {
                return [];
            }
        }

        $mapping = $this->getServiceConfig()->responseMapping;

        // Explicit detail envelope path — use when the single-item wrapper
        // is deeper than / different from the list wrapper (Laravel
        // JsonResource wrapping the record under a non-"data" key, or
        // double-nested shapes like `{"resource": {"data": {...}}}`).
        // Walks a dot-path so multi-level envelopes resolve in one step.
        if ($mapping->detailDataKey !== '') {
            $extracted = data_get($json, $mapping->detailDataKey);
            if (is_array($extracted) && ! array_is_list($extracted)) {
                /** @var array<string, mixed> $extracted */
                return $extracted;
            }
            Log::warning('AbstractRestResource::extractItemData — detail_data_key missed', [
                'resource' => static::class,
                'detail_data_key' => $mapping->detailDataKey,
                'top_level_keys' => array_keys($json),
                'hint' => 'detail_data_key did not resolve to an associative array; falling back to default extraction logic.',
            ]);
        }

        // Primary path: respect the configured data_key. Many APIs re-use the
        // list wrapper for single-item responses (Laravel API Resources:
        // GET /posts/1 → {"data": {...}}), so this lookup usually succeeds.
        $dataKey = $mapping->dataKey;

        if (isset($json[$dataKey]) && is_array($json[$dataKey]) && ! array_is_list($json[$dataKey])) {
            /** @var array<string, mixed> $wrapped */
            $wrapped = $json[$dataKey];

            return $wrapped;
        }

        // Fallback: REST APIs often use a SINGULAR wrapper for single-item
        // responses even when the list uses a plural one (e.g. collection
        // wraps in `tasks`, detail wraps in `task`). Without this unwrap the
        // caster sees `{"task": {...}}` as the full item — primary_key isn't
        // at the top level, so the resource reports the item as not found.
        if (count($json) === 1) {
            $only = reset($json);

            if (is_array($only) && ! array_is_list($only)) {
                /** @var array<string, mixed> $only */
                return $only;
            }
        }

        /** @var array<string, mixed> $json */
        return $json;
    }

    /**
     * Scan a raw response body and return the first complete top-level
     * JSON object as a PHP array. Handles the "valid JSON, then trailing
     * garbage" pattern produced by frameworks that leak a second payload
     * (exceptions, debug output) into the body after the real response.
     *
     * @return array<string, mixed>|null
     */
    private function salvageLeadingJsonObject(string $body): ?array
    {
        $body = ltrim($body);

        if ($body === '' || $body[0] !== '{') {
            return null;
        }

        $depth = 0;
        $inString = false;
        $escape = false;

        for ($i = 0, $len = strlen($body); $i < $len; $i++) {
            $ch = $body[$i];

            if ($escape) {
                $escape = false;
                continue;
            }

            if ($ch === '\\' && $inString) {
                $escape = true;
                continue;
            }

            if ($ch === '"') {
                $inString = ! $inString;
                continue;
            }

            if ($inString) {
                continue;
            }

            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;

                if ($depth === 0) {
                    $candidate = substr($body, 0, $i + 1);
                    $decoded = json_decode($candidate, true);

                    if (! is_array($decoded)) {
                        return null;
                    }

                    /** @var array<string, mixed> $decoded */
                    return $decoded;
                }
            }
        }

        return null;
    }

    /**
     * Build query parameters for list requests.
     *
     * @return array<string, mixed>
     */
    protected function buildListParams(): array
    {
        $params = [];

        // Seed with index-time defaults so scope params (e.g. for_entity)
        // ride on every request. User-driven pagination / sort / search /
        // filters below may overwrite individual keys, which is correct —
        // explicit user choice must win over a baked-in default.
        $indexDefaults = is_array($this->defaultQuery['index'] ?? null)
            ? $this->defaultQuery['index']
            : [];

        foreach ($indexDefaults as $k => $v) {
            $params[(string) $k] = $v;
        }

        $mapping = $this->getServiceConfig()->responseMapping;

        // Pagination — emit query params matching the upstream paginator:
        //   length_aware (default) → ?page=N&per_page=M
        //   link_header            → ?page=N&per_page=M (same shape; navigation via Link header)
        //   cursor                 → ?cursor=...&per_page=M (no cursor on first page)
        //   none                   → no pagination params at all
        if (in_array($mapping->paginationType, ['length_aware', 'link_header'], true) && $this->isPaginationUsed()) {
            $params['page'] = max(1, $this->getPaginatorPage() ?: 1) - (int) $mapping->zeroBasedPages;
            $params[$mapping->perPageParamName] = $this->getItemsPerPage();
        } elseif ($mapping->paginationType === 'cursor') {
            $cursorRaw = $this->getCore()->getRequest()->get($mapping->cursorQueryParam, '');
            $cursor = is_string($cursorRaw) ? $cursorRaw : '';

            if ($cursor !== '') {
                $params[$mapping->cursorQueryParam] = $cursor;
            }

            $params[$mapping->perPageParamName] = $this->getItemsPerPage();
        }

        // Sorting — skip entirely when the upstream API doesn't support it.
        // Otherwise the sort query param silently no-ops and users think the
        // sort controls are broken. The param name is configurable
        // (sort_param_name in response_mapping) for APIs that use
        // "order_by" / "ordering" / similar instead of "sort".
        if ($mapping->supportsSorting) {
            [$sortColumn, $sortDirection] = $this->prepareOrder();

            if ($sortColumn !== '') {
                $params[$mapping->sortParamName] = ($sortDirection === 'desc' ? '-' : '').$sortColumn;
            }
        }

        // Search — param name is configurable (search_param_name). Many
        // APIs use "q" or "query" instead of the default "search".
        $searchTerm = data_get($this->getQueryParams(), $this->getSearchQueryKey());

        if (filled($searchTerm)) {
            $params[$mapping->searchParamName] = $searchTerm;
        }

        // Filters — two wire formats supported:
        //   nested (default):   ?<param>[status]=todo    (Laravel / JSON:API)
        //   flat:               ?status=todo             (spread filter keys onto top-level query)
        // `filter_param_name` (default "filter") lets you switch to the
        // plural form "filters" that Laravel projects using Spatie Query
        // Builder default config expect (`?filters[status]=todo`). Wrong
        // pair silently yields an unfiltered list — "filters are broken".
        $filterParams = $this->buildFilterParams();

        if ($filterParams !== []) {
            if ($mapping->filterFormat === 'flat') {
                foreach ($filterParams as $key => $value) {
                    if ($key !== '') {
                        $params[$key] = $value;
                    }
                }
            } else {
                $params[$mapping->filterParamName] = $filterParams;
            }
        }

        Log::debug('AbstractRestResource::buildListParams', [
            'resource' => static::class,
            'params' => $params,
        ]);

        return $params;
    }

    /**
     * Dispatch an index/list request. GET keeps the historical query-string
     * behavior; POST sends the list params as JSON body and lets $indexBody
     * provide/override required static body keys.
     *
     * @param array<string, mixed> $params
     */
    protected function performIndexRequest(string $endpoint, array $params): Response
    {
        $method = strtoupper($this->indexMethod);

        if ($method === 'POST') {
            $body = $this->buildPostIndexBody($params);

            Log::debug('AbstractRestResource::performIndexRequest — POST', [
                'resource' => static::class,
                'endpoint' => $endpoint,
                'body_keys' => array_keys($body),
            ]);

            return $this->httpClient()->post($endpoint, $body);
        }

        if ($method !== 'GET') {
            Log::warning('AbstractRestResource::performIndexRequest — unsupported index method, falling back to GET', [
                'resource' => static::class,
                'endpoint' => $endpoint,
                'method' => $method,
            ]);
        }

        return $this->httpClient()->get($endpoint, $params);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function buildPostIndexBody(array $params): array
    {
        $body = $this->indexBody;

        foreach ($params as $key => $value) {
            $body[$key] = is_array($this->indexBody[$key] ?? null) && ! is_array($value)
                ? [$value]
                : $value;
        }

        return $body;
    }

    /**
     * Build query parameters for list requests including filters.
     *
     * @return array<string, mixed>
     */
    protected function buildFilterParams(): array
    {
        $filterData = $this->getFilterParams();

        /** @var array<string, mixed> $normalized */
        $normalized = [];
        foreach ($filterData as $k => $v) {
            $normalized[(string) $k] = $v;
        }

        return $normalized;
    }

    /**
     * Apply field values from request to item data array.
     *
     * Uses MoonShine's field apply mechanism so that fields with custom
     * resolveOnApply() (e.g. RestBelongsToMany) are handled correctly.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    protected function applyFields(array $data, FieldsContract $fields): array
    {
        Log::debug('AbstractRestResource::applyFields', [
            'resource' => static::class,
            'fields_count' => $fields->count(),
        ]);

        foreach ($fields as $field) {
            $applied = $field->apply($this->fieldApply($field), $data);
            if (is_array($applied)) {
                /** @var array<string, mixed> $applied */
                $data = $applied;
            }
        }

        return $data;
    }

    /**
     * Attach this resource as the parent to every REST relation field, even
     * when the field is nested inside a Box/Tabs/Flex wrapper in the page's
     * fields() return value. Without recursion via onlyFields(), wrapping the
     * form in `Box::make([...])` (the native MoonShine pattern, produced by
     * form-page.stub) hid every RestBelongsTo/RestBelongsToMany from the
     * parent lookup and made their dropdowns render without options.
     */
    /**
     * @template TFields of FieldsContract
     *
     * @param TFields $fields
     *
     * @return TFields
     */
    public function attachParentResourceToFields(FieldsContract $fields): FieldsContract
    {
        $this->assertHostedFieldsSafe($fields);
        foreach ($fields->onlyFields() as $field) {
            if (method_exists($field, 'resource')) {
                $field->resource($this);
            }
        }

        return $fields;
    }

    private function assertHostedFieldsSafe(FieldsContract $fields): void
    {
        if (config('api-admin.hosted.mode', 'local') !== 'hosted') {
            return;
        }
        foreach ($fields->onlyFields(withWrappers: true) as $field) {
            if ($field instanceof File) {
                Log::warning('Rejected shared upload field', ['resource' => static::class]);
                abort(403, 'Native file/image fields require trusted local mode. Use an upstream URL field in hosted resources.');
            }
            if ($field instanceof Json) {
                $this->assertHostedFieldsSafe($field->getFields());
            }
        }
    }
}
