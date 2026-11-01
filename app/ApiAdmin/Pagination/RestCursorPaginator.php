<?php

declare(strict_types=1);

namespace App\ApiAdmin\Pagination;

use Illuminate\Pagination\Cursor;
use Illuminate\Pagination\CursorPaginator;
use Override;

/**
 * CursorPaginator variant for REST APIs that return an opaque "next" token.
 *
 * Laravel's native CursorPaginator is designed for database cursor
 * pagination — it derives the next cursor from column values on the last
 * item. REST upstreams that expose cursor pagination usually hand us the
 * token directly (`{cursor: {next: "<opaque-string>"}}`), so we can't
 * regenerate it from row data. This subclass stashes the token provided
 * by the upstream and exposes it through the standard nextCursor() hook
 * that MoonShine's pagination UI already understands.
 *
 * @extends CursorPaginator<int, mixed>
 */
final class RestCursorPaginator extends CursorPaginator
{
    /**
     * @param iterable<int, mixed> $items
     * @param array<string, mixed> $options
     */
    public function __construct(
        iterable $items,
        int $perPage,
        private readonly ?string $nextCursorValue,
        private readonly string $cursorParam,
        ?Cursor $cursor = null,
        array $options = [],
    ) {
        parent::__construct($items, $perPage, $cursor, $options);
    }

    #[Override]
    public function nextCursor(): ?Cursor
    {
        if ($this->nextCursorValue === null || $this->nextCursorValue === '') {
            return null;
        }

        return new Cursor([$this->cursorParam => $this->nextCursorValue]);
    }

    #[Override]
    public function hasMorePages(): bool
    {
        return $this->nextCursorValue !== null && $this->nextCursorValue !== '';
    }
}
