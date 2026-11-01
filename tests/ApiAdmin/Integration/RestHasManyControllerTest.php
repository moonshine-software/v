<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Integration;

use App\ApiAdmin\Fields\RestHasMany;
use App\ApiAdmin\Providers\ApiAdminServiceProvider;
use App\ApiAdmin\Resources\AbstractRestResource;
use App\ApiAdmin\ServiceConfig\ResponseMapping;
use App\ApiAdmin\ServiceConfig\ServiceConfig;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use MoonShine\Contracts\Core\DependencyInjection\CoreContract;
use MoonShine\Contracts\Core\ResourceContract;
use MoonShine\Laravel\Models\MoonshineUser;
use MoonShine\Laravel\Providers\MoonShineServiceProvider;
use MoonShine\UI\Fields\Text;
use Orchestra\Testbench\TestCase;
use Override;

class RestHasManyControllerTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            MoonShineServiceProvider::class,
            ApiAdminServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(new MoonshineUser(['id' => 1]), 'moonshine');

        $this->withoutMiddleware(VerifyCsrfToken::class);
        $this->bindShopService();
        $this->registerTestResource();
    }

    public function test_store_uses_field_target_service_over_request_service(): void
    {
        Http::fake([
            'https://api.blog.test/v1/posts/5/variants' => Http::response(['data' => ['id' => 9, 'name' => 'Wrong']]),
            'https://api.shop.test/api/posts/5/variants' => Http::response(['results' => ['id' => 1, 'name' => 'Blue']], 201),
        ]);

        $response = $this->post('/rest-has-many/store', [
            '_resource' => $this->resourceUri(),
            '_relation' => 'variants',
            '_parent_id' => 5,
            'service' => 'blog',
            'name' => 'Blue',
        ]);

        $response->assertOk()->assertJsonStructure(['message']);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://api.shop.test/api/posts/5/variants');
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'https://api.blog.test/v1/posts/5/variants'));
    }

    public function test_update_uses_field_target_service_over_request_service(): void
    {
        Http::fake([
            'https://api.blog.test/v1/posts/5/variants/8' => Http::response(['data' => ['id' => 8, 'name' => 'Wrong']]),
            'https://api.shop.test/api/posts/5/variants/8' => Http::response(['results' => ['id' => 8, 'name' => 'Green']]),
        ]);

        $response = $this->put('/rest-has-many/update?_resource='.$this->resourceUri().'&_relation=variants&_parent_id=5&_key=8&service=blog', [
            'name' => 'Green',
        ]);

        $response->assertOk()->assertJsonStructure(['message']);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
            && $request->url() === 'https://api.shop.test/api/posts/5/variants/8');
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'https://api.blog.test/v1/posts/5/variants/8'));
    }

    public function test_destroy_uses_field_target_service_over_request_service(): void
    {
        Http::fake([
            'https://api.blog.test/v1/posts/5/variants/8' => Http::response(null, 204),
            'https://api.shop.test/api/posts/5/variants/8' => Http::response(null, 204),
        ]);

        $response = $this->delete('/rest-has-many/destroy?_resource='.$this->resourceUri().'&_relation=variants&_parent_id=5&_key=8&service=blog');

        $response->assertOk()->assertJsonStructure(['message']);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && $request->url() === 'https://api.shop.test/api/posts/5/variants/8');
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'https://api.blog.test/v1/posts/5/variants/8'));
    }

    private function registerTestResource(): void
    {
        /** @var list<class-string<ResourceContract>> $resources */
        $resources = [ControllerTestPostWithManyResource::class];
        moonshine()->resources($resources);
    }

    private function resourceUri(): string
    {
        return new ControllerTestPostWithManyResource(app(CoreContract::class))->getUriKey();
    }

    private function bindShopService(): void
    {
        $service = fn (): ServiceConfig => new ServiceConfig(
            name: 'shop',
            baseUrl: 'https://api.shop.test/api',
            apiKey: 'shop-key',
            responseMapping: new ResponseMapping(
                dataKey: 'results',
                totalKey: 'count',
                perPageKey: 'page_size',
            ),
        );

        app()->forgetInstance('api-admin.service.shop');
        app()->forgetInstance('api-admin.tenant.local.service.shop');
        app()->singleton('api-admin.service.shop', $service);
        app()->singleton('api-admin.tenant.local.service.shop', $service);
    }
}

class ControllerTestPostWithManyResource extends AbstractRestResource
{
    #[Override]
    protected string $title = 'Posts';

    #[Override]
    public function getUriKey(): string
    {
        return 'controller-test-post-with-many-resource-target-service';
    }

    protected function resolveServiceConfig(): ServiceConfig
    {
        return new ServiceConfig(
            name: 'blog',
            baseUrl: 'https://api.blog.test/v1',
            apiKey: 'key',
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
            RestHasMany::make('Variants', 'variants')
                ->targetService('shop')
                ->endpoint('/posts/{parent_id}/variants')
                ->fields([
                    Text::make('Name', 'name'),
                ])
                ->fullCrud(),
        ];
    }
}
