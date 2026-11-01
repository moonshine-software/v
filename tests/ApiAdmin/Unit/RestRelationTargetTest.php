<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Unit;

use App\ApiAdmin\Fields\RestRelationTarget;
use App\ApiAdmin\Resources\AbstractRestResource;
use App\ApiAdmin\ServiceConfig\ResponseMapping;
use App\ApiAdmin\ServiceConfig\ServiceConfig;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use MoonShine\Contracts\Core\DependencyInjection\CoreContract;
use MoonShine\Contracts\UI\FieldContract;
use MoonShine\Laravel\Providers\MoonShineServiceProvider;
use Orchestra\Testbench\TestCase;

final class RestRelationTargetTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            MoonShineServiceProvider::class,
        ];
    }

    public function test_same_service_target_uses_parent_resource_http_client(): void
    {
        Http::fake([
            'https://api.blog.test/v1/categories*' => Http::response(['data' => []]),
        ]);

        $target = RestRelationTarget::resolve($this->createBlogResource());

        $this->assertInstanceOf(RestRelationTarget::class, $target);
        $this->assertSame('blog', $target->sourceService());
        $this->assertSame('blog', $target->targetService());
        $this->assertSame('data', $target->dataWrapper());
        $this->assertTrue($target->usesParentClient());

        $response = $target->httpClient()->get('/categories');
        $this->assertInstanceOf(Response::class, $response);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.blog.test/v1/categories'
            && $request->hasHeader('X-Parent-Client', 'yes'));
    }

    public function test_cross_service_target_uses_registered_target_service_config(): void
    {
        Http::fake([
            'https://api.shop.test/api/brands*' => Http::response(['results' => []]),
        ]);

        app()->singleton('api-admin.service.shop', fn (): ServiceConfig => new ServiceConfig(
            name: 'shop',
            baseUrl: 'https://api.shop.test/api',
            apiKey: 'shop-key',
            responseMapping: new ResponseMapping(dataKey: 'results'),
        ));

        $target = RestRelationTarget::resolve($this->createBlogResource(), 'shop');

        $this->assertInstanceOf(RestRelationTarget::class, $target);
        $this->assertSame('blog', $target->sourceService());
        $this->assertSame('shop', $target->targetService());
        $this->assertSame('results', $target->dataWrapper());
        $this->assertFalse($target->usesParentClient());

        $target->httpClient()->get('/brands');

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.shop.test/api/brands'
            && $request->hasHeader('Authorization', 'Bearer shop-key')
            && ! $request->hasHeader('X-Parent-Client'));
    }

    public function test_explicit_data_wrapper_overrides_target_mapping(): void
    {
        app()->singleton('api-admin.service.shop', fn (): ServiceConfig => new ServiceConfig(
            name: 'shop',
            baseUrl: 'https://api.shop.test/api',
            apiKey: 'shop-key',
            responseMapping: new ResponseMapping(dataKey: 'results'),
        ));

        $target = RestRelationTarget::resolve($this->createBlogResource(), 'shop', 'items');

        $this->assertInstanceOf(RestRelationTarget::class, $target);
        $this->assertSame('items', $target->dataWrapper());
    }

    public function test_unknown_target_service_fails_closed(): void
    {
        $target = RestRelationTarget::resolve($this->createBlogResource(), 'missing');

        $this->assertNull($target);
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
                    apiKey: 'blog-key',
                    responseMapping: new ResponseMapping,
                );
            }

            protected function endpoint(): string
            {
                return '/posts';
            }

            protected function modifyHttpClient(PendingRequest $client): PendingRequest
            {
                return $client->withHeader('X-Parent-Client', 'yes');
            }

            /** @return iterable<FieldContract> */
            protected function fields(): iterable
            {
                return [];
            }
        };
    }
}
