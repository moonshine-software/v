<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Unit;

use App\ApiAdmin\ServiceConfig\ResponseMapping;
use App\ApiAdmin\TypeCasts\ConfigurableApiCaster;
use MoonShine\Contracts\Core\Paginator\PaginatorContract;
use MoonShine\Core\TypeCasts\MixedDataWrapper;
use Orchestra\Testbench\TestCase;

class ConfigurableApiCasterTest extends TestCase
{
    public function test_cast_wraps_array_into_mixed_data_wrapper(): void
    {
        $caster = new ConfigurableApiCaster(new ResponseMapping);

        $result = $caster->cast(['id' => 1, 'name' => 'Test']);

        $this->assertInstanceOf(MixedDataWrapper::class, $result);
        $this->assertEquals(1, $result->getKey());
    }

    public function test_cast_with_custom_primary_key(): void
    {
        $mapping = new ResponseMapping(primaryKey: 'uuid');
        $caster = new ConfigurableApiCaster($mapping);

        $result = $caster->cast(['uuid' => 'abc-123', 'name' => 'Test']);

        $this->assertEquals('abc-123', $result->getKey());
    }

    public function test_cast_returns_empty_wrapper_for_null(): void
    {
        $caster = new ConfigurableApiCaster(new ResponseMapping);

        $result = $caster->cast(null);

        $this->assertInstanceOf(MixedDataWrapper::class, $result);
        $this->assertNull($result->getKey());
    }

    public function test_cast_returns_same_wrapper_if_already_wrapped(): void
    {
        $caster = new ConfigurableApiCaster(new ResponseMapping);
        $wrapper = new MixedDataWrapper(['id' => 5], 5);

        $result = $caster->cast($wrapper);

        $this->assertSame($wrapper, $result);
    }

    public function test_paginator_cast_with_laravel_format(): void
    {
        $caster = new ConfigurableApiCaster(new ResponseMapping);

        $data = [
            'data' => [
                ['id' => 1, 'name' => 'Item 1'],
                ['id' => 2, 'name' => 'Item 2'],
            ],
            'total' => 10,
            'per_page' => 2,
            'current_page' => 1,
            'last_page' => 5,
            'from' => 1,
            'to' => 2,
            'path' => '/api/items',
        ];

        $result = $caster->paginatorCast($data);

        $this->assertInstanceOf(PaginatorContract::class, $result);
        $this->assertEquals(10, $result->getTotal());
        $this->assertEquals(1, $result->getCurrentPage());
        $this->assertEquals(5, $result->getLastPage());
    }

    public function test_paginator_cast_with_django_format(): void
    {
        $mapping = new ResponseMapping(
            dataKey: 'results',
            totalKey: 'count',
            perPageKey: 'page_size',
        );
        $caster = new ConfigurableApiCaster($mapping);

        $data = [
            'results' => [
                ['id' => 1, 'name' => 'Product 1'],
            ],
            'count' => 50,
            'page_size' => 20,
            'current_page' => 1,
            'last_page' => 3,
            'path' => '/api/products',
        ];

        $result = $caster->paginatorCast($data);

        $this->assertInstanceOf(PaginatorContract::class, $result);
        $this->assertEquals(50, $result->getTotal());
    }

    public function test_paginator_cast_returns_null_for_null(): void
    {
        $caster = new ConfigurableApiCaster(new ResponseMapping);

        $this->assertNull($caster->paginatorCast(null));
    }

    /**
     * Regression: detail pages call TableBuilder->items([$singleItem]) which goes
     * through IterableComponent::resolvePaginator → caster->paginatorCast($items).
     * If the caster always returned a Paginator (with empty `data` because the
     * input is not a paginated payload), it would clobber the table's items and
     * the detail page would render no rows.
     */
    public function test_paginator_cast_returns_null_for_list_of_items(): void
    {
        $caster = new ConfigurableApiCaster(new ResponseMapping);

        $items = [
            new MixedDataWrapper(['id' => 1, 'title' => 'Post 1'], 1),
        ];

        $this->assertNull(
            $caster->paginatorCast($items),
            'List-style array of items must not be cast into a Paginator',
        );
    }

    public function test_paginator_cast_returns_null_for_assoc_without_pagination_keys(): void
    {
        $caster = new ConfigurableApiCaster(new ResponseMapping);

        $this->assertNull(
            $caster->paginatorCast(['id' => 1, 'title' => 'Single']),
            'A plain associative item without data/total keys is not a paginated payload',
        );
    }
}
