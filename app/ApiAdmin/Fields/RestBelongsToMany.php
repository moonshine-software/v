<?php

declare(strict_types=1);

namespace App\ApiAdmin\Fields;

use App\ApiAdmin\Fields\Concerns\HasEndpointQuery;
use App\ApiAdmin\Http\PathSegment;
use App\ApiAdmin\Resources\AbstractRestResource;
use Closure;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use LogicException;
use MoonShine\Contracts\Core\TypeCasts\DataWrapperContract;
use MoonShine\UI\Fields\Select;
use Override;
use Throwable;

/**
 * REST-based BelongsToMany field — a multi-select for attach/detach operations via HTTP.
 *
 * Renders a searchable multi-select populated from a related API endpoint.
 * On save, sends attach/detach requests to manage the many-to-many relationship.
 *
 * Usage in resource fields():
 *   RestBelongsToMany::make('Tags', 'tags')
 *       ->endpoint('/tags')
 *       ->attachEndpoint('/posts/{parent_id}/tags')
 *       ->valueKey('id')
 *       ->labelKey('name')
 */
class RestBelongsToMany extends Select
{
    use HasEndpointQuery;

    protected string $apiEndpoint = '';

    protected string $attachEndpoint = '';

    protected string $valueKey = 'id';

    protected bool $hasExplicitValueKey = false;

    protected string $labelKey = 'name';

    protected string $parentKeyParam = 'parent_id';

    protected ?AbstractRestResource $parentResource = null;

    /**
     * Optional batch endpoint: `/posts/relations?ids[]={ids}`. When set, `primeAssociations()`
     * can pull all parent→related mappings in one HTTP call instead of N. Response shape
     * is upstream-specific — caller provides a mapper via `->batchAttachMapper()`.
     */
    protected string $batchAttachEndpoint = '';

    /** @var (Closure(array<array-key, mixed>): array<array-key, mixed>)|null */
    protected ?Closure $batchAttachMapper = null;

    /**
     * Per-PHP-request cache to collapse duplicate HTTP calls.
     * Keyed by `method:URL:queryHash`. Holds whatever the individual loader returned
     * (options map or current-ids list). Reset naturally between HTTP requests — static
     * storage is local to the CLI/FPM process lifetime.
     *
     * @var array<string, mixed>
     */
    protected static array $requestCache = [];

    /**
     * Primed bulk cache: parent_id → list<string> of related ids. Populated by
     * `primeAssociations()` or by a successful `batchAttachEndpoint` call on first
     * preview. `loadCurrentAssociations()` reads from here before falling back to HTTP.
     *
     * @var array<string, array<string, list<string>>>
     */
    protected static array $primedAssociations = [];

    /**
     * Constructor override to enable multiple selection by default.
     */
    public function __construct(string $label, ?string $column = null, ?Closure $formatted = null)
    {
        parent::__construct($label, $column, $formatted);

        $this->multiple();
    }

    /**
     * Set the API endpoint for loading all available options.
     */
    public function endpoint(string $endpoint): static
    {
        $this->apiEndpoint = $endpoint;

        Log::debug('RestBelongsToMany::endpoint', [
            'field' => $this->getColumn(),
            'endpoint' => $endpoint,
        ]);

        return $this;
    }

    /**
     * Set the API endpoint for attach/detach operations.
     * Use {parent_id} as placeholder for the parent item's key.
     */
    public function attachEndpoint(string $endpoint): static
    {
        $this->attachEndpoint = $endpoint;

        return $this;
    }

    /**
     * Set the value key for options.
     */
    public function valueKey(string $key): static
    {
        $this->valueKey = $key;
        $this->hasExplicitValueKey = true;

        return $this;
    }

    public function getValueKey(): string
    {
        return $this->hasExplicitValueKey || $this->relatedResourceClass === null
            ? $this->valueKey
            : ($this->getRelationTarget()?->serviceConfig()->responseMapping->primaryKey ?? $this->valueKey);
    }

    /**
     * Set the label key for options.
     */
    public function labelKey(string $key): static
    {
        $this->labelKey = $key;

        return $this;
    }

    /**
     * Set the parent key parameter for endpoint substitution.
     */
    public function parentKeyParam(string $param): static
    {
        $this->parentKeyParam = $param;

        return $this;
    }

    /**
     * Set the data wrapper key.
     */
    public function dataWrapper(?string $wrapper): static
    {
        $this->dataWrapper = $wrapper;

        return $this;
    }

    /**
     * Set the parent resource for HTTP client access.
     */
    public function resource(AbstractRestResource $resource): static
    {
        $this->parentResource = $resource;

        return $this;
    }

    public function getAttachEndpoint(): string
    {
        return $this->attachEndpoint;
    }

    /**
     * Optional bulk-fetch endpoint. `{ids}` is replaced with a comma-separated list of
     * parent ids (e.g. `/posts/relations?parent_ids[]={ids}` → `?parent_ids[]=1,2,3`).
     *
     * Pair with `->batchAttachMapper()` to decode the upstream response into a
     * `parent_id → [related_ids…]` map. When either side is missing, preview falls back
     * to per-row `loadCurrentAssociations()` (the current N+1 path).
     */
    public function batchAttachEndpoint(string $endpoint): static
    {
        $this->batchAttachEndpoint = $endpoint;

        return $this;
    }

    /**
     * Decode the batch response. Receives the raw `response->json()` body and must return
     * `[parent_id => [related_id, ...], ...]`. All ids are coerced to strings by the
     * loader; leave trimming/casing to the upstream contract.
     *
     * @param Closure(array<array-key, mixed>): array<array-key, mixed> $mapper
     */
    public function batchAttachMapper(Closure $mapper): static
    {
        $this->batchAttachMapper = $mapper;

        return $this;
    }

    /**
     * Populate the primed-associations cache from a bulk fetch. Call once per list render
     * when you can enumerate parent ids up-front (e.g. from a `beforeIndexRender` hook):
     *
     *     $tagsField->primeAssociations(['1','2','3']);
     *
     * Requires `batchAttachEndpoint` + `batchAttachMapper` to be configured. Returns the
     * loaded map so callers can inspect/override. Returns an empty array (and logs) on
     * failure — keeps per-row path as the safety net.
     *
     * @param list<string|int> $parentIds
     *
     * @return array<string, list<string>>
     */
    public function primeAssociations(array $parentIds): array
    {
        $target = $this->resolveRelationTarget($this->dataWrapper);

        if (! $target instanceof RestRelationTarget
            || $this->batchAttachEndpoint === ''
            || ! $this->batchAttachMapper instanceof Closure
            || $parentIds === []
        ) {
            return [];
        }

        $normalizedIds = array_map(static fn ($id): string => (string) $id, $parentIds)
            |> array_unique(...)
            |> array_values(...);
        $endpoint = str_replace('{ids}', implode(',', array_map(PathSegment::encode(...), $normalizedIds)), $this->batchAttachEndpoint);

        Log::debug('RestBelongsToMany::primeAssociations', [
            'field' => $this->getColumn(),
            'endpoint' => $endpoint,
            'ids' => $normalizedIds,
        ]);

        try {
            $queryString = parse_url($endpoint, PHP_URL_QUERY);
            parse_str(is_string($queryString) ? $queryString : '', $query);
            $response = $target->httpClient()->get($endpoint, http_build_query(array_replace($query, $this->resolveEndpointQuery())));
            $response->throw();

            $json = $response->json();
            $mapped = ($this->batchAttachMapper)(is_array($json) ? $json : []);

            $bucket = [];
            foreach ($mapped as $parentId => $relatedIds) {
                $pid = (string) $parentId;
                $ids = [];
                foreach ((array) $relatedIds as $rid) {
                    if (is_scalar($rid)) {
                        $ids[] = (string) $rid;
                    }
                }
                $bucket[$pid] = $ids;
            }

            self::$primedAssociations[$this->primedCacheKey()] = $bucket;

            Log::debug('RestBelongsToMany::primeAssociations — loaded', [
                'field' => $this->getColumn(),
                'source_service' => $target->sourceService(),
                'target_service' => $target->targetService(),
                'parents_primed' => count($bucket),
            ]);

            return $bucket;
        } catch (Throwable $e) {
            Log::error('RestBelongsToMany::primeAssociations — failed', [
                'field' => $this->getColumn(),
                'exception' => $e::class,
                'status' => $e instanceof RequestException ? $e->response->status() : null,
            ]);

            return [];
        }
    }

    /**
     * Reset static per-request caches. Exposed for tests — production code relies on
     * process-level reset between HTTP requests.
     */
    public static function flushCaches(): void
    {
        self::$requestCache = [];
        self::$primedAssociations = [];
    }

    /**
     * Cache bucket identity for primed associations. Attach endpoint + batch endpoint
     * together uniquely identify one relation on one parent resource — two fields for
     * the same relation in different views share the cache.
     */
    protected function primedCacheKey(): string
    {
        $service = $this->targetService
            ?? $this->parentResource?->getServiceConfig()->name
            ?? '';

        return $service.'|'.$this->attachEndpoint.'|'.$this->batchAttachEndpoint;
    }

    public function getParentResource(): ?AbstractRestResource
    {
        return $this->parentResource;
    }

    /**
     * Build the attach/detach endpoint URL with parent ID substituted.
     */
    protected function resolveAttachEndpoint(?DataWrapperContract $parentItem = null): ?string
    {
        $parentKey = $parentItem?->getKey();

        return $this->resolveRelationEndpoint($this->attachEndpoint, [
            $this->parentKeyParam => $parentKey,
            'parent_id' => $parentKey,
        ], $this->relationEndpointContextFromItem($parentItem));
    }

    /**
     * Load all available options from the API endpoint.
     *
     * @return array<array-key, string>
     */
    public function loadOptions(): array
    {
        if (! $this->parentResource instanceof AbstractRestResource || $this->apiEndpoint === '') {
            Log::warning('RestBelongsToMany::loadOptions — missing resource or endpoint', [
                'field' => $this->getColumn(),
            ]);

            return [];
        }

        $target = $this->resolveRelationTarget($this->dataWrapper);
        if (! $target instanceof RestRelationTarget) {
            return [];
        }

        $query = $this->resolveEndpointQuery();
        $cacheKey = 'options:'.$target->targetService().':'.$this->apiEndpoint.':'.md5(serialize($query));

        if (array_key_exists($cacheKey, self::$requestCache)) {
            /** @var array<array-key, string> $cached */
            $cached = self::$requestCache[$cacheKey];

            return $cached;
        }

        Log::debug('RestBelongsToMany::loadOptions', [
            'field' => $this->getColumn(),
            'source_service' => $target->sourceService(),
            'target_service' => $target->targetService(),
            'endpoint' => $this->apiEndpoint,
            'query_keys' => array_keys($query),
        ]);

        try {
            $response = $target->httpClient()->get($this->apiEndpoint, $query);
            $response->throw();

            $json = $response->json();

            $items = data_get($json, $target->dataWrapper(), $json);

            if (! is_array($items)) {
                return [];
            }

            $options = [];

            foreach ($items as $item) {
                $rawValue = data_get($item, $this->getValueKey());
                $rawLabel = data_get($item, $this->labelKey);
                $value = is_scalar($rawValue) ? (string) $rawValue : '';
                $label = is_scalar($rawLabel) ? (string) $rawLabel : '';
                if ($value !== '') {
                    $options[$value] = $label;
                }
            }

            Log::debug('RestBelongsToMany::loadOptions — loaded', [
                'field' => $this->getColumn(),
                'options_count' => count($options),
            ]);

            self::$requestCache[$cacheKey] = $options;

            return $options;
        } catch (Throwable $e) {
            Log::error('RestBelongsToMany::loadOptions — failed', [
                'field' => $this->getColumn(),
                'exception' => $e::class,
                'status' => $e instanceof RequestException ? $e->response->status() : null,
            ]);

            return [];
        }
    }

    /**
     * Sync the many-to-many relationship via HTTP.
     * Sends the selected IDs to the attach endpoint.
     *
     * @param array<int, int|string> $ids
     */
    public function syncRelation(array $ids, ?DataWrapperContract $parentItem = null): bool
    {
        $target = $this->resolveRelationTarget($this->dataWrapper);
        if (! $target instanceof RestRelationTarget) {
            throw new LogicException(
                "RestBelongsToMany field '{$this->getColumn()}': parentResource is not set. Ensure the field is used within a REST resource.",
            );
        }

        if ($this->attachEndpoint === '') {
            throw new LogicException(
                "RestBelongsToMany field '{$this->getColumn()}': attachEndpoint is required. Add ->attachEndpoint('/endpoint/{parent_id}/relation') to the field definition.",
            );
        }

        $endpoint = $this->resolveAttachEndpoint($parentItem);
        if ($endpoint === null) {
            return false;
        }

        Log::info('RestBelongsToMany::syncRelation', [
            'field' => $this->getColumn(),
            'source_service' => $target->sourceService(),
            'target_service' => $target->targetService(),
            'endpoint' => $endpoint,
            'ids' => $ids,
        ]);

        try {
            $response = $target->httpClient()->put($endpoint, [
                'ids' => $ids,
            ]);

            $response->throw();

            Log::info('RestBelongsToMany::syncRelation — synced', [
                'field' => $this->getColumn(),
                'count' => count($ids),
            ]);

            return true;
        } catch (Throwable $e) {
            Log::error('RestBelongsToMany::syncRelation — failed', [
                'field' => $this->getColumn(),
                'exception' => $e::class,
                'status' => $e instanceof RequestException ? $e->response->status() : null,
            ]);

            return false;
        }
    }

    /**
     * Prepare the field for rendering — loads options and current associations from API.
     */
    #[Override]
    protected function prepareBeforeRender(): void
    {
        parent::prepareBeforeRender();

        if (! $this->parentResource instanceof AbstractRestResource) {
            return;
        }

        // Load all available options
        if ($this->getValues()->getValues()->isEmpty()) {
            $this->options($this->loadOptions());
        }

        // Load currently associated IDs and set as selected value (skip on index/preview to avoid N+1)
        $parentItem = $this->getData();

        if (! $this->isPreviewMode() && $parentItem instanceof DataWrapperContract && $parentItem->getKey() !== null) {
            if ($this->attachEndpoint === '') {
                throw new LogicException(
                    "RestBelongsToMany field '{$this->getColumn()}': attachEndpoint is required. Add ->attachEndpoint('/endpoint/{parent_id}/relation') to the field definition.",
                );
            }

            $currentIds = $this->loadCurrentAssociations($parentItem);

            if ($currentIds !== []) {
                $this->setValue($currentIds);
            }
        }
    }

    /**
     * Load currently associated IDs from the attach endpoint.
     *
     * @return list<string>
     */
    protected function loadCurrentAssociations(?DataWrapperContract $parentItem): array
    {
        if (! $parentItem instanceof DataWrapperContract || ! $this->parentResource instanceof AbstractRestResource || $this->attachEndpoint === '') {
            return [];
        }

        $target = $this->resolveRelationTarget($this->dataWrapper);
        if (! $target instanceof RestRelationTarget) {
            return [];
        }

        $parentKey = (string) ($parentItem->getKey() ?? '');
        if ($parentKey === '') {
            return [];
        }

        $primedBucket = self::$primedAssociations[$this->primedCacheKey()] ?? null;

        if (is_array($primedBucket) && array_key_exists($parentKey, $primedBucket)) {
            Log::debug('RestBelongsToMany::loadCurrentAssociations — primed hit', [
                'field' => $this->getColumn(),
                'parent_id' => $parentKey,
            ]);

            return $primedBucket[$parentKey];
        }

        $endpoint = $this->resolveAttachEndpoint($parentItem);
        if ($endpoint === null) {
            return [];
        }
        $query = $this->resolveEndpointQuery();
        $cacheKey = 'associations:'.$target->targetService().':'.$endpoint.':'.md5(serialize($query));

        if (array_key_exists($cacheKey, self::$requestCache)) {
            /** @var list<string> $cached */
            $cached = self::$requestCache[$cacheKey];

            return $cached;
        }

        Log::debug('RestBelongsToMany::loadCurrentAssociations', [
            'field' => $this->getColumn(),
            'source_service' => $target->sourceService(),
            'target_service' => $target->targetService(),
            'endpoint' => $endpoint,
            'query_keys' => array_keys($query),
        ]);

        try {
            $response = $target->httpClient()->get($endpoint, $query);
            $response->throw();

            $json = $response->json();

            $items = data_get($json, $target->dataWrapper(), $json);

            if (! is_array($items)) {
                return [];
            }

            $ids = [];
            foreach ($items as $item) {
                $raw = data_get($item, $this->getValueKey());
                if (is_scalar($raw)) {
                    $ids[] = (string) $raw;
                }
            }

            Log::debug('RestBelongsToMany::loadCurrentAssociations — loaded', [
                'field' => $this->getColumn(),
                'ids' => $ids,
            ]);

            self::$requestCache[$cacheKey] = $ids;

            return $ids;
        } catch (Throwable $e) {
            Log::error('RestBelongsToMany::loadCurrentAssociations — failed', [
                'field' => $this->getColumn(),
                'exception' => $e::class,
                'status' => $e instanceof RequestException ? $e->response->status() : null,
            ]);

            return [];
        }
    }

    /**
     * Render the field on detail/index pages.
     *
     * `prepareBeforeRender` intentionally skips loading associations on preview to
     * avoid an N+1 on form pages, so by the time a Select-derived field renders
     * its preview the value is empty. We fetch associations here and join the
     * matching option labels into a comma-separated string.
     *
     * Index pages do one HTTP call per visible row by default (acceptable for a 25-row
     * page). To collapse this into one bulk call, configure `->batchAttachEndpoint()` +
     * `->batchAttachMapper()` and call `->primeAssociations($allParentIds)` from a
     * list-page hook before render. Both `loadOptions()` and `loadCurrentAssociations()`
     * additionally memoize per URL in a static request-level cache, so duplicate field
     * instances across rows never hit the wire twice for identical queries.
     */
    #[Override]
    protected function resolvePreview(): string
    {
        if (! $this->parentResource instanceof AbstractRestResource || $this->attachEndpoint === '') {
            return '';
        }

        $parentItem = $this->getData();

        if (! $parentItem instanceof DataWrapperContract || $parentItem->getKey() === null) {
            return '';
        }

        $ids = $this->loadCurrentAssociations($parentItem);

        if ($ids === []) {
            return '';
        }

        $options = $this->loadOptions();

        $labels = [];
        foreach ($ids as $id) {
            $labels[] = e($options[$id] ?? $id);
        }

        return implode(', ', $labels);
    }

    /**
     * Don't include the relation data in the main save payload.
     * The sync happens in resolveAfterApply after the parent is saved.
     */
    #[Override]
    protected function resolveOnApply(): ?Closure
    {
        return static fn (mixed $item): mixed => $item;
    }

    /**
     * After the parent item is saved, sync the many-to-many relation via HTTP.
     */
    #[Override]
    protected function resolveAfterApply(mixed $data): mixed
    {
        $ids = $this->getRequestValue();

        if ($ids === false || $ids === null) {
            $ids = [];
        }

        if (is_string($ids)) {
            $ids = [$ids];
        }

        $parentItem = $data instanceof DataWrapperContract ? $data : null;

        if ($parentItem instanceof DataWrapperContract) {
            /** @var array<int, int|string> $normalized */
            $normalized = [];
            foreach ((array) $ids as $id) {
                if (is_int($id) || is_string($id)) {
                    $normalized[] = $id;
                }
            }
            if (! $this->syncRelation($normalized, $parentItem)) {
                throw ValidationException::withMessages([
                    $this->getColumn() => 'The record was saved, but this relation could not be saved. Retry saving the relation.',
                ]);
            }
        }

        return $data;
    }
}
