<?php

declare(strict_types=1);

namespace App\ApiAdmin\ServiceConfig;

/**
 * DTO for configurable API response key mapping.
 *
 * Each microservice can return data in its own format (Django REST, Express, Spring Boot, Laravel).
 * This DTO maps the response structure so ConfigurableApiCaster can extract data uniformly.
 */
final readonly class ResponseMapping
{
    /**
     * @param string $filterFormat How list filters are encoded in query strings.
     *                             "nested" → `?filter[status]=todo` (Laravel / JSON:API style, default).
     *                             "flat"   → `?status=todo` (task-manager style — filter keys become
     *                             top-level query params).
     * @param bool $supportsSorting When false, `sort=...` is not appended to the list request.
     *                              Useful for CQRS APIs that silently ignore sort params and
     *                              return unsorted data (hides the gap instead of making it
     *                              look like sorting is working).
     * @param string $paginationType Pagination model exposed by the list endpoint.
     *                               "length_aware" (default, Laravel/Django shape — total + per_page
     *                               + current_page + last_page), "cursor" (next-cursor only, no total;
     *                               used by log/feed-like APIs), "link_header" (RFC 5988 Link header
     *                               with rel=first/prev/next/last — GitHub/GitLab/Atlassian style;
     *                               body carries only items, navigation + total come from HTTP
     *                               headers), or "none" (single-shot list, no pagination UI).
     * @param string $nextCursorKey Dot-path inside the response envelope to the "next" cursor
     *                              string. Read only when paginationType="cursor". Defaults to
     *                              "cursor.next" (matches `{data:[...], cursor:{next:"..."}}`).
     *                              A null / missing / empty value signals "no more pages".
     * @param string $cursorQueryParam Query-string param name the upstream expects to receive the
     *                                 cursor on subsequent requests (default "cursor").
     * @param string $totalHeader Name of the HTTP response header that carries the total row count
     *                            when paginationType="link_header". Default "X-Total-Count" (GitHub).
     *                            GitLab uses "X-Total". Leave empty to skip total extraction —
     *                            paginator falls back to simple mode (no page count, next/prev only).
     * @param string $detailStrategy How to fetch a single item.
     *                               "endpoint" (default) → GET /resource/{id}.
     *                               "list"               → GET /resource?<key>={id}, take first match.
     *                               Use "list" for APIs that expose only list + write endpoints
     *                               (no per-id reads), e.g. tag/status catalogues edited in-line.
     * @param string $detailListKeyParam Query-param name the list endpoint accepts to narrow to
     *                                   one row when detailStrategy="list" (default equals primaryKey).
     * @param string $filterParamName Top-level query key under which list filters are grouped.
     *                                Default "filter" — works for most JSON:API / Laravel Query
     *                                Builder setups. Use "filters" for Laravel projects that use
     *                                the plural form (e.g. Spatie Query Builder default config
     *                                with `->parameterName('filters')`). Ignored when
     *                                filterFormat="flat" (flat spreads keys to top-level).
     * @param string $sortParamName Top-level query key for the sort directive (default "sort").
     *                              Override for APIs that expect "order_by" / "ordering" / similar.
     * @param string $searchParamName Top-level query key for the free-text search box (default
     *                                "search"). Override for APIs that expect "q" (common) or
     *                                "query" — otherwise the search box silently no-ops upstream.
     * @param string $detailDataKey Dot-path inside a single-item response envelope where the
     *                              item body lives. Leave empty (default) when list and detail
     *                              share the same wrapper (`GET /posts/1` → `{"data": {...}}`,
     *                              same key as the list). Set when single-item responses have a
     *                              deeper / different envelope than the list — e.g. Laravel
     *                              JsonResource wrapping the individual record under a custom
     *                              key (`{"resource": {"data": {...}}}` → `resource.data`).
     *                              Without this, extractItemData() only walks the shallow
     *                              data_key and falls back to the one-key-unwrap heuristic,
     *                              which misses double-nested shapes — the wrapper ends up
     *                              holding `{"data": {...}}` as the item, primary_key can't be
     *                              found, and the form renders empty.
     * @param string $perPageParamName Upstream request parameter for page size (e.g. page_size or size).
     * @param bool $zeroBasedPages Translate upstream zero-based page numbers to one-based UI/MCP pages.
     */
    public function __construct(
        public string $dataKey = 'data',
        public string $totalKey = 'total',
        public string $perPageKey = 'per_page',
        public string $currentPageKey = 'current_page',
        public string $lastPageKey = 'last_page',
        public string $fromKey = 'from',
        public string $toKey = 'to',
        public string $pathKey = 'path',
        public string $linksKey = 'links',
        public string $firstPageUrlKey = 'first_page_url',
        public string $lastPageUrlKey = 'last_page_url',
        public string $prevPageUrlKey = 'prev_page_url',
        public string $nextPageUrlKey = 'next_page_url',
        public string $primaryKey = 'id',
        public string $filterFormat = 'nested',
        public bool $supportsSorting = true,
        public string $paginationType = 'length_aware',
        public string $nextCursorKey = 'cursor.next',
        public string $cursorQueryParam = 'cursor',
        public string $totalHeader = 'X-Total-Count',
        public string $detailStrategy = 'endpoint',
        public string $detailListKeyParam = '',
        public string $filterParamName = 'filter',
        public string $sortParamName = 'sort',
        public string $searchParamName = 'search',
        public string $detailDataKey = '',
        public string $perPageParamName = 'per_page',
        public bool $zeroBasedPages = false,
    ) {}

    /**
     * Create from array (e.g. from config file).
     *
     * Accepts the loose "string keys, scalar-ish values" shape that the
     * Laravel Config repo and JSON-RPC payloads hand us. Every documented
     * key is re-typed via is_string / is_bool guards below, so the DTO
     * stays strictly typed regardless of noisy input.
     *
     * @param array<string, bool|string> $mapping
     */
    public static function fromArray(array $mapping): self
    {
        $str = static fn (string $k, string $default): string => is_string($mapping[$k] ?? null) ? $mapping[$k] : $default;

        return new self(
            dataKey: $str('data_key', 'data'),
            totalKey: $str('total_key', 'total'),
            perPageKey: $str('per_page_key', 'per_page'),
            currentPageKey: $str('current_page_key', 'current_page'),
            lastPageKey: $str('last_page_key', 'last_page'),
            fromKey: $str('from_key', 'from'),
            toKey: $str('to_key', 'to'),
            pathKey: $str('path_key', 'path'),
            linksKey: $str('links_key', 'links'),
            firstPageUrlKey: $str('first_page_url_key', 'first_page_url'),
            lastPageUrlKey: $str('last_page_url_key', 'last_page_url'),
            prevPageUrlKey: $str('prev_page_url_key', 'prev_page_url'),
            nextPageUrlKey: $str('next_page_url_key', 'next_page_url'),
            primaryKey: $str('primary_key', 'id'),
            filterFormat: in_array($str('filter_format', 'nested'), ['nested', 'flat'], true)
                ? $str('filter_format', 'nested')
                : 'nested',
            supportsSorting: is_bool($mapping['supports_sorting'] ?? null) ? $mapping['supports_sorting'] : true,
            paginationType: in_array($str('pagination_type', 'length_aware'), ['length_aware', 'cursor', 'link_header', 'none'], true)
                ? $str('pagination_type', 'length_aware')
                : 'length_aware',
            nextCursorKey: $str('next_cursor_key', '') !== '' ? $str('next_cursor_key', 'cursor.next') : 'cursor.next',
            cursorQueryParam: $str('cursor_query_param', '') !== '' ? $str('cursor_query_param', 'cursor') : 'cursor',
            totalHeader: $str('total_header', '') !== '' ? $str('total_header', 'X-Total-Count') : 'X-Total-Count',
            detailStrategy: in_array($str('detail_strategy', 'endpoint'), ['endpoint', 'list'], true)
                ? $str('detail_strategy', 'endpoint')
                : 'endpoint',
            detailListKeyParam: $str('detail_list_key_param', ''),
            filterParamName: $str('filter_param_name', '') !== '' ? $str('filter_param_name', 'filter') : 'filter',
            sortParamName: $str('sort_param_name', '') !== '' ? $str('sort_param_name', 'sort') : 'sort',
            searchParamName: $str('search_param_name', '') !== '' ? $str('search_param_name', 'search') : 'search',
            detailDataKey: $str('detail_data_key', ''),
            perPageParamName: $str('per_page_param_name', '') !== '' ? $str('per_page_param_name', 'per_page') : 'per_page',
            zeroBasedPages: is_bool($mapping['zero_based_pages'] ?? null) ? $mapping['zero_based_pages'] : false,
        );
    }

    /**
     * @return array{
     *     data_key: string,
     *     total_key: string,
     *     per_page_key: string,
     *     current_page_key: string,
     *     last_page_key: string,
     *     from_key: string,
     *     to_key: string,
     *     path_key: string,
     *     links_key: string,
     *     first_page_url_key: string,
     *     last_page_url_key: string,
     *     prev_page_url_key: string,
     *     next_page_url_key: string,
     *     primary_key: string,
     *     filter_format: string,
     *     supports_sorting: bool,
     *     pagination_type: string,
     *     next_cursor_key: string,
     *     cursor_query_param: string,
     *     total_header: string,
     *     detail_strategy: string,
     *     detail_list_key_param: string,
     *     filter_param_name: string,
     *     sort_param_name: string,
     *     search_param_name: string,
     *     detail_data_key: string,
     *     per_page_param_name: string,
     *     zero_based_pages: bool,
     * }
     */
    public function toArray(): array
    {
        return [
            'data_key' => $this->dataKey,
            'total_key' => $this->totalKey,
            'per_page_key' => $this->perPageKey,
            'current_page_key' => $this->currentPageKey,
            'last_page_key' => $this->lastPageKey,
            'from_key' => $this->fromKey,
            'to_key' => $this->toKey,
            'path_key' => $this->pathKey,
            'links_key' => $this->linksKey,
            'first_page_url_key' => $this->firstPageUrlKey,
            'last_page_url_key' => $this->lastPageUrlKey,
            'prev_page_url_key' => $this->prevPageUrlKey,
            'next_page_url_key' => $this->nextPageUrlKey,
            'primary_key' => $this->primaryKey,
            'filter_format' => $this->filterFormat,
            'supports_sorting' => $this->supportsSorting,
            'pagination_type' => $this->paginationType,
            'next_cursor_key' => $this->nextCursorKey,
            'cursor_query_param' => $this->cursorQueryParam,
            'total_header' => $this->totalHeader,
            'detail_strategy' => $this->detailStrategy,
            'detail_list_key_param' => $this->detailListKeyParam,
            'filter_param_name' => $this->filterParamName,
            'sort_param_name' => $this->sortParamName,
            'search_param_name' => $this->searchParamName,
            'detail_data_key' => $this->detailDataKey,
            'per_page_param_name' => $this->perPageParamName,
            'zero_based_pages' => $this->zeroBasedPages,
        ];
    }
}
