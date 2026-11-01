<?php

declare(strict_types=1);

namespace App\ApiAdmin\MCP;

use App\ApiAdmin\ServiceConfig\ResponseMapping;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use MoonShine\Contracts\Core\Paginator\PaginatorContract;
use MoonShine\Contracts\Core\TypeCasts\DataWrapperContract;

/**
 * Normalise `findItem()` / `getItems()` output into JSON-RPC-friendly shapes.
 *
 * Single items go through `serialize()` — prefer `getOriginal()` (the raw
 * upstream payload, preserves nested relations) and fall back to `toArray()`
 * when the wrapper holds a non-array shape. Always ensures the configured
 * `primary_key` is present at the top level so the agent can refer to
 * `item.{primaryKey}` uniformly.
 *
 * List results pass through `serializePaginator()` which folds the three
 * possible variants MoonShine can hand back (PaginatorContract, Laravel
 * Paginator/CursorPaginator, Collection) into `{items, pagination}`.
 */
final class ItemSerializer
{
    /**
     * @return array<string, mixed>
     */
    public static function serialize(DataWrapperContract $wrapper, ResponseMapping $mapping): array
    {
        $original = $wrapper->getOriginal();

        if (is_array($original)) {
            /** @var array<string, mixed> $out */
            $out = $original;
        } else {
            /** @var array<string, mixed> $viaArray */
            $viaArray = $wrapper->toArray();
            $out = $viaArray;
        }

        $primary = $mapping->primaryKey !== '' ? $mapping->primaryKey : 'id';
        if (! array_key_exists($primary, $out)) {
            $key = $wrapper->getKey();
            if (is_scalar($key)) {
                $out[$primary] = $key;
            }
        }

        return $out;
    }

    /**
     * @param mixed $result PaginatorContract | Illuminate\Contracts\Pagination\Paginator | Collection | iterable
     *
     * @return array{
     *     items: list<array<string, mixed>>,
     *     pagination: array<string, mixed>,
     * }
     */
    public static function serializePaginator(mixed $result, ResponseMapping $mapping): array
    {
        if ($result instanceof PaginatorContract) {
            return self::fromMoonShinePaginator($result, $mapping);
        }

        if ($result instanceof Paginator
            || $result instanceof CursorPaginator) {
            return self::fromLaravelPaginator($result, $mapping);
        }

        if ($result instanceof Collection || $result instanceof LazyCollection) {
            return self::fromPlainCollection($result, $mapping);
        }

        if (is_iterable($result)) {
            $materialised = is_array($result) ? $result : iterator_to_array($result, preserve_keys: false);

            return self::fromPlainCollection(collect($materialised), $mapping);
        }

        return [
            'items' => [],
            'pagination' => ['strategy' => 'none', 'per_page' => 0, 'has_more' => false],
        ];
    }

    /**
     * @return array{items: list<array<string, mixed>>, pagination: array<string, mixed>}
     */
    private static function fromMoonShinePaginator(PaginatorContract $paginator, ResponseMapping $mapping): array
    {
        $items = [];
        foreach ($paginator->getOriginalData() as $row) {
            if ($row instanceof DataWrapperContract) {
                $items[] = self::serialize($row, $mapping);
            } elseif (is_array($row)) {
                /** @var array<string, mixed> $row */
                $items[] = $row;
            }
        }

        $strategy = $mapping->paginationType === 'cursor' ? 'cursor' : ($mapping->paginationType === 'link_header' ? 'link_header' : 'length_aware');

        $pagination = [
            'strategy' => $strategy,
            'current_page' => $paginator->getCurrentPage(),
            'per_page' => $paginator->getPerPage(),
            'total' => $paginator->getTotal(),
            'last_page' => $paginator->getLastPage(),
            'has_more' => $paginator->getNextPageUrl() !== null,
            'next_page_url' => $paginator->getNextPageUrl(),
            'prev_page_url' => $paginator->getPrevPageUrl(),
        ];

        if ($strategy === 'cursor') {
            $nextUrl = $paginator->getNextPageUrl();
            $pagination['next_cursor'] = $nextUrl !== null
                ? self::extractCursorFromUrl($nextUrl, $mapping->cursorQueryParam)
                : null;
        }

        return ['items' => $items, 'pagination' => $pagination];
    }

    /**
     * @param Paginator<array-key, mixed>|CursorPaginator<array-key, mixed> $paginator
     *
     * @return array{items: list<array<string, mixed>>, pagination: array<string, mixed>}
     */
    private static function fromLaravelPaginator(Paginator|CursorPaginator $paginator, ResponseMapping $mapping): array
    {
        $items = [];
        foreach ($paginator->items() as $row) {
            if ($row instanceof DataWrapperContract) {
                $items[] = self::serialize($row, $mapping);
            } elseif (is_array($row)) {
                /** @var array<string, mixed> $row */
                $items[] = $row;
            }
        }

        if ($paginator instanceof CursorPaginator) {
            /** @var Cursor|null $nextCursor */
            $nextCursor = $paginator->nextCursor();

            return [
                'items' => $items,
                'pagination' => [
                    'strategy' => 'cursor',
                    'per_page' => $paginator->perPage(),
                    'has_more' => $paginator->hasMorePages(),
                    'next_cursor' => $nextCursor?->encode(),
                    'next_page_url' => $paginator->nextPageUrl(),
                ],
            ];
        }

        $pagination = [
            'strategy' => 'length_aware',
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'has_more' => $paginator->hasMorePages(),
            'next_page_url' => $paginator->nextPageUrl(),
        ];

        if ($paginator instanceof LengthAwarePaginator) {
            $pagination['total'] = $paginator->total();
            $pagination['last_page'] = $paginator->lastPage();
        }

        return ['items' => $items, 'pagination' => $pagination];
    }

    /**
     * @param Collection<array-key, mixed>|LazyCollection<array-key, mixed> $collection
     *
     * @return array{items: list<array<string, mixed>>, pagination: array<string, mixed>}
     */
    private static function fromPlainCollection(Collection|LazyCollection $collection, ResponseMapping $mapping): array
    {
        $items = [];
        foreach ($collection as $row) {
            if ($row instanceof DataWrapperContract) {
                $items[] = self::serialize($row, $mapping);
            } elseif (is_array($row)) {
                /** @var array<string, mixed> $row */
                $items[] = $row;
            }
        }

        return [
            'items' => $items,
            'pagination' => [
                'strategy' => 'none',
                'per_page' => count($items),
                'total' => count($items),
                'has_more' => false,
            ],
        ];
    }

    private static function extractCursorFromUrl(string $url, string $param): ?string
    {
        $query = parse_url($url, PHP_URL_QUERY);
        if (! is_string($query) || $query === '') {
            return null;
        }

        parse_str($query, $parsed);
        $value = $parsed[$param] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
