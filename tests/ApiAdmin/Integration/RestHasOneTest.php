<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Integration;

use App\ApiAdmin\Fields\RestHasOne;
use App\ApiAdmin\Providers\ApiAdminServiceProvider;
use App\ApiAdmin\Resources\AbstractRestResource;
use App\ApiAdmin\ServiceConfig\ResponseMapping;
use App\ApiAdmin\ServiceConfig\ServiceConfig;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use MoonShine\Contracts\Core\DependencyInjection\CoreContract;
use MoonShine\Contracts\UI\ComponentContract;
use MoonShine\Contracts\UI\FieldContract;
use MoonShine\Contracts\UI\RelationFieldContract;
use MoonShine\Core\TypeCasts\MixedDataWrapper;
use MoonShine\Laravel\Providers\MoonShineServiceProvider;
use MoonShine\UI\Components\FormBuilder;
use MoonShine\UI\Components\Layout\Box;
use MoonShine\UI\Components\Table\TableBuilder;
use MoonShine\UI\Fields\ID;
use MoonShine\UI\Fields\Text;
use Orchestra\Testbench\TestCase;
use ReflectionClass;

/**
 * Tests for RestHasOne field — one-to-one REST relation with full CRUD.
 *
 * Each test configures its own Http::fake to avoid the overly-broad
 * '/posts*' pattern that would otherwise mask narrower author routes.
 */
class RestHasOneTest extends TestCase
{
    private AbstractRestResource $parent;

    protected function getPackageProviders($app): array
    {
        return [
            MoonShineServiceProvider::class,
            ApiAdminServiceProvider::class,
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->parent = $this->createBlogResource();
    }

    // ── Interface compliance ─────────────────────────────────────────

    public function test_implements_relation_field_contract(): void
    {
        $field = RestHasOne::make('Author', 'author');

        $this->assertInstanceOf(RelationFieldContract::class, $field);
    }

    public function test_is_to_one_returns_true(): void
    {
        $this->assertTrue(RestHasOne::make('Author', 'author')->isToOne());
    }

    public function test_is_morph_returns_false(): void
    {
        $this->assertFalse(RestHasOne::make('Author', 'author')->isMorph());
    }

    public function test_is_outside_component_by_default(): void
    {
        $this->assertTrue(RestHasOne::make('Author', 'author')->isOutsideComponent());
    }

    public function test_disable_outside_flips_flag(): void
    {
        $this->assertFalse(RestHasOne::make('Author', 'author')->disableOutside()->isOutsideComponent());
    }

    public function test_relation_name_uses_column(): void
    {
        $field = RestHasOne::make('Author', 'author');

        $this->assertSame('author', $field->getRelationName());
        $this->assertSame('author', $field->getResourceColumn());
    }

    public function test_get_related_returns_null_for_rest_field(): void
    {
        $this->assertNull(RestHasOne::make('Author', 'author')->getRelated());
    }

    // ── HTTP: loadItem ───────────────────────────────────────────────

    public function test_load_item_returns_data_on_200(): void
    {
        Http::fake([
            'https://api.blog.test/v1/posts/1/author*' => Http::response([
                'data' => ['id' => 1, 'name' => 'John Doe', 'email' => 'john@example.com'],
            ]),
        ]);

        $field = $this->makeField();

        $item = $field->loadItem(new MixedDataWrapper(['id' => 1], 1));

        $this->assertIsArray($item);
        $this->assertSame('John Doe', $item['name']);
    }

    public function test_load_item_returns_null_on_404(): void
    {
        Http::fake([
            '*' => Http::response(null, 404),
        ]);

        $this->assertNull($this->makeField()->loadItem(new MixedDataWrapper(['id' => 99], 99)));
    }

    public function test_load_item_returns_null_without_parent_id(): void
    {
        $this->assertNull($this->makeField()->loadItem());
    }

    public function test_load_item_returns_null_without_parent_resource(): void
    {
        $field = RestHasOne::make('Author', 'author')->endpoint('/posts/{parent_id}/author');

        $this->assertNull($field->loadItem(new MixedDataWrapper(['id' => 1], 1)));
    }

    public function test_load_item_substitutes_parent_id_in_endpoint(): void
    {
        Http::fake([
            '*' => Http::response(['data' => ['id' => 1, 'name' => 'Ada']]),
        ]);

        $this->makeField()->loadItem(new MixedDataWrapper(['id' => 42], 42));

        Http::assertSent(fn (Request $req): bool => str_contains($req->url(), '/posts/42/author'));
    }

    public function test_load_item_respects_custom_parent_key_param(): void
    {
        Http::fake(['*' => Http::response(['data' => ['id' => 1]])]);

        $field = RestHasOne::make('Author', 'author')
            ->endpoint('/posts/{postId}/author')
            ->parentKeyParam('postId')
            ->resource($this->parent);

        $field->loadItem(new MixedDataWrapper(['id' => 7], 7));

        Http::assertSent(fn (Request $req): bool => str_contains($req->url(), '/posts/7/author'));
    }

    public function test_load_item_returns_null_when_data_not_associative(): void
    {
        Http::fake(['*' => Http::response(['data' => [1, 2, 3]])]);

        $this->assertNull($this->makeField()->loadItem(new MixedDataWrapper(['id' => 1], 1)));
    }

    public function test_load_item_respects_custom_data_wrapper(): void
    {
        Http::fake([
            'https://api.blog.test/v1/posts/*/author*' => Http::response([
                'result' => ['id' => 1, 'name' => 'Wrap'],
            ]),
        ]);

        $field = RestHasOne::make('Author', 'author')
            ->endpoint('/posts/{parent_id}/author')
            ->dataWrapper('result')
            ->resource($this->parent);

        $item = $field->loadItem(new MixedDataWrapper(['id' => 1], 1));

        $this->assertIsArray($item);
        $this->assertSame('Wrap', $item['name']);
    }

    public function test_load_item_swallows_exceptions_and_returns_null(): void
    {
        Http::fake(['*' => Http::response(null, 500)]);

        $this->assertNull($this->makeField()->loadItem(new MixedDataWrapper(['id' => 1], 1)));
    }

    // ── HTTP: create ─────────────────────────────────────────────────

    public function test_create_item_posts_to_parent_scoped_endpoint(): void
    {
        Http::fake(['*' => Http::response(['data' => ['id' => 1, 'name' => 'New']], 201)]);

        $result = $this->makeField()->createItemForParent(['name' => 'New'], 5);

        $this->assertIsArray($result);
        Http::assertSent(fn (Request $req): bool => $req->method() === 'POST' && str_contains($req->url(), '/posts/5/author'));
    }

    public function test_create_item_returns_null_without_parent_resource(): void
    {
        $field = RestHasOne::make('Author', 'author')->endpoint('/posts/{parent_id}/author');

        $this->assertNull($field->createItemForParent(['name' => 'x'], 1));
    }

    public function test_create_item_returns_null_on_http_failure(): void
    {
        Http::fake(['*' => Http::response(null, 500)]);

        $this->assertNull($this->makeField()->createItemForParent(['name' => 'x'], 1));
    }

    // ── HTTP: update ─────────────────────────────────────────────────

    public function test_update_item_puts_to_parent_scoped_endpoint(): void
    {
        Http::fake(['*' => Http::response(['data' => ['id' => 1, 'name' => 'Updated']])]);

        $result = $this->makeField()->updateItemForParent(['name' => 'Updated'], 7);

        $this->assertIsArray($result);
        Http::assertSent(fn (Request $req): bool => $req->method() === 'PUT' && str_contains($req->url(), '/posts/7/author'));
    }

    public function test_update_item_returns_null_on_http_failure(): void
    {
        Http::fake(['*' => Http::response(null, 500)]);

        $this->assertNull($this->makeField()->updateItemForParent(['name' => 'x'], 1));
    }

    public function test_update_item_returns_null_without_parent_resource(): void
    {
        $field = RestHasOne::make('Author', 'author')->endpoint('/posts/{parent_id}/author');

        $this->assertNull($field->updateItemForParent(['name' => 'x'], 1));
    }

    // ── HTTP: delete ─────────────────────────────────────────────────

    public function test_delete_item_sends_delete_request(): void
    {
        Http::fake(['*' => Http::response(null, 204)]);

        $this->assertTrue($this->makeField()->deleteItemForParent(3));
        Http::assertSent(fn (Request $req): bool => $req->method() === 'DELETE' && str_contains($req->url(), '/posts/3/author'));
    }

    public function test_delete_item_treats_404_as_success(): void
    {
        Http::fake(['*' => Http::response(null, 404)]);

        $this->assertTrue($this->makeField()->deleteItemForParent(3));
    }

    public function test_delete_item_returns_false_on_500(): void
    {
        Http::fake(['*' => Http::response('fail', 500)]);

        $this->assertFalse($this->makeField()->deleteItemForParent(3));
    }

    public function test_delete_item_returns_false_without_parent_resource(): void
    {
        $field = RestHasOne::make('Author', 'author')->endpoint('/posts/{parent_id}/author');

        $this->assertFalse($field->deleteItemForParent(1));
    }

    // ── CRUD flags ───────────────────────────────────────────────────

    public function test_creatable_editable_removable_flags_default_false(): void
    {
        $field = RestHasOne::make('Author', 'author');

        $this->assertFalse($this->getProtected($field, 'isCreatable'));
        $this->assertFalse($this->getProtected($field, 'isEditable'));
        $this->assertFalse($this->getProtected($field, 'isRemovable'));
    }

    public function test_creatable_editable_removable_set_flags(): void
    {
        $field = RestHasOne::make('Author', 'author')->creatable()->editable()->removable();

        $this->assertTrue($this->getProtected($field, 'isCreatable'));
        $this->assertTrue($this->getProtected($field, 'isEditable'));
        $this->assertTrue($this->getProtected($field, 'isRemovable'));
    }

    public function test_full_crud_enables_all_flags(): void
    {
        $field = RestHasOne::make('Author', 'author')->fullCrud();

        $this->assertTrue($this->getProtected($field, 'isCreatable'));
        $this->assertTrue($this->getProtected($field, 'isEditable'));
        $this->assertTrue($this->getProtected($field, 'isRemovable'));
    }

    // ── View mode: form vs preview ───────────────────────────────────

    public function test_view_data_returns_box_with_table_when_no_crud_enabled(): void
    {
        $field = $this->makeField()->fields([Text::make('Name', 'name')]);

        $data = $this->invokeProtected($field, 'viewData');
        $this->assertIsArray($data);

        $this->assertInstanceOf(Box::class, $data['component']);
        $this->assertSame('Author', $data['component']->getLabel());
        $children = $this->boxComponents($data['component']);
        $this->assertNotEmpty($children);
        $this->assertInstanceOf(TableBuilder::class, $children[0]);
    }

    public function test_view_data_returns_box_with_form_when_crud_enabled(): void
    {
        Http::fake(['*' => Http::response(null, 404)]);

        $field = $this->makeField()
            ->fields([Text::make('Name', 'name')])
            ->creatable()
            ->parentId(1);

        $data = $this->invokeProtected($field, 'viewData');
        $this->assertIsArray($data);

        $this->assertInstanceOf(Box::class, $data['component']);
        $children = $this->boxComponents($data['component']);
        $this->assertInstanceOf(FormBuilder::class, $children[0]);
    }

    public function test_view_data_falls_back_to_table_when_endpoint_missing(): void
    {
        $field = RestHasOne::make('Author', 'author')
            ->fields([Text::make('Name', 'name')])
            ->creatable()
            ->resource($this->parent);

        $data = $this->invokeProtected($field, 'viewData');
        $this->assertIsArray($data);

        $this->assertInstanceOf(Box::class, $data['component']);
        $children = $this->boxComponents($data['component']);
        $this->assertInstanceOf(TableBuilder::class, $children[0]);
    }

    public function test_has_wrapper_returns_false(): void
    {
        $this->assertFalse(RestHasOne::make('Author', 'author')->hasWrapper());
    }

    public function test_form_component_uses_store_route_when_no_item(): void
    {
        Http::fake(['*' => Http::response(null, 404)]);

        $field = $this->makeField()
            ->fields([Text::make('Name', 'name')])
            ->creatable()
            ->parentId(1);

        $action = $field->getFormComponent()->getAction();

        $this->assertStringContainsString('/rest-has-one/store', $action);
        $this->assertStringContainsString('_parent_id=1', $action);
    }

    public function test_form_component_uses_update_route_when_item_exists(): void
    {
        Http::fake([
            '*' => Http::response(['data' => ['id' => 1, 'name' => 'John']]),
        ]);

        $field = $this->makeField()
            ->fields([Text::make('Name', 'name')])
            ->editable()
            ->parentId(1);

        $field->fillData(new MixedDataWrapper(['id' => 1], 1));

        $action = $field->getFormComponent()->getAction();

        $this->assertStringContainsString('/rest-has-one/update', $action);
    }

    public function test_form_component_name_is_stable(): void
    {
        $field = RestHasOne::make('Author', 'author');

        $this->assertSame('rest-has-one-author', $field->getFormComponentName());
    }

    /**
     * Regression: resolvePreview previously read $this->toValue() (which returns
     * the raw stored value, never running resolveValue), so on detail pages where
     * the related item isn't embedded in the parent payload the preview was empty.
     */
    public function test_resolve_preview_lazy_loads_related_item(): void
    {
        Http::fake([
            'https://api.blog.test/v1/posts/1/author*' => Http::response([
                'data' => ['id' => 7, 'name' => 'Ada Lovelace', 'email' => 'ada@example.com'],
            ]),
        ]);

        $field = $this->makeField();
        $field->fields([
            ID::make('Id', 'id'),
            Text::make('Name', 'name'),
            Text::make('Email', 'email'),
        ]);
        $field->fillData(new MixedDataWrapper(['id' => 1], 1));
        $field->previewMode();

        $previewRaw = $this->invokeProtected($field, 'resolvePreview');
        $preview = is_string($previewRaw) ? $previewRaw : '';

        $this->assertNotSame('', $preview);
        $this->assertStringContainsString('Ada Lovelace', $preview);
        $this->assertStringContainsString('ada@example.com', $preview);
    }

    public function test_resolve_preview_returns_empty_when_no_related_item(): void
    {
        Http::fake(['*' => Http::response(null, 404)]);

        $field = $this->makeField();
        $field->fields([Text::make('Name', 'name')]);
        $field->fillData(new MixedDataWrapper(['id' => 99], 99));
        $field->previewMode();

        $emptyRaw = $this->invokeProtected($field, 'resolvePreview');
        $this->assertSame('', is_string($emptyRaw) ? $emptyRaw : '');
    }

    public function test_form_event_name_uses_fragment_updated(): void
    {
        $field = RestHasOne::make('Author', 'author');

        $this->assertStringContainsString('fragment_updated', $field->getFormEventName());
        $this->assertStringContainsString('rest-has-one-author', $field->getFormEventName());
    }

    // ── Helpers ──────────────────────────────────────────────────────

    private function makeField(): RestHasOne
    {
        return RestHasOne::make('Author', 'author')
            ->endpoint('/posts/{parent_id}/author')
            ->resource($this->parent);
    }

    private function createBlogResource(): AbstractRestResource
    {
        $core = app(CoreContract::class);

        return new class($core) extends AbstractRestResource
        {
            protected function resolveServiceConfig(): ServiceConfig
            {
                return new ServiceConfig(
                    name: 'blog',
                    baseUrl: 'https://api.blog.test/v1',
                    apiKey: 'test-key',
                    responseMapping: new ResponseMapping,
                );
            }

            protected function endpoint(): string
            {
                return '/posts';
            }

            /** @return iterable<FieldContract> */
            /** @return iterable<FieldContract> */
            /** @return iterable<FieldContract> */
            protected function fields(): iterable
            {
                return [];
            }
        };
    }

    /**
     * @return array<int, ComponentContract>
     */
    private function boxComponents(Box $box): array
    {
        /** @var array<int, ComponentContract> $components */
        $components = $box->getComponents()->toArray();

        return $components;
    }

    /**
     * @param list<mixed> $args
     */
    private function invokeProtected(object $obj, string $method, array $args = []): mixed
    {
        $ref = new ReflectionClass($obj)->getMethod($method);

        return $ref->invokeArgs($obj, $args);
    }

    private function getProtected(object $obj, string $name): mixed
    {
        $prop = new ReflectionClass($obj)->getProperty($name);

        return $prop->getValue($obj);
    }
}
