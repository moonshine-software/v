<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Integration;

use App\ApiAdmin\Fields\RestHasOne;
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
use MoonShine\UI\Fields\Textarea;
use Orchestra\Testbench\TestCase;
use Override;

/**
 * Tests HTTP endpoints that power RestHasOne CRUD:
 *   POST   /rest-has-one/store
 *   PUT    /rest-has-one/update
 *   DELETE /rest-has-one/destroy
 */
class RestHasOneControllerTest extends TestCase
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

        // Controllers use the 'web' middleware group; skip CSRF verification in tests.
        $this->withoutMiddleware(VerifyCsrfToken::class);

        $this->bindShopService();
        $this->registerTestResource();
    }

    public function test_store_posts_to_parent_scoped_endpoint(): void
    {
        Http::fake(['*' => Http::response(['data' => ['id' => 1, 'name' => 'New']], 201)]);

        $response = $this->post('/rest-has-one/store', [
            '_resource' => $this->resourceUri(),
            '_relation' => 'author',
            '_parent_id' => 5,
            'name' => 'New Author',
            'email' => 'new@example.com',
        ]);

        $response->assertOk()->assertJsonStructure(['message']);
        Http::assertSent(fn (Request $req): bool => $req->method() === 'POST' && str_contains($req->url(), '/posts/5/author'));
    }

    public function test_update_sends_put_to_parent_scoped_endpoint(): void
    {
        Http::fake(['*' => Http::response(['data' => ['id' => 1, 'name' => 'Updated']])]);

        $response = $this->put('/rest-has-one/update?_resource='.$this->resourceUri().'&_relation=author&_parent_id=3', [
            'name' => 'Updated Author',
            'email' => 'u@example.com',
        ]);

        $response->assertOk()->assertJsonStructure(['message']);
        Http::assertSent(fn (Request $req): bool => $req->method() === 'PUT' && str_contains($req->url(), '/posts/3/author'));
    }

    public function test_destroy_sends_delete_to_parent_scoped_endpoint(): void
    {
        Http::fake(['*' => Http::response(null, 204)]);

        $response = $this->delete('/rest-has-one/destroy?_resource='.$this->resourceUri().'&_relation=author&_parent_id=7');

        $response->assertOk()->assertJsonStructure(['message']);
        Http::assertSent(fn (Request $req): bool => $req->method() === 'DELETE' && str_contains($req->url(), '/posts/7/author'));
    }

    public function test_store_strips_internal_params_from_payload(): void
    {
        Http::fake(['*' => Http::response(['data' => ['id' => 1]], 201)]);

        $this->post('/rest-has-one/store', [
            '_resource' => $this->resourceUri(),
            '_relation' => 'author',
            '_parent_id' => 1,
            '_token' => 'csrf',
            '_method' => 'POST',
            'name' => 'X',
        ]);

        Http::assertSent(function (Request $req): bool {
            $decoded = json_decode($req->body(), true);
            /** @var array<string, mixed> $body */
            $body = is_array($decoded) ? $decoded : [];

            return ! isset($body['_resource'])
                && ! isset($body['_relation'])
                && ! isset($body['_parent_id'])
                && ! isset($body['_token'])
                && ! isset($body['_method'])
                && ($body['name'] ?? null) === 'X';
        });
    }

    public function test_store_returns_500_when_upstream_fails(): void
    {
        Http::fake(['*' => Http::response(null, 500)]);

        $response = $this->post('/rest-has-one/store', [
            '_resource' => $this->resourceUri(),
            '_relation' => 'author',
            '_parent_id' => 1,
            'name' => 'X',
        ]);

        $response->assertStatus(500);
    }

    public function test_update_returns_500_when_upstream_fails(): void
    {
        Http::fake(['*' => Http::response(null, 500)]);

        $response = $this->put('/rest-has-one/update?_resource='.$this->resourceUri().'&_relation=author&_parent_id=1', [
            'name' => 'x',
        ]);

        $response->assertStatus(500);
    }

    public function test_destroy_returns_500_when_upstream_fails(): void
    {
        Http::fake(['*' => Http::response('err', 500)]);

        $response = $this->delete('/rest-has-one/destroy?_resource='.$this->resourceUri().'&_relation=author&_parent_id=1');

        $response->assertStatus(500);
    }

    public function test_store_returns_404_when_resource_unknown(): void
    {
        $response = $this->post('/rest-has-one/store', [
            '_resource' => 'unknown-uri',
            '_relation' => 'author',
            '_parent_id' => 1,
            'name' => 'X',
        ]);

        $response->assertNotFound();
    }

    public function test_store_returns_404_when_relation_not_found_on_resource(): void
    {
        $response = $this->post('/rest-has-one/store', [
            '_resource' => $this->resourceUri(),
            '_relation' => 'nonexistent',
            '_parent_id' => 1,
            'name' => 'X',
        ]);

        $response->assertNotFound();
    }

    public function test_store_uses_field_target_service_over_request_service(): void
    {
        Http::fake([
            'https://api.blog.test/v1/posts/5/shop-author' => Http::response(['data' => ['id' => 9, 'name' => 'Wrong']]),
            'https://api.shop.test/api/posts/5/shop-author' => Http::response(['results' => ['id' => 1, 'name' => 'New']], 201),
        ]);

        $response = $this->post('/rest-has-one/store', [
            '_resource' => $this->resourceUri(),
            '_relation' => 'shop_author',
            '_parent_id' => 5,
            'service' => 'blog',
            'name' => 'New Author',
        ]);

        $response->assertOk()->assertJsonStructure(['message']);
        Http::assertSent(fn (Request $req): bool => $req->method() === 'POST'
            && $req->url() === 'https://api.shop.test/api/posts/5/shop-author');
        Http::assertNotSent(fn (Request $req): bool => str_contains($req->url(), 'https://api.blog.test/v1/posts/5/shop-author'));
    }

    public function test_update_uses_field_target_service_over_request_service(): void
    {
        Http::fake([
            'https://api.blog.test/v1/posts/5/shop-author' => Http::response(['data' => ['id' => 9, 'name' => 'Wrong']]),
            'https://api.shop.test/api/posts/5/shop-author' => Http::response(['results' => ['id' => 1, 'name' => 'Updated']]),
        ]);

        $response = $this->put('/rest-has-one/update?_resource='.$this->resourceUri().'&_relation=shop_author&_parent_id=5&service=blog', [
            'name' => 'Updated Author',
        ]);

        $response->assertOk()->assertJsonStructure(['message']);
        Http::assertSent(fn (Request $req): bool => $req->method() === 'PUT'
            && $req->url() === 'https://api.shop.test/api/posts/5/shop-author');
        Http::assertNotSent(fn (Request $req): bool => str_contains($req->url(), 'https://api.blog.test/v1/posts/5/shop-author'));
    }

    public function test_destroy_uses_field_target_service_over_request_service(): void
    {
        Http::fake([
            'https://api.blog.test/v1/posts/5/shop-author' => Http::response(null, 204),
            'https://api.shop.test/api/posts/5/shop-author' => Http::response(null, 204),
        ]);

        $response = $this->delete('/rest-has-one/destroy?_resource='.$this->resourceUri().'&_relation=shop_author&_parent_id=5&service=blog');

        $response->assertOk()->assertJsonStructure(['message']);
        Http::assertSent(fn (Request $req): bool => $req->method() === 'DELETE'
            && $req->url() === 'https://api.shop.test/api/posts/5/shop-author');
        Http::assertNotSent(fn (Request $req): bool => str_contains($req->url(), 'https://api.blog.test/v1/posts/5/shop-author'));
    }

    // ── Helpers ──────────────────────────────────────────────────────

    private function registerTestResource(): void
    {
        /** @var list<class-string<ResourceContract>> $resources */
        $resources = [ControllerTestPostResource::class];
        moonshine()->resources($resources);
    }

    private function resourceUri(): string
    {
        return new ControllerTestPostResource(app(CoreContract::class))->getUriKey();
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

/**
 * Stub resource with a RestHasOne field for controller tests.
 */
class ControllerTestPostResource extends AbstractRestResource
{
    #[Override]
    protected string $title = 'Posts';

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
            RestHasOne::make('Author', 'author')
                ->endpoint('/posts/{parent_id}/author')
                ->fields([
                    Text::make('Name', 'name'),
                    Text::make('Email', 'email'),
                    Textarea::make('Bio', 'bio'),
                ])
                ->creatable()
                ->editable()
                ->removable(),
            RestHasOne::make('Shop Author', 'shop_author')
                ->targetService('shop')
                ->endpoint('/posts/{parent_id}/shop-author')
                ->fields([
                    Text::make('Name', 'name'),
                ])
                ->fullCrud(),
        ];
    }
}
