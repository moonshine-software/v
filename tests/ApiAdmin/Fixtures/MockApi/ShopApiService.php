<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Fixtures\MockApi;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Mock Shop API service for testing.
 *
 * Simulates a Django REST-style API with different response format:
 * - "results" instead of "data"
 * - "count" instead of "total"
 * - "page_size" instead of "per_page"
 *
 * Covers: products (CRUD), brands (BelongsTo), variants (HasMany), specs (HasOne).
 */
final class ShopApiService
{
    private const string BASE_URL = 'https://api.shop.test/api';

    /**
     * Register fake HTTP responses for the Shop API.
     */
    public static function fake(): void
    {
        Http::fake([
            // Products list (paginated, Django REST format)
            self::BASE_URL.'/products*' => Http::sequence()
                ->push(self::productsListResponse())
                ->whenEmpty(Http::response(self::productsListResponse())),

            // Single product
            self::BASE_URL.'/products/1' => Http::response(self::productResponse(1)),
            self::BASE_URL.'/products/2' => Http::response(self::productResponse(2)),

            // Brands (for BelongsTo)
            self::BASE_URL.'/brands*' => Http::response(self::brandsResponse()),

            // Product variants (HasMany)
            self::BASE_URL.'/products/*/variants*' => Http::response(self::variantsResponse()),

            // Product specs (HasOne)
            self::BASE_URL.'/products/*/specs*' => Http::response(self::specsResponse()),

            // Categories (BelongsToMany)
            self::BASE_URL.'/categories*' => Http::response(self::shopCategoriesResponse()),
            self::BASE_URL.'/products/*/categories' => Http::response(['success' => true]),

            // Create/Update/Delete
            self::BASE_URL.'/products' => function (Request $request) {
                if ($request->method() === 'POST') {
                    return Http::response(self::productResponse(99, $request->data()), 201);
                }

                return Http::response(self::productsListResponse());
            },

            // 422 validation error
            self::BASE_URL.'/products/invalid' => Http::response([
                'detail' => 'Validation Error',
                'errors' => [
                    ['field' => 'name', 'message' => 'This field is required.'],
                    ['field' => 'price', 'message' => 'Ensure this value is greater than 0.'],
                ],
            ], 422),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function productsListResponse(): array
    {
        return [
            'results' => [
                self::productItem(1),
                self::productItem(2),
            ],
            'count' => 2,
            'page_size' => 20,
            'current_page' => 1,
            'last_page' => 1,
            'from' => 1,
            'to' => 2,
            'path' => self::BASE_URL.'/products',
        ];
    }

    /**
     * @param array<int|string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public static function productResponse(int $id, array $overrides = []): array
    {
        /** @var array<string, mixed> $merged */
        $merged = array_merge(self::productItem($id), $overrides);

        return $merged;
    }

    /**
     * @return array<string, mixed>
     */
    public static function productItem(int $id): array
    {
        return [
            'id' => $id,
            'name' => "Product #{$id}",
            'sku' => "SKU-{$id}-001",
            'price' => 29.99 * $id,
            'brand_id' => 1,
            'in_stock' => true,
            'description' => "Description for product #{$id}",
            'created_at' => '2026-02-01T08:00:00Z',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function brandsResponse(): array
    {
        return [
            'results' => [
                ['id' => 1, 'name' => 'Acme Corp'],
                ['id' => 2, 'name' => 'Globex Inc'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function variantsResponse(): array
    {
        return [
            'results' => [
                ['id' => 1, 'size' => 'S', 'color' => 'Red', 'stock' => 10, 'price_modifier' => 0],
                ['id' => 2, 'size' => 'M', 'color' => 'Blue', 'stock' => 25, 'price_modifier' => 2.00],
                ['id' => 3, 'size' => 'L', 'color' => 'Green', 'stock' => 5, 'price_modifier' => 4.00],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function specsResponse(): array
    {
        return [
            'results' => [
                'weight' => '250g',
                'dimensions' => '10x5x3cm',
                'material' => 'Cotton',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function shopCategoriesResponse(): array
    {
        return [
            'results' => [
                ['id' => 1, 'name' => 'Clothing'],
                ['id' => 2, 'name' => 'Electronics'],
                ['id' => 3, 'name' => 'Home & Garden'],
            ],
        ];
    }
}
