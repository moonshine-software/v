<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Integration;

use App\ApiAdmin\Fields\RestBelongsTo;
use App\ApiAdmin\Fields\RestBelongsToMany;
use App\ApiAdmin\Fields\RestHasMany;
use App\ApiAdmin\Fields\RestHasOne;
use App\ApiAdmin\Resources\AbstractRestResource;
use App\ApiAdmin\ServiceConfig\ResponseMapping;
use App\ApiAdmin\ServiceConfig\ServiceConfig;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use MoonShine\Contracts\Core\DependencyInjection\CoreContract;
use MoonShine\Contracts\UI\FieldContract;
use MoonShine\Core\TypeCasts\MixedDataWrapper;
use MoonShine\Laravel\Providers\MoonShineServiceProvider;
use Orchestra\Testbench\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Tests\ApiAdmin\Fixtures\MockApi\BlogApiService;
use Tests\ApiAdmin\Fixtures\MockApi\ShopApiService;

class RestFieldsTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            MoonShineServiceProvider::class,
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        BlogApiService::fake();

        RestBelongsToMany::flushCaches();
    }

    public function test_belongs_to_loads_options_from_api(): void
    {
        $resource = $this->createBlogResource();

        $field = new RestBelongsTo('Category', 'category_id');
        $field->endpoint('/categories')
            ->resource($resource);

        $options = $field->loadOptions();

        $this->assertNotEmpty($options);
        $this->assertArrayHasKey(1, $options);
        $this->assertEquals('Technology', $options[1]);
    }

    public function test_belongs_to_with_custom_keys(): void
    {
        Http::fake([
            'https://api.blog.test/v1/authors*' => Http::response([
                'data' => [
                    ['uuid' => 'a1', 'full_name' => 'Alice'],
                    ['uuid' => 'b2', 'full_name' => 'Bob'],
                ],
            ]),
        ]);

        $resource = $this->createBlogResource();

        $field = new RestBelongsTo('Author', 'author_id');
        $field->endpoint('/authors')
            ->valueKey('uuid')
            ->labelKey('full_name')
            ->resource($resource);

        $options = $field->loadOptions();

        $this->assertArrayHasKey('a1', $options);
        $this->assertEquals('Alice', $options['a1']);
    }

    public function test_has_many_loads_items(): void
    {
        Http::fake([
            '*' => Http::response([
                'data' => [
                    ['id' => 1, 'author_name' => 'Alice', 'body' => 'Great post!'],
                    ['id' => 2, 'author_name' => 'Bob', 'body' => 'Thanks.'],
                ],
            ]),
        ]);

        $resource = $this->createBlogResource();

        $field = new RestHasMany('Comments', 'comments');
        $field->endpoint('/posts/{parent_id}/comments')
            ->resource($resource);

        $items = $field->loadItems(new MixedDataWrapper(['id' => 1], 1));

        $this->assertNotEmpty($items, 'Items should not be empty');
        // Items may be wrapped or not — verify we got data back
        $firstItem = is_array($items[0] ?? null) ? $items[0] : $items;
        $this->assertNotEmpty($firstItem);
    }

    public function test_belongs_to_emits_endpoint_query_on_load_options(): void
    {
        Http::fake([
            '*/statuses*' => Http::response([
                'data' => [
                    ['id' => 1, 'name' => 'Open'],
                    ['id' => 2, 'name' => 'Closed'],
                ],
            ]),
        ]);

        $resource = $this->createBlogResource();

        $field = new RestBelongsTo('Status', 'status_id')
            ->endpoint('/statuses')
            ->endpointQuery(['for_entity' => 'project'])
            ->resource($resource);

        $options = $field->loadOptions();

        $this->assertNotEmpty($options);
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/statuses')
            && str_contains($r->url(), 'for_entity=project'));
    }

    public function test_belongs_to_unwraps_embedded_relation_object_to_scalar(): void
    {
        // Laravel APIs with `->with('client')` return
        // `{"client": {"id": 4, "name": "Acme"}}` instead of the scalar
        // FK `client_id`. When the Resource column matches the response
        // shape (column = `client`), the raw value is the whole object —
        // Select/TomSelect would render it as "[object Object]" and save
        // would POST the whole array back. toValue() must unwrap to the
        // scalar `valueKey`, and resolvePreview must use the embedded
        // labelKey so index/detail cells show the human-readable name.
        $resource = $this->createBlogResource();

        $field = new RestBelongsTo('Client', 'client')
            ->endpoint('/clients')
            ->resource($resource);

        $field->setValue(['id' => 4, 'name' => 'Acme']);

        $this->assertSame(4, $field->toValue(withDefault: false));

        $previewMethod = new ReflectionMethod($field, 'resolvePreview');
        $this->assertSame('Acme', $previewMethod->invoke($field));
    }

    public function test_belongs_to_preview_escapes_upstream_label(): void
    {
        $field = new RestBelongsTo('Client', 'client')
            ->endpoint('/clients')
            ->resource($this->createBlogResource());

        $field->setValue(['id' => 4, 'name' => '<img src=x onerror=alert(1)>']);

        $this->assertSame(
            '&lt;img src=x onerror=alert(1)&gt;',
            new ReflectionMethod($field, 'resolvePreview')->invoke($field),
        );
    }

    public function test_belongs_to_many_preview_escapes_upstream_labels(): void
    {
        Http::clearResolvedInstances();
        Http::swap(new Factory);
        Http::fake([
            'https://api.blog.test/v1/posts/1/tags' => Http::response(['data' => [['id' => 1, 'name' => 'x']]]),
            'https://api.blog.test/v1/tags*' => Http::response(['data' => [['id' => 1, 'name' => '<img src=x onerror=alert(1)>']]]),
        ]);

        $field = new RestBelongsToMany('Tags', 'tags');
        $field
            ->endpoint('/tags')
            ->attachEndpoint('/posts/{parent_id}/tags')
            ->resource($this->createBlogResource())
            ->fillData(new MixedDataWrapper(['id' => 1], 1))
            ->previewMode();

        $this->assertSame('&lt;img src=x onerror=alert(1)&gt;', $this->invokeProtectedMethod($field, 'resolvePreview'));
    }

    public function test_has_many_preview_escapes_upstream_labels(): void
    {
        $field = new RestHasMany('Comments', 'comments');
        $field->setValue([['id' => 1, 'title' => '<img src=x onerror=alert(1)>']]);

        $this->assertSame('&lt;img src=x onerror=alert(1)&gt;', $this->invokeProtectedMethod($field, 'resolvePreview'));
    }

    public function test_belongs_to_leaves_scalar_fk_value_untouched(): void
    {
        // Inverse guard: when the column already holds a scalar FK
        // (traditional `client_id => 4` shape), toValue() returns it
        // unchanged and does NOT treat it as an embed. Preserves existing
        // behavior for APIs that don't embed relation objects.
        $resource = $this->createBlogResource();

        $field = new RestBelongsTo('Client', 'client_id')
            ->endpoint('/clients')
            ->resource($resource);

        $field->setValue(4);

        $this->assertSame(4, $field->toValue(withDefault: false));
    }

    public function test_has_many_resolves_endpoint_with_parent_id(): void
    {
        Http::fake([
            '*' => Http::response([
                'data' => [
                    ['id' => 10, 'body' => 'Fetched comment'],
                ],
            ]),
        ]);

        $resource = $this->createBlogResource();

        $field = new RestHasMany('Comments', 'comments');
        $field->endpoint('/posts/{parent_id}/comments')
            ->resource($resource);

        $parent = new MixedDataWrapper(['id' => 42], 42);
        $items = $field->loadItems($parent);

        $this->assertNotEmpty($items);

        // Verify the endpoint was called with the parent ID substituted
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/posts/42/comments'));
    }

    public function test_belongs_to_many_loads_options(): void
    {
        $resource = $this->createBlogResource();

        $field = new RestBelongsToMany('Tags', 'tags');
        $field->endpoint('/tags')
            ->resource($resource);

        $options = $field->loadOptions();

        $this->assertNotEmpty($options);
        $this->assertArrayHasKey(1, $options);
        $this->assertEquals('PHP', $options[1]);
    }

    public function test_belongs_to_many_syncs_relation(): void
    {
        Http::fake([
            'https://api.blog.test/v1/posts/1/tags' => Http::response(['success' => true]),
        ]);

        $resource = $this->createBlogResource();

        $field = new RestBelongsToMany('Tags', 'tags');
        $field->endpoint('/tags')
            ->attachEndpoint('/posts/{parent_id}/tags')
            ->resource($resource);

        $parent = new MixedDataWrapper(['id' => 1], 1);
        $result = $field->syncRelation([1, 2, 4], $parent);

        $this->assertTrue($result);
    }

    /**
     * Regression: on detail/index pages prepareBeforeRender skips loading
     * associations, so Select-derived preview rendered as empty. resolvePreview
     * fetches associations + options and joins matching labels.
     */
    public function test_belongs_to_many_preview_renders_associated_labels(): void
    {
        // Reset Http::fakes registered in setUp() so our narrow patterns win.
        Http::clearResolvedInstances();
        Http::swap(new Factory);
        Http::fake([
            'https://api.blog.test/v1/posts/1/tags' => Http::response([
                'data' => [
                    ['id' => 1, 'name' => 'PHP'],
                    ['id' => 4, 'name' => 'Testing'],
                ],
            ]),
            'https://api.blog.test/v1/tags*' => Http::response([
                'data' => [
                    ['id' => 1, 'name' => 'PHP'],
                    ['id' => 2, 'name' => 'Laravel'],
                    ['id' => 4, 'name' => 'Testing'],
                ],
            ]),
        ]);

        $resource = $this->createBlogResource();

        $field = new RestBelongsToMany('Tags', 'tags');
        $field
            ->endpoint('/tags')
            ->attachEndpoint('/posts/{parent_id}/tags')
            ->resource($resource)
            ->fillData(new MixedDataWrapper(['id' => 1], 1))
            ->previewMode();

        $preview = $this->invokeProtectedMethod($field, 'resolvePreview');

        $this->assertSame('PHP, Testing', $preview);
    }

    public function test_belongs_to_many_preview_returns_empty_without_attach_endpoint(): void
    {
        $resource = $this->createBlogResource();

        $field = new RestBelongsToMany('Tags', 'tags');
        $field
            ->endpoint('/tags')
            ->resource($resource)
            ->fillData(new MixedDataWrapper(['id' => 1], 1))
            ->previewMode();

        $this->assertSame('', $this->invokeProtectedMethod($field, 'resolvePreview'));
    }

    public function test_belongs_to_many_preview_returns_empty_for_missing_parent_key(): void
    {
        $resource = $this->createBlogResource();

        $field = new RestBelongsToMany('Tags', 'tags');
        $field
            ->endpoint('/tags')
            ->attachEndpoint('/posts/{parent_id}/tags')
            ->resource($resource)
            ->previewMode();

        $this->assertSame('', $this->invokeProtectedMethod($field, 'resolvePreview'));
    }

    public function test_belongs_to_many_preview_memoizes_load_options_per_request(): void
    {
        Http::clearResolvedInstances();
        Http::swap(new Factory);
        Http::fake([
            'https://api.blog.test/v1/posts/*/tags' => Http::response(['data' => [['id' => 1, 'name' => 'PHP']]]),
            'https://api.blog.test/v1/tags*' => Http::response(['data' => [
                ['id' => 1, 'name' => 'PHP'],
                ['id' => 2, 'name' => 'Laravel'],
            ]]),
        ]);

        $resource = $this->createBlogResource();

        $makeField = function () use ($resource): RestBelongsToMany {
            $field = new RestBelongsToMany('Tags', 'tags');
            $field->endpoint('/tags')
                ->attachEndpoint('/posts/{parent_id}/tags')
                ->resource($resource)
                ->fillData(new MixedDataWrapper(['id' => 1], 1))
                ->previewMode();

            return $field;
        };

        // Render 3 preview rows (same URL query → one HTTP call expected).
        $this->invokeProtectedMethod($makeField(), 'resolvePreview');
        $this->invokeProtectedMethod($makeField(), 'resolvePreview');
        $this->invokeProtectedMethod($makeField(), 'resolvePreview');

        Http::assertSentCount(2); // 1x /posts/1/tags + 1x /tags (options)
    }

    public function test_belongs_to_many_prime_associations_serves_subsequent_loads_from_cache(): void
    {
        Http::clearResolvedInstances();
        Http::swap(new Factory);
        Http::fake([
            'https://api.blog.test/v1/posts/tags-batch*' => Http::response([
                '1' => [1, 2],
                '2' => [4],
                '3' => [],
            ]),
            'https://api.blog.test/v1/tags*' => Http::response(['data' => [
                ['id' => 1, 'name' => 'PHP'],
                ['id' => 2, 'name' => 'Laravel'],
                ['id' => 4, 'name' => 'Testing'],
            ]]),
        ]);

        $resource = $this->createBlogResource();

        $priming = new RestBelongsToMany('Tags', 'tags');
        $priming->endpoint('/tags')
            ->attachEndpoint('/posts/{parent_id}/tags')
            ->batchAttachEndpoint('/posts/tags-batch?parent_ids[]={ids}')
            ->batchAttachMapper(static function (array $payload): array {
                $out = [];
                foreach ($payload as $parentId => $ids) {
                    $list = [];
                    foreach ((array) $ids as $id) {
                        if (is_scalar($id)) {
                            $list[] = (string) $id;
                        }
                    }
                    $out[(string) $parentId] = $list;
                }

                return $out;
            })
            ->resource($resource);

        $primed = $priming->primeAssociations(['1', '2', '3']);

        // PHP auto-converts numeric string keys to int — compare via normalized shape.
        $normalized = [];
        foreach ($primed as $parentId => $relatedIds) {
            $normalized[(string) $parentId] = $relatedIds;
        }
        $this->assertSame(['1' => ['1', '2'], '2' => ['4'], '3' => []], $normalized);

        Http::assertSentCount(1); // single bulk call, nothing more

        // Simulate 3 preview rows — all should read from primed cache.
        foreach (['1', '2', '3'] as $parentId) {
            $field = new RestBelongsToMany('Tags', 'tags');
            $field->endpoint('/tags')
                ->attachEndpoint('/posts/{parent_id}/tags')
                ->batchAttachEndpoint('/posts/tags-batch?parent_ids[]={ids}')
                ->resource($resource)
                ->fillData(new MixedDataWrapper(['id' => $parentId], $parentId))
                ->previewMode();

            $this->invokeProtectedMethod($field, 'resolvePreview');
        }

        // After primeAssociations: 1 call for priming + 1 call for /tags options (shared request cache)
        Http::assertSentCount(2);
    }

    public function test_belongs_to_depends_on_stores_columns_and_auto_enables_reactive(): void
    {
        $resource = $this->createBlogResource();

        $field = new RestBelongsTo('Status', 'status_id');
        $field->endpoint('/statuses')
            ->resource($resource)
            ->endpointQuery(['project_id' => '{project_id}'])
            ->dependsOn(['project_id']);

        $this->assertSame(['project_id'], $field->getDependsOnColumns());
        $this->assertTrue($field->isReactive(), 'dependsOn must auto-mark the field reactive');
    }

    public function test_belongs_to_depends_on_empty_list_does_not_enable_reactive(): void
    {
        $resource = $this->createBlogResource();

        $field = new RestBelongsTo('Status', 'status_id');
        $field->endpoint('/statuses')
            ->resource($resource)
            ->dependsOn([]);

        $this->assertSame([], $field->getDependsOnColumns());
        $this->assertFalse($field->isReactive(), 'empty dependsOn must not force reactive on');
    }

    public function test_belongs_to_many_flush_caches_resets_between_requests(): void
    {
        Http::clearResolvedInstances();
        Http::swap(new Factory);
        Http::fake([
            'https://api.blog.test/v1/posts/*/tags' => Http::response(['data' => [['id' => 1, 'name' => 'PHP']]]),
            'https://api.blog.test/v1/tags*' => Http::response(['data' => [['id' => 1, 'name' => 'PHP']]]),
        ]);

        $resource = $this->createBlogResource();

        $makeField = function () use ($resource): RestBelongsToMany {
            $field = new RestBelongsToMany('Tags', 'tags');
            $field->endpoint('/tags')
                ->attachEndpoint('/posts/{parent_id}/tags')
                ->resource($resource)
                ->fillData(new MixedDataWrapper(['id' => 1], 1))
                ->previewMode();

            return $field;
        };

        $this->invokeProtectedMethod($makeField(), 'resolvePreview');
        Http::assertSentCount(2);

        RestBelongsToMany::flushCaches();
        $this->invokeProtectedMethod($makeField(), 'resolvePreview');

        Http::assertSentCount(4); // 2 initial + 2 after flush
    }

    public function test_has_many_preview_renders_first_three_entity_labels(): void
    {
        $field = new RestHasMany('Comments', 'comments');
        $field->setValue([
            ['id' => 1, 'title' => 'First'],
            ['id' => 2, 'title' => 'Second'],
            ['id' => 3, 'title' => 'Third'],
        ]);

        $this->assertSame(
            'First, Second, Third',
            $this->invokeProtectedMethod($field, 'resolvePreview'),
        );
    }

    public function test_has_many_preview_appends_overflow_suffix(): void
    {
        $field = new RestHasMany('Comments', 'comments');
        $field->setValue([
            ['id' => 1, 'name' => 'Alpha'],
            ['id' => 2, 'name' => 'Beta'],
            ['id' => 3, 'name' => 'Gamma'],
            ['id' => 4, 'name' => 'Delta'],
            ['id' => 5, 'name' => 'Epsilon'],
        ]);

        $this->assertSame(
            'Alpha, Beta, Gamma, +2 more',
            $this->invokeProtectedMethod($field, 'resolvePreview'),
        );
    }

    public function test_has_many_preview_falls_back_to_id_when_no_label_key(): void
    {
        $field = new RestHasMany('Comments', 'comments');
        $field->setValue([
            ['id' => 42, 'body' => 'plain'],
        ]);

        $this->assertSame('#42', $this->invokeProtectedMethod($field, 'resolvePreview'));
    }

    public function test_has_many_preview_returns_empty_for_zero_items(): void
    {
        $field = new RestHasMany('Comments', 'comments');
        $field->setValue([]);

        $this->assertSame('', $this->invokeProtectedMethod($field, 'resolvePreview'));
    }

    public function test_has_many_preview_honors_custom_preview_label_closure(): void
    {
        $field = new RestHasMany('Comments', 'comments');
        $field->previewLabel(
            static function (array $item): string {
                $title = is_scalar($item['title'] ?? null) ? (string) $item['title'] : '?';
                $status = is_scalar($item['status'] ?? null) ? (string) $item['status'] : '?';

                return sprintf('%s (%s)', $title, $status);
            },
        );
        $field->setValue([
            ['id' => 1, 'title' => 'Post', 'status' => 'draft'],
            ['id' => 2, 'title' => 'Note', 'status' => 'published'],
        ]);

        $this->assertSame(
            'Post (draft), Note (published)',
            $this->invokeProtectedMethod($field, 'resolvePreview'),
        );
    }

    public function test_belongs_to_auto_detects_embedded_object_when_column_is_scalar_fk(): void
    {
        // Laravel APIs with `->with('client')` return `{"client": {"id":4,"name":"Acme"}}`
        // without a scalar `client_id` sibling. When the Resource column IS `client_id`,
        // the field must strip `_id` and look up `client.id` from the same payload so
        // the EDIT form stays pre-selected. Without this, the select rendered empty.
        $resource = $this->createBlogResource();

        $field = new RestBelongsTo('Client', 'client_id')
            ->endpoint('/clients')
            ->resource($resource);

        $parent = new MixedDataWrapper([
            'id' => 1,
            'client' => ['id' => 4, 'name' => 'Acme'],
        ], 1);

        $field->fillData($parent);

        $this->assertSame(4, $field->toValue(withDefault: false));

        // Embed was stashed — preview uses the label directly, no HTTP call.
        $preview = new ReflectionMethod($field, 'resolvePreview')->invoke($field);
        $this->assertSame('Acme', $preview);
    }

    public function test_belongs_to_fill_from_dot_path_overrides_column(): void
    {
        // Explicit fill_from handles shapes the auto-detect can't guess —
        // e.g. the API returns `{"owner": {"uuid": "xyz"}}` but the column is
        // `owner_ref` (no `_id` suffix, so strip-and-probe skips it). The user
        // points fill_from at `owner.uuid` and the field pulls the scalar.
        $resource = $this->createBlogResource();

        $field = new RestBelongsTo('Owner', 'owner_ref')
            ->endpoint('/owners')
            ->valueKey('uuid')
            ->labelKey('full_name')
            ->fillFrom('owner.uuid')
            ->resource($resource);

        $parent = new MixedDataWrapper([
            'id' => 1,
            'owner' => ['uuid' => 'xyz', 'full_name' => 'Alice Example'],
        ], 1);

        $field->fillData($parent);

        $this->assertSame('xyz', $field->toValue(withDefault: false));
    }

    public function test_belongs_to_auto_detect_skips_when_column_has_no_id_suffix(): void
    {
        // Guard: without the trailing `_id`, strip-and-probe would misread
        // unrelated columns. The field must NOT walk into the parent payload
        // unless the caller opts in via fill_from.
        $resource = $this->createBlogResource();

        $field = new RestBelongsTo('Status', 'status')
            ->endpoint('/statuses')
            ->resource($resource);

        $parent = new MixedDataWrapper([
            'id' => 1,
            'status' => ['id' => 9, 'name' => 'Open'],
        ], 1);

        $field->fillData($parent);

        // parent::toValue returns the whole object, the existing
        // embedded-object unwrap kicks in, yielding the scalar id.
        $this->assertSame(9, $field->toValue(withDefault: false));
    }

    public function test_has_many_preview_limit_is_configurable(): void
    {
        $field = new RestHasMany('Comments', 'comments');
        $field->previewLimit(1);
        $field->setValue([
            ['id' => 1, 'title' => 'One'],
            ['id' => 2, 'title' => 'Two'],
            ['id' => 3, 'title' => 'Three'],
        ]);

        $this->assertSame(
            'One, +2 more',
            $this->invokeProtectedMethod($field, 'resolvePreview'),
        );
    }

    /**
     * @param list<mixed> $args
     */
    private function invokeProtectedMethod(object $obj, string $method, array $args = []): mixed
    {
        $m = new ReflectionClass($obj)->getMethod($method);

        return $m->invokeArgs($obj, $args);
    }

    public function test_django_format_response_mapping(): void
    {
        ShopApiService::fake();

        $resource = $this->createShopResource();

        $field = new RestBelongsTo('Brand', 'brand_id');
        $field->endpoint('/brands')
            ->dataWrapper('results')
            ->resource($resource);

        $options = $field->loadOptions();

        $this->assertNotEmpty($options);
        $this->assertArrayHasKey(1, $options);
        $this->assertEquals('Acme Corp', $options[1]);
    }

    public function test_belongs_to_loads_options_from_target_service(): void
    {
        Http::fake([
            'https://api.shop.test/api/brands*' => Http::response(ShopApiService::brandsResponse()),
        ]);
        $this->bindShopService();

        $field = new RestBelongsTo('Brand', 'brand_id');
        $field->targetService('shop')
            ->endpoint('/brands')
            ->resource($this->createBlogResource());

        $options = $field->loadOptions();

        $this->assertSame('Acme Corp', $options[1] ?? null);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'https://api.shop.test/api/brands')
            && $request->hasHeader('Authorization', 'Bearer shop-key'));
    }

    public function test_belongs_to_many_syncs_relation_on_target_service(): void
    {
        Http::fake([
            'https://api.shop.test/api/products/1/categories' => Http::response(['success' => true]),
        ]);
        $this->bindShopService();

        $field = new RestBelongsToMany('Categories', 'categories');
        $field->targetService('shop')
            ->endpoint('/categories')
            ->attachEndpoint('/products/{parent_id}/categories')
            ->resource($this->createBlogResource());

        $this->assertTrue($field->syncRelation([1, 2], new MixedDataWrapper(['id' => 1], 1)));
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.shop.test/api/products/1/categories'
            && $request->method() === 'PUT');
    }

    public function test_has_many_loads_items_from_target_service_mapping(): void
    {
        Http::fake([
            'https://api.shop.test/api/products/1/variants*' => Http::response(ShopApiService::variantsResponse()),
        ]);
        $this->bindShopService();

        $field = new RestHasMany('Variants', 'variants');
        $field->targetService('shop')
            ->endpoint('/products/{parent_id}/variants')
            ->resource($this->createBlogResource());

        $items = $field->loadItems(new MixedDataWrapper(['id' => 1], 1));

        $this->assertCount(3, $items);
        $this->assertSame('S', $items[0]['size'] ?? null);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'https://api.shop.test/api/products/1/variants'));
    }

    public function test_has_one_loads_item_from_target_service_mapping(): void
    {
        Http::fake([
            'https://api.shop.test/api/products/1/specs*' => Http::response(ShopApiService::specsResponse()),
        ]);
        $this->bindShopService();

        $field = new RestHasOne('Specs', 'specs');
        $field->targetService('shop')
            ->endpoint('/products/{parent_id}/specs')
            ->resource($this->createBlogResource());

        $item = $field->loadItem(new MixedDataWrapper(['id' => 1], 1));

        $this->assertSame('250g', $item['weight'] ?? null);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'https://api.shop.test/api/products/1/specs'));
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

    private function bindShopService(): void
    {
        app()->singleton('api-admin.service.shop', fn (): ServiceConfig => new ServiceConfig(
            name: 'shop',
            baseUrl: 'https://api.shop.test/api',
            apiKey: 'shop-key',
            responseMapping: new ResponseMapping(
                dataKey: 'results',
                totalKey: 'count',
                perPageKey: 'page_size',
            ),
        ));
    }

    private function createShopResource(): AbstractRestResource
    {
        $core = app(CoreContract::class);

        return new class($core) extends AbstractRestResource
        {
            protected function resolveServiceConfig(): ServiceConfig
            {
                return new ServiceConfig(
                    name: 'shop',
                    baseUrl: 'https://api.shop.test/api',
                    apiKey: 'shop-key',
                    responseMapping: new ResponseMapping(
                        dataKey: 'results',
                        totalKey: 'count',
                        perPageKey: 'page_size',
                    ),
                );
            }

            protected function endpoint(): string
            {
                return '/products';
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
}
