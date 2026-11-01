<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Integration;

use App\ApiAdmin\Fields\RestHasMany;
use App\ApiAdmin\Fields\RestHasOne;
use App\ApiAdmin\Providers\ApiAdminServiceProvider;
use MoonShine\Contracts\UI\RelationFieldContract;
use MoonShine\Laravel\Providers\MoonShineServiceProvider;
use Orchestra\Testbench\TestCase;

/**
 * Ensures RestHasOne and RestHasMany are recognized as relation fields
 * so MoonShine's outside-filtering (`Fields::onlyOutside()`) picks them up
 * and renders them after the main form.
 */
class RestRelationContractTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            MoonShineServiceProvider::class,
            ApiAdminServiceProvider::class,
        ];
    }

    public function test_rest_has_one_implements_relation_field_contract(): void
    {
        $this->assertInstanceOf(RelationFieldContract::class, RestHasOne::make('Author', 'author'));
    }

    public function test_rest_has_many_implements_relation_field_contract(): void
    {
        $this->assertInstanceOf(RelationFieldContract::class, RestHasMany::make('Comments', 'comments'));
    }

    public function test_rest_has_one_is_outside_component(): void
    {
        $this->assertTrue(RestHasOne::make('Author', 'author')->isOutsideComponent());
    }

    public function test_rest_has_many_is_outside_component(): void
    {
        $this->assertTrue(RestHasMany::make('Comments', 'comments')->isOutsideComponent());
    }

    public function test_rest_has_one_is_to_one(): void
    {
        $this->assertTrue(RestHasOne::make('Author', 'author')->isToOne());
    }

    public function test_rest_has_many_is_not_to_one(): void
    {
        $this->assertFalse(RestHasMany::make('Comments', 'comments')->isToOne());
    }

    public function test_rest_has_one_disable_outside(): void
    {
        $this->assertFalse(RestHasOne::make('Author', 'author')->disableOutside()->isOutsideComponent());
    }

    public function test_rest_has_many_disable_outside(): void
    {
        $this->assertFalse(RestHasMany::make('Comments', 'comments')->disableOutside()->isOutsideComponent());
    }

    public function test_rest_has_one_is_not_morph(): void
    {
        $this->assertFalse(RestHasOne::make('Author', 'author')->isMorph());
    }

    public function test_rest_has_many_is_not_morph(): void
    {
        $this->assertFalse(RestHasMany::make('Comments', 'comments')->isMorph());
    }

    public function test_rest_has_one_get_related_returns_null(): void
    {
        $this->assertNull(RestHasOne::make('Author', 'author')->getRelated());
    }

    public function test_rest_has_many_get_related_returns_null(): void
    {
        $this->assertNull(RestHasMany::make('Comments', 'comments')->getRelated());
    }

    public function test_rest_has_one_make_related_returns_null(): void
    {
        $this->assertNull(RestHasOne::make('Author', 'author')->makeRelated());
    }

    public function test_rest_has_many_make_related_returns_null(): void
    {
        $this->assertNull(RestHasMany::make('Comments', 'comments')->makeRelated());
    }

    public function test_rest_has_one_exclude_instancing_is_noop(): void
    {
        $field = RestHasOne::make('Author', 'author');
        $field->excludeInstancing();
        $this->assertInstanceOf(RestHasOne::class, $field);
    }

    public function test_rest_has_one_relation_name_and_resource_column_use_column(): void
    {
        $field = RestHasOne::make('Author', 'post_author');

        $this->assertSame('post_author', $field->getRelationName());
        $this->assertSame('post_author', $field->getResourceColumn());
    }
}
