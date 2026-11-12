<?php

declare(strict_types=1);

namespace App\ApiAdmin\Fields;

use App\ApiAdmin\Fields\Concerns\HasEndpointQuery;
use App\ApiAdmin\Http\AsyncSearchContext;
use App\ApiAdmin\Http\PathSegment;
use App\ApiAdmin\Resources\AbstractRestResource;
use App\ApiAdmin\Tenancy\TenantRoutes;
use GuzzleHttp\Psr7\Uri;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use MoonShine\Contracts\Core\TypeCasts\DataWrapperContract;
use MoonShine\UI\Fields\Select;
use Override;
use Throwable;

/**
 * REST-based BelongsTo field — a Select with async search that loads options via HTTP from a related API endpoint.
 *
 * Uses MoonShine's TomSelect async search — options are fetched from a proxy controller
 * that calls the REST API and filters results server-side.
 *
 * Usage in resource fields():
 *   RestBelongsTo::make('Author', 'author_id')
 *       ->endpoint('/authors')
 *       ->valueKey('id')
 *       ->labelKey('name')
 */
class RestBelongsTo extends Select
{
    use HasEndpointQuery;

    protected string $apiEndpoint = '';

    protected string $valueKey = 'id';

    protected bool $hasExplicitValueKey = false;

    protected string $labelKey = 'name';

    protected ?AbstractRestResource $parentResource = null;

    /**
     * When the raw field value is an embedded relation object (Laravel
     * `->with('client')` shape: `{"client": {"id": 4, "name": "Acme"}}`
     * instead of the scalar FK `client_id`), we remember the embed here
     * so prepareBeforeRender() can seed the selected option label from
     * it without an extra HTTP round-trip.
     *
     * @var array<string, mixed>|null
     */
    protected ?array $embeddedRecord = null;

    protected ?string $fillFrom = null;

    /**
     * Override the dot-path used when extracting the value from a parent payload.
     *
     * Useful when the column is a scalar FK (`client_id`) but the API returns
     * only the embedded object (`{"client": {"id": 4, "name": "Acme"}}`) —
     * point `fill_from` at `client.id`. Without this, auto-detect strips the
     * trailing `_id` and looks at `{col_base}.{valueKey}` for the same shape.
     */
    public function fillFrom(?string $dotPath): static
    {
        $this->fillFrom = $dotPath;

        return $this;
    }

    public function getFillFrom(): ?string
    {
        return $this->fillFrom;
    }

    public function getApiEndpoint(): string
    {
        return $this->apiEndpoint;
    }

    public function getValueKey(): string
    {
        return $this->hasExplicitValueKey || $this->relatedResourceClass === null
            ? $this->valueKey
            : ($this->getRelationTarget()?->serviceConfig()->responseMapping->primaryKey ?? $this->valueKey);
    }

    public function getLabelKey(): string
    {
        return $this->labelKey;
    }

    public function getDataWrapper(): ?string
    {
        return $this->dataWrapper;
    }

    public function getParentResource(): ?AbstractRestResource
    {
        return $this->parentResource;
    }

    /**
     * Set the API endpoint for loading options (e.g. '/authors', '/categories').
     */
    public function endpoint(string $endpoint): static
    {
        $this->apiEndpoint = $endpoint;

        Log::debug('RestBelongsTo::endpoint', [
            'field' => $this->getColumn(),
            'endpoint' => $endpoint,
        ]);

        return $this;
    }

    /**
     * Set the key used as the option value (default: 'id').
     */
    public function valueKey(string $key): static
    {
        $this->valueKey = $key;
        $this->hasExplicitValueKey = true;

        return $this;
    }

    /**
     * Set the key used as the option label (default: 'name').
     */
    public function labelKey(string $key): static
    {
        $this->labelKey = $key;

        return $this;
    }

    /**
     * Set the wrapper key for extracting data from the API response (e.g. 'data', 'results').
     * If null, uses the parent resource's ResponseMapping dataKey.
     */
    public function dataWrapper(?string $wrapper): static
    {
        $this->dataWrapper = $wrapper;

        return $this;
    }

    /**
     * Set the parent resource to use its HTTP client for API calls.
     */
    public function resource(AbstractRestResource $resource): static
    {
        $this->parentResource = $resource;

        Log::debug('RestBelongsTo::resource', [
            'field' => $this->getColumn(),
            'resource' => $resource::class,
        ]);

        return $this;
    }

    /**
     * Build the async search URL for this field.
     * Points to the proxy controller that fetches from the REST API and filters server-side.
     */
    protected function buildAsyncSearchUrl(): ?string
    {
        if (! $this->parentResource instanceof AbstractRestResource || $this->apiEndpoint === '') {
            return null;
        }

        $target = $this->resolveRelationTarget($this->dataWrapper);
        if (! $target instanceof RestRelationTarget) {
            return null;
        }

        $data = $this->getData();
        $endpoint = $this->resolveRelationEndpoint(
            $this->apiEndpoint,
            [],
            $this->relationEndpointContextFromItem($data instanceof DataWrapperContract ? $data : null),
        );
        if ($endpoint === null) {
            return null;
        }

        // Keep the URL small and avoid copying unrelated form values into it.
        preg_match_all('/\{([a-zA-Z_]\w*)\}/', $this->apiEndpoint, $matches);
        $keys = $matches[1];
        foreach ($this->getEndpointQuery() as $value) {
            if (is_string($value) && preg_match('/^\{([a-zA-Z_]\w*)\}$/', $value, $match) === 1) {
                $keys[] = $match[1];
            }
        }
        $context = array_intersect_key($this->resolveEndpointQueryContext(), array_flip($keys));
        $routeParams = [
            ...TenantRoutes::routeParams(),
            '_resource' => $this->parentResource->getUriKey(),
            '_relation' => $this->getColumn(),
            '_field_scope' => $this->getWrapName() === $this->parentResource->getQueryParamName('filter') ? 'filter' : 'form',
            '_parent_id' => $this->parentResource->getItemID(),
            '_context' => AsyncSearchContext::encode($this, $context),
        ];

        return route('api-admin.async-search', $routeParams);
    }

    /** @param array<string, mixed> $context */
    public function resolveSearchEndpoint(array $context): ?string
    {
        // Supply every placeholder explicitly: the async request must never
        // supplement a server-issued context with arbitrary query parameters.
        preg_match_all('/\{([a-zA-Z_]\w*)\}/', $this->apiEndpoint, $matches);
        $values = [];
        foreach ($matches[1] as $name) {
            $value = $context[$name] ?? null;
            $values[$name] = is_scalar($value) ? $value : null;
        }

        return $this->resolveRelationEndpoint($this->apiEndpoint, $values, $context);
    }

    /**
     * Load options from the API endpoint.
     *
     * @return array<array-key, string> Key-value pairs for select options. Keys come from
     *                                  valueKey (typically `id`) — PHP coerces numeric
     *                                  strings to int, so the index type is left open.
     */
    public function loadOptions(): array
    {
        if (! $this->parentResource instanceof AbstractRestResource) {
            Log::warning('RestBelongsTo::loadOptions — no parent resource set', [
                'field' => $this->getColumn(),
            ]);

            return [];
        }

        if ($this->apiEndpoint === '') {
            Log::warning('RestBelongsTo::loadOptions — no endpoint set', [
                'field' => $this->getColumn(),
            ]);

            return [];
        }

        $target = $this->resolveRelationTarget($this->dataWrapper);
        if (! $target instanceof RestRelationTarget) {
            return [];
        }

        $data = $this->getData();
        $endpoint = $this->resolveRelationEndpoint(
            $this->apiEndpoint,
            [],
            $this->relationEndpointContextFromItem($data instanceof DataWrapperContract ? $data : null),
        );
        if ($endpoint === null) {
            return [];
        }

        Log::debug('RestBelongsTo::loadOptions', [
            'field' => $this->getColumn(),
            'source_service' => $target->sourceService(),
            'target_service' => $target->targetService(),
            'endpoint' => $endpoint,
            'value_key' => $this->getValueKey(),
            'label_key' => $this->labelKey,
            'query_keys' => array_keys($this->resolveEndpointQuery()),
        ]);

        try {
            $response = $target->httpClient()->get($endpoint, $this->resolveEndpointQuery());
            $response->throw();

            $json = $response->json();

            $items = data_get($json, $target->dataWrapper(), $json);

            if (! is_array($items)) {
                $items = [];
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

            Log::debug('RestBelongsTo::loadOptions — loaded', [
                'field' => $this->getColumn(),
                'options_count' => count($options),
            ]);

            return $options;
        } catch (Throwable $e) {
            Log::error('RestBelongsTo::loadOptions — failed', [
                'field' => $this->getColumn(),
                'exception' => $e::class,
                'status' => $e instanceof RequestException ? $e->response->status() : null,
            ]);

            return [];
        }
    }

    /**
     * Load a single option by value for displaying the currently selected item on edit forms.
     *
     * @return array<string, string>
     */
    protected function loadSelectedOption(mixed $currentValue): array
    {
        if ($currentValue === null || $currentValue === '' || ! is_scalar($currentValue) || ! $this->parentResource instanceof AbstractRestResource) {
            return [];
        }

        $key = (string) $currentValue;
        $target = $this->resolveRelationTarget($this->dataWrapper);
        $data = $this->getData();
        $endpoint = $this->resolveRelationEndpoint(
            $this->apiEndpoint,
            [],
            $this->relationEndpointContextFromItem($data instanceof DataWrapperContract ? $data : null),
        );
        if (! $target instanceof RestRelationTarget || $endpoint === null) {
            return [$key => $key];
        }

        try {
            $mapping = $target->serviceConfig()->responseMapping;
            $query = $this->resolveEndpointQuery();
            if ($mapping->detailStrategy === 'list') {
                $query += [$mapping->detailListKeyParam ?: $this->getValueKey() => $key];
            } else {
                $uri = new Uri($endpoint);
                $endpoint = (string) $uri->withPath(rtrim($uri->getPath(), '/').'/'.PathSegment::encode($key));
            }
            $response = $target->httpClient()->get($endpoint, $query);
            if ($response->status() !== 404) {
                $response->throw();
                $json = $response->json();
                $wrapper = $mapping->detailStrategy === 'list'
                    ? $target->dataWrapper()
                    : ($mapping->detailDataKey ?: $target->dataWrapper());
                $items = data_get($json, $wrapper, $json);
                $items = is_array($items) ? (array_is_list($items) ? $items : [$items]) : [];
                foreach ($items as $item) {
                    $id = data_get($item, $this->getValueKey());
                    $label = data_get($item, $this->labelKey);
                    if (is_scalar($id) && (string) $id === $key && is_scalar($label)) {
                        return [$key => (string) $label];
                    }
                }

                return [$key => $key];
            }
        } catch (Throwable $e) {
            Log::warning('RestBelongsTo: selected option lookup failed', [
                'field' => $this->getColumn(),
                'exception' => $e::class,
                'status' => $e instanceof RequestException ? $e->response->status() : null,
            ]);

            return [$key => $key];
        }

        $allOptions = $this->loadOptions();

        return [$key => $allOptions[$key] ?? $key];
    }

    /**
     * Fast-path: when the parent response already embedded the relation
     * object, we know both the key AND the label locally — no HTTP round
     * trip needed. Skips the full options list, which on large tables
     * (users, tags) would be expensive.
     *
     * @return array<string, string>
     */
    protected function selectedOptionFromEmbed(mixed $currentValue): array
    {
        $key = is_scalar($currentValue) ? (string) $currentValue : '';
        $embeddedLabel = $this->embeddedRecord !== null
            ? \data_get($this->embeddedRecord, $this->labelKey)
            : null;
        $label = is_scalar($embeddedLabel)
            ? (string) $embeddedLabel
            : $key;

        return [$key => $label];
    }

    /**
     * Unwrap embedded-relation objects to the scalar FK value.
     *
     * Laravel APIs with `->load('client')` return
     * `{"client": {"id": 4, "name": "Acme"}}` instead of the scalar
     * `client_id`. When the Resource column is `client` (matching the
     * shape of the response) the stored value is the whole object —
     * Select would render it as "[object Object]" and save would POST
     * the whole array back. We detect this case once, remember the
     * embed for label rendering, and expose only the scalar `valueKey`
     * (typically `id`) as the "value" to the rest of MoonShine.
     *
     * For the shape-native case (column is `client_id` with a scalar
     * value) this is a no-op.
     */
    #[Override]
    public function toValue(bool $withDefault = true): mixed
    {
        $raw = parent::toValue($withDefault);

        if (is_array($raw) && ! array_is_list($raw) && isset($raw[$this->getValueKey()])) {
            /** @var array<string, mixed> $rawAssoc */
            $rawAssoc = $raw;
            $this->embeddedRecord = $rawAssoc;

            return $rawAssoc[$this->getValueKey()];
        }

        if (($raw === null || $raw === '') && ($unwrapped = $this->unwrapEmbeddedFromData()) !== null) {
            return $unwrapped;
        }

        return $raw;
    }

    /**
     * Laravel APIs with `->load('client')` return `{"client": {"id":4}}`
     * with no scalar `client_id` sibling. When the column IS `client_id`,
     * parent::toValue sees null; we fall back to `fill_from` override or
     * strip the trailing `_id` and read `{col_base}.{valueKey}` from the
     * same payload. Stashes the object into embeddedRecord so preview /
     * prepareBeforeRender skip the redundant HTTP round-trip.
     */
    private function unwrapEmbeddedFromData(): string|int|null
    {
        $data = $this->getData();
        if (! $data instanceof DataWrapperContract) {
            return null;
        }

        $original = $data->getOriginal();
        if (! is_array($original)) {
            return null;
        }

        $path = $this->fillFrom;
        if ($path === null) {
            $column = $this->getColumn();
            if (! str_ends_with($column, '_id')) {
                return null;
            }
            $path = substr($column, 0, -3);
        }

        $object = data_get($original, $path);
        if (is_array($object) && isset($object[$this->getValueKey()])) {
            /** @var array<string, mixed> $objectAssoc */
            $objectAssoc = $object;
            $this->embeddedRecord = $objectAssoc;

            $value = $objectAssoc[$this->getValueKey()];
            if (is_scalar($value)) {
                return is_int($value) ? $value : (string) $value;
            }

            return null;
        }

        if ($this->fillFrom !== null && is_scalar($object)) {
            return is_int($object) ? $object : (string) $object;
        }

        return null;
    }

    /**
     * Prepare the field for rendering — sets up async search via proxy controller.
     */
    #[Override]
    protected function prepareBeforeRender(): void
    {
        parent::prepareBeforeRender();

        if (! $this->parentResource instanceof AbstractRestResource) {
            return;
        }

        $asyncUrl = $this->buildAsyncSearchUrl();

        if ($asyncUrl !== null) {
            $this->searchable();
            $this->async($asyncUrl);

            // Load the currently selected option so TomSelect displays it.
            // `toValue()` above unwraps any embedded-relation object; we
            // use the stashed embed (if present) to skip the extra HTTP
            // round-trip that loadSelectedOption would trigger.
            $currentValue = $this->toValue(withDefault: false);

            if ($currentValue !== null && $currentValue !== '') {
                $option = $this->embeddedRecord !== null
                    ? $this->selectedOptionFromEmbed($currentValue)
                    : $this->loadSelectedOption($currentValue);

                $this->options($option);
            }

            // Preload options when dropdown opens
            $this->customAttributes([
                'data-async-on-init' => true,
                'data-async-on-init-dropdown' => true,
            ]);
        } elseif ($this->getValues()->getValues()->isEmpty()) {
            $this->searchable();
            $this->options($this->loadOptions());
        }
    }

    /**
     * Cell preview on Index / Detail pages.
     *
     * When the raw value is an embedded relation object (toValue() already
     * stashed it into `embeddedRecord`), pull the label directly from the
     * embed — far cheaper than loadOptions() per row and gives the user
     * the name they expect instead of a numeric FK.
     *
     * Otherwise delegate to the parent Select preview, which looks up the
     * label in `$this->getValues()` (loaded options). That path works
     * correctly for scalar FKs when the options collection has been
     * populated (form pages); on index rows it returns empty unless the
     * caller formatted the value explicitly. We do NOT loadOptions() here
     * — it would fire an HTTP call per row on the index table.
     */
    #[Override]
    protected function resolvePreview(): string
    {
        // Force toValue() to run so embeddedRecord is captured if applicable.
        $this->toValue(withDefault: false);

        if ($this->embeddedRecord !== null) {
            $label = \data_get($this->embeddedRecord, $this->labelKey);
            if (\is_scalar($label) && $label !== '') {
                return e((string) $label);
            }
        }

        return e(parent::resolvePreview());
    }
}
