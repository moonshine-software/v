<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Integration;

use App\ApiAdmin\Fields\RestBelongsTo;
use App\ApiAdmin\Fields\RestBelongsToMany;
use App\ApiAdmin\Fields\RestHasMany;
use App\ApiAdmin\Http\Controllers\RestAsyncSearchController;
use App\ApiAdmin\Providers\ApiAdminServiceProvider;
use App\ApiAdmin\Resources\AbstractRestResource;
use App\ApiAdmin\ServiceConfig\ResponseMapping;
use App\ApiAdmin\ServiceConfig\ServiceConfig;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use MoonShine\Contracts\Core\DependencyInjection\CoreContract;
use MoonShine\Contracts\Core\ResourceContract;
use MoonShine\Contracts\UI\FieldContract;
use MoonShine\Core\TypeCasts\MixedDataWrapper;
use MoonShine\Laravel\Models\MoonshineUser;
use MoonShine\Laravel\Providers\MoonShineServiceProvider;
use Orchestra\Testbench\TestCase;
use Override;
use ReflectionClass;
use Tests\ApiAdmin\Fixtures\MockApi\ShopApiService;

class CrossServiceRelationTest extends TestCase
{
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

        $this->actingAs(new MoonshineUser(['id' => 1]), 'moonshine');

        $this->bindShopService();
        $this->registerTestResource();
    }

    public function test_has_many_load_single_item_uses_target_service_response_mapping(): void
    {
        Http::fake([
            'https://api.shop.test/api/products/1/variants/2*' => Http::response(ShopApiService::variantsResponse()),
        ]);

        $field = new RestHasMany('Variants', 'variants');
        $field->targetService('shop')
            ->endpoint('/products/{parent_id}/variants')
            ->parentId(1)
            ->resource($this->createBlogResource());

        $item = $field->loadSingleItem(2);

        $this->assertSame('M', $item['size'] ?? null);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.shop.test/api/products/1/variants/2');
    }

    public function test_has_many_missing_parent_placeholder_returns_empty_without_request(): void
    {
        Http::fake([
            '*' => Http::response(ShopApiService::variantsResponse()),
        ]);

        $field = new RestHasMany('Variants', 'variants');
        $field->targetService('shop')
            ->endpoint('/products/{parent_id}/variants')
            ->resource($this->createBlogResource());

        $this->assertSame([], $field->loadItems());
        Http::assertNothingSent();
    }

    public function test_belongs_to_endpoint_placeholder_resolves_from_parent_item_context(): void
    {
        Http::fake([
            'https://api.shop.test/api/catalog/7/brands*' => Http::response(ShopApiService::brandsResponse()),
        ]);

        $field = new RestBelongsTo('Brand', 'brand_id');
        $field->targetService('shop')
            ->endpoint('/catalog/{catalog_id}/brands')
            ->resource($this->createBlogResource())
            ->fillData(new MixedDataWrapper(['id' => 10, 'catalog_id' => 7], 10));

        $options = $field->loadOptions();

        $this->assertSame('Acme Corp', $options[1] ?? null);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.shop.test/api/catalog/7/brands');
    }

    public function test_belongs_to_many_preview_uses_target_service_for_options_and_current_associations(): void
    {
        Http::fake([
            'https://api.shop.test/api/categories*' => Http::response(ShopApiService::shopCategoriesResponse()),
            'https://api.shop.test/api/products/1/categories*' => Http::response([
                'results' => [
                    ['id' => 1],
                    ['id' => 3],
                ],
            ]),
        ]);

        $field = new RestBelongsToMany('Categories', 'categories');
        $field->targetService('shop')
            ->endpoint('/categories')
            ->attachEndpoint('/products/{parent_id}/categories')
            ->resource($this->createBlogResource())
            ->fillData(new MixedDataWrapper(['id' => 1], 1));

        $preview = $this->invokeProtectedMethod($field, 'resolvePreview');

        $this->assertSame('Clothing, Home &amp; Garden', $preview);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.shop.test/api/products/1/categories'
            && $request->hasHeader('Authorization', 'Bearer shop-key'));
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.shop.test/api/categories'
            && $request->hasHeader('Authorization', 'Bearer shop-key'));
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'https://api.blog.test/v1'));
    }

    public function test_missing_target_service_degrades_relation_options_without_http_request(): void
    {
        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('warning')
            ->once()
            ->with('RestRelationTarget::resolve — target service unavailable', Mockery::on(
                fn (array $context): bool => ($context['target_service'] ?? null) === 'missing-shop',
            ));
        Http::fake([
            '*' => Http::response(ShopApiService::brandsResponse()),
        ]);

        $field = new RestBelongsTo('Brand', 'brand_id');
        $field->targetService('missing-shop')
            ->endpoint('/brands')
            ->resource($this->createBlogResource());

        $this->assertSame([], $field->loadOptions());
        Http::assertNothingSent();
    }

    public function test_async_search_uses_field_target_service_over_request_service(): void
    {
        Http::fake([
            'https://api.blog.test/v1/brands*' => Http::response(['data' => [['id' => 9, 'name' => 'Wrong Service']]]),
            'https://api.shop.test/api/brands*' => Http::response(ShopApiService::brandsResponse()),
        ]);

        $controller = new RestAsyncSearchController;
        $request = HttpRequest::create('/api-admin/async-search', 'GET', [
            '_resource' => $this->resourceUri(),
            '_relation' => 'brand_id',
        ]);

        $response = $controller($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([
            ['value' => '1', 'label' => 'Acme Corp'],
            ['value' => '2', 'label' => 'Globex Inc'],
        ], $response->getData(true));
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'https://api.shop.test/api/brands'));
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'https://api.blog.test/v1/brands'));
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

    private function registerTestResource(): void
    {
        /** @var list<class-string<ResourceContract>> $resources */
        $resources = [CrossServicePostResource::class];
        moonshine()->resources($resources);
    }

    private function resourceUri(): string
    {
        return new CrossServicePostResource(app(CoreContract::class))->getUriKey();
    }

    /**
     * @param list<mixed> $args
     */
    private function invokeProtectedMethod(object $object, string $method, array $args = []): mixed
    {
        $reflection = new ReflectionClass($object);
        $reflectedMethod = $reflection->getMethod($method);

        return $reflectedMethod->invokeArgs($object, $args);
    }
}

class CrossServicePostResource extends AbstractRestResource
{
    #[Override]
    protected string $title = 'Posts';

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

    #[Override]
    protected function formFields(): iterable
    {
        return [
            RestBelongsTo::make('Brand', 'brand_id')
                ->targetService('shop')
                ->endpoint('/brands'),
        ];
    }
}
