<?php

declare(strict_types=1);

namespace App\ApiAdmin\TypeCasts;

use App\ApiAdmin\ServiceConfig\ResponseMapping;
use ArrayIterator;
use GuzzleHttp\Psr7\Uri;
use Illuminate\Support\Facades\Log;
use MoonShine\Contracts\Core\Paginator\PaginatorContract;
use MoonShine\Contracts\Core\TypeCasts\DataCasterContract;
use MoonShine\Contracts\Core\TypeCasts\DataWrapperContract;
use MoonShine\Core\Paginator\Paginator;
use MoonShine\Core\TypeCasts\MixedDataWrapper;

/**
 * Configurable API response caster.
 *
 * Unlike the hardcoded JsonApiCaster from devkit, this caster uses ResponseMapping
 * to extract data from any API response format (Django REST, Express, Spring Boot, Laravel).
 *
 * @implements DataCasterContract<mixed>
 */
final readonly class ConfigurableApiCaster implements DataCasterContract
{
    public function __construct(
        private ResponseMapping $mapping,
        private int $perPage = 25,
        private int $currentPage = 1,
    ) {
        Log::debug('ConfigurableApiCaster: initialized', [
            'data_key' => $this->mapping->dataKey,
            'primary_key' => $this->mapping->primaryKey,
            'per_page' => $this->perPage,
        ]);
    }

    /**
     * Cast a single item (array/object) into MixedDataWrapper.
     */
    public function cast(mixed $data): DataWrapperContract
    {
        if ($data === null || $data === []) {
            Log::debug('ConfigurableApiCaster::cast — empty data, returning empty wrapper');

            return new MixedDataWrapper([]);
        }

        // If data is already a DataWrapperContract, return as-is
        if ($data instanceof DataWrapperContract) {
            Log::debug('ConfigurableApiCaster::cast — data already wrapped', [
                'key' => $data->getKey(),
            ]);

            return $data;
        }

        $rawKey = data_get($data, $this->mapping->primaryKey);
        $key = is_int($rawKey) || is_string($rawKey) ? $rawKey : null;

        Log::debug('ConfigurableApiCaster::cast', [
            'primary_key_field' => $this->mapping->primaryKey,
            'resolved_key' => $key,
            'data_type' => get_debug_type($data),
        ]);

        return new MixedDataWrapper($data, $key);
    }

    /**
     * Cast a paginated API response into PaginatorContract.
     *
     * Extracts items from the configurable data key, pagination metadata from configurable keys,
     * and builds a Paginator compatible with MoonShine's pagination system.
     */
    public function paginatorCast(mixed $data): ?PaginatorContract
    {
        if ($data instanceof PaginatorContract) {
            return $data;
        }

        if ($data === null) {
            Log::debug('ConfigurableApiCaster::paginatorCast — null data');

            return null;
        }

        // Only treat $data as a paginated payload when it actually looks like one
        // (associative array with a recognised data/total key). Otherwise — e.g. when
        // a detail page passes [$singleItem] — returning a Paginator with empty data
        // would clobber the table's items via IterableComponent::resolvePaginator().
        if (! $this->looksLikePaginatedPayload($data)) {
            return null;
        }

        $rawItems = data_get($data, $this->mapping->dataKey, []);
        $items = is_iterable($rawItems) ? $rawItems : [];
        $total = $this->toInt(data_get($data, $this->mapping->totalKey, 0), 0);
        $perPage = $this->toInt(data_get($data, $this->mapping->perPageKey, $this->perPage), $this->perPage);
        $rawCurrentPage = data_get($data, $this->mapping->currentPageKey);
        $currentPage = $rawCurrentPage === null
            ? $this->currentPage
            : max(1, $this->toInt($rawCurrentPage, 0) + (int) $this->mapping->zeroBasedPages);
        $rawLastPage = data_get($data, $this->mapping->lastPageKey);
        $fromRaw = data_get($data, $this->mapping->fromKey);
        $toRaw = data_get($data, $this->mapping->toKey);
        $path = $this->toString(data_get($data, $this->mapping->pathKey, '')) ?: \Illuminate\Pagination\Paginator::resolveCurrentPath();
        $links = data_get($data, $this->mapping->linksKey, []);
        $firstPageUrl = data_get($data, $this->mapping->firstPageUrlKey);
        $lastPageUrl = data_get($data, $this->mapping->lastPageUrlKey);
        $prevPageUrl = data_get($data, $this->mapping->prevPageUrlKey);
        $nextPageUrl = data_get($data, $this->mapping->nextPageUrlKey);
        $simple = $rawLastPage === null && (
            data_get($data, $this->mapping->nextPageUrlKey, false) !== false
            || data_get($data, $this->mapping->prevPageUrlKey, false) !== false
        );
        $lastPage = $simple ? null : max(1, $this->toInt($rawLastPage, 1));

        if ($simple) {
            // Upstream page size may ignore our request; follow its navigation without guessing a last page.
            $path = \Illuminate\Pagination\Paginator::resolveCurrentPath();
            $prevPageUrl = $this->localPageUrl($prevPageUrl, $path);
            $nextPageUrl = $this->localPageUrl($nextPageUrl, $path);
            Log::debug('Using upstream page navigation', ['current_page' => $currentPage]);
        } else {
            $pageUrl = fn (int $page): string => $this->pageUrl($page, $path);
            $prevPageUrl ??= $currentPage > 1 ? $pageUrl($currentPage - 1) : null;
            $nextPageUrl ??= $currentPage < $lastPage ? $pageUrl($currentPage + 1) : null;
        }

        Log::debug('ConfigurableApiCaster::paginatorCast', [
            'items_count' => is_countable($items) ? count($items) : 'uncountable',
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $currentPage,
            'last_page' => $lastPage,
        ]);

        // Build links array in the format Paginator expects: [{url, label, active}]
        $paginatorLinks = $lastPage === null ? [] : $this->buildPaginatorLinks($links, $currentPage, $lastPage, $path);

        // Paginator::__construct has vendor-wide generic PHPDoc quirks
        // (links declared iterable<string, mixed> on line 25, actually
        // array-key-keyed per line 99 in the same file; data/originalData
        // similarly narrow). Wrapping in iterator_to_array + array_values
        // materialises the shape into array<int|string, mixed> which the
        // vendor constructor accepts at runtime — same as what its own
        // internal PaginatorLinks::make() does to the collection.
        /** @var iterable<string, mixed> $linksIter */
        $linksIter = new ArrayIterator(array_combine(
            array_map(static fn (int $i): string => (string) $i, array_keys($paginatorLinks)),
            $paginatorLinks,
        ));
        /** @var iterable<int|string, mixed> $dataIter */
        $dataIter = is_array($items) ? array_values($items) : iterator_to_array($items);

        return new Paginator(
            path: $path,
            links: $linksIter,
            // Keep paginator payload raw and let table components cast row items.
            // This matches MoonShine devkit RestApi behavior.
            data: $dataIter,
            originalData: $dataIter,
            currentPage: $currentPage,
            from: $fromRaw !== null ? $this->toInt($fromRaw, 0) : null,
            to: $toRaw !== null ? $this->toInt($toRaw, 0) : null,
            perPage: $perPage,
            simple: $simple,
            total: $total,
            lastPage: $lastPage,
            firstPageUrl: is_string($firstPageUrl) ? $firstPageUrl : null,
            prevPageUrl: is_string($prevPageUrl) ? $prevPageUrl : null,
            lastPageUrl: is_string($lastPageUrl) ? $lastPageUrl : null,
            nextPageUrl: is_string($nextPageUrl) ? $nextPageUrl : null,
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

    private function localPageUrl(mixed $url, string $path): ?string
    {
        if (! is_string($url) || $url === '') {
            return null;
        }
        $query = parse_url($url, PHP_URL_QUERY);
        if (! is_string($query)) {
            return null;
        }
        parse_str($query, $params);
        $page = $params['page'] ?? null;

        return is_string($page) && ctype_digit($page) && (int) $page > 0
            ? $this->pageUrl((int) $page, $path)
            : null;
    }

    private function pageUrl(int $page, string $path): string
    {
        $uri = new Uri($path);
        parse_str($uri->getQuery(), $query);

        return (string) $uri->withQuery(http_build_query([...$query, ...request()->query(), 'page' => $page]));
    }

    private function toInt(mixed $value, int $default): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return $default;
    }

    private function toString(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /**
     * Detect whether the input looks like a paginated API response. A paginated
     * payload is an associative array (or stdClass) carrying at least one of the
     * mapped keys. A list of items (`[item, item, ...]`) is not paginated and
     * should be left untouched by the caster.
     */
    private function looksLikePaginatedPayload(mixed $data): bool
    {
        if (is_object($data)) {
            $data = (array) $data;
        }

        if (! is_array($data)) {
            return false;
        }

        if ($data === []) {
            return false;
        }

        // Numeric/list-style array of items — not a paginated payload.
        if (array_is_list($data)) {
            return false;
        }

        return array_key_exists($this->mapping->dataKey, $data)
            || array_key_exists($this->mapping->totalKey, $data)
            || array_key_exists($this->mapping->currentPageKey, $data)
            || array_key_exists($this->mapping->lastPageKey, $data);
    }

    /**
     * Build paginator links from API response or generate them.
     *
     * @param mixed $links Raw links from API response
     *
     * @return array<int, array{url: string|null, label: string, active: bool}>
     */
    private function buildPaginatorLinks(mixed $links, int $currentPage, int $lastPage, string $path): array
    {
        // If API provides links in the expected format, use them
        if (is_array($links) && $links !== [] && is_array($links[0] ?? null) && array_key_exists('url', $links[0])) {
            Log::debug('ConfigurableApiCaster: using API-provided links', [
                'count' => count($links),
            ]);

            /** @var array<int, array{url: string|null, label: string, active: bool}> $normalized */
            $normalized = [];
            foreach (array_values($links) as $i => $link) {
                if (! is_array($link)) {
                    continue;
                }
                $url = $link['url'] ?? null;
                $label = $link['label'] ?? (string) ($i + 1);
                $active = $link['active'] ?? false;
                $normalized[] = [
                    'url' => is_string($url) ? $url : null,
                    'label' => is_scalar($label) ? (string) $label : '',
                    'active' => (bool) $active,
                ];
            }

            return $normalized;
        }

        // Generate links from pagination metadata
        Log::debug('ConfigurableApiCaster: generating links', [
            'current_page' => $currentPage,
            'last_page' => $lastPage,
        ]);

        $result = [];

        for ($page = 1; $page <= $lastPage; $page++) {
            $result[] = [
                'url' => $this->pageUrl($page, $path),
                'label' => (string) $page,
                'active' => $page === $currentPage,
            ];
        }

        return $result;
    }
}
