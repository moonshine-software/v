<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Fixtures\MockApi;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Mock Blog API service for testing.
 *
 * Simulates a Laravel-style API with standard pagination format.
 * Covers: posts (CRUD), categories (BelongsTo), comments (HasMany), profile (HasOne).
 */
final class BlogApiService
{
    private const string BASE_URL = 'https://api.blog.test/v1';

    /**
     * Register fake HTTP responses for the Blog API.
     */
    public static function fake(): void
    {
        Http::fake([
            // Posts list (paginated, Laravel format)
            self::BASE_URL.'/posts*' => Http::sequence()
                ->push(self::postsListResponse())
                ->whenEmpty(Http::response(self::postsListResponse())),

            // Single post
            self::BASE_URL.'/posts/1' => Http::response(self::postResponse(1)),
            self::BASE_URL.'/posts/2' => Http::response(self::postResponse(2)),

            // Categories (for BelongsTo)
            self::BASE_URL.'/categories*' => Http::response(self::categoriesResponse()),

            // Comments (HasMany via nested endpoint)
            self::BASE_URL.'/posts/*/comments*' => Http::response(self::commentsResponse()),

            // Author profile (HasOne) — GET returns current author, POST/PUT/DELETE for CRUD.
            self::BASE_URL.'/posts/*/author*' => fn (Request $request) => match ($request->method()) {
                'POST', 'PUT' => Http::response(
                    ['data' => array_merge(self::authorItem(), $request->data())],
                    $request->method() === 'POST' ? 201 : 200,
                ),
                'DELETE' => Http::response('', 204),
                default => Http::response(self::authorResponse()),
            },

            // Tags (BelongsToMany)
            self::BASE_URL.'/tags*' => Http::response(self::tagsResponse()),
            self::BASE_URL.'/posts/*/tags' => Http::response(['success' => true]),

            // Create/Update/Delete
            self::BASE_URL.'/posts' => function (Request $request) {
                if ($request->method() === 'POST') {
                    return Http::response(self::postResponse(99, $request->data()), 201);
                }

                return Http::response(self::postsListResponse());
            },
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function postsListResponse(): array
    {
        return [
            'data' => [
                self::postItem(1),
                self::postItem(2),
                self::postItem(3),
            ],
            'current_page' => 1,
            'per_page' => 25,
            'total' => 3,
            'last_page' => 1,
            'from' => 1,
            'to' => 3,
            'path' => self::BASE_URL.'/posts',
            'links' => [],
            'first_page_url' => self::BASE_URL.'/posts?page=1',
            'last_page_url' => self::BASE_URL.'/posts?page=1',
            'prev_page_url' => null,
            'next_page_url' => null,
        ];
    }

    /**
     * @param array<int|string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public static function postResponse(int $id, array $overrides = []): array
    {
        /** @var array<string, mixed> $merged */
        $merged = array_merge(self::postItem($id), $overrides);

        return $merged;
    }

    /**
     * @return array<string, mixed>
     */
    public static function postItem(int $id): array
    {
        return [
            'id' => $id,
            'title' => "Post #{$id}: Test Article",
            'slug' => "test-article-{$id}",
            'body' => "This is the body content of post #{$id}.",
            'category_id' => 1,
            'status' => 'published',
            'created_at' => '2026-01-15T10:00:00Z',
            'updated_at' => '2026-01-16T14:30:00Z',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function categoriesResponse(): array
    {
        return [
            'data' => [
                ['id' => 1, 'name' => 'Technology', 'slug' => 'technology'],
                ['id' => 2, 'name' => 'Science', 'slug' => 'science'],
                ['id' => 3, 'name' => 'Design', 'slug' => 'design'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function commentsResponse(): array
    {
        return [
            'data' => [
                ['id' => 1, 'author_name' => 'Alice', 'body' => 'Great post!', 'created_at' => '2026-01-16T15:00:00Z'],
                ['id' => 2, 'author_name' => 'Bob', 'body' => 'Thanks for sharing.', 'created_at' => '2026-01-16T16:00:00Z'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function authorResponse(): array
    {
        return ['data' => self::authorItem()];
    }

    /**
     * @return array<string, mixed>
     */
    public static function authorItem(): array
    {
        return [
            'id' => 1,
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'bio' => 'Senior tech writer',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function tagsResponse(): array
    {
        return [
            'data' => [
                ['id' => 1, 'name' => 'PHP'],
                ['id' => 2, 'name' => 'Laravel'],
                ['id' => 3, 'name' => 'Docker'],
                ['id' => 4, 'name' => 'MoonShine'],
            ],
        ];
    }
}
