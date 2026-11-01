<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Integration;

use App\ApiAdmin\Resources\AbstractRestResource;
use App\ApiAdmin\ServiceConfig\ResponseMapping;
use App\ApiAdmin\ServiceConfig\ServiceConfig;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use MoonShine\Contracts\Core\DependencyInjection\CoreContract;
use MoonShine\Contracts\UI\FieldContract;
use MoonShine\Laravel\Providers\MoonShineServiceProvider;
use Orchestra\Testbench\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Covers {@see AbstractRestResource::createItem()},
 * {@see AbstractRestResource::updateItem()}, and
 * {@see AbstractRestResource::deleteItem()} — the MCP/API-shim pathway
 * that bypasses the UI form pipeline but still honours `mutations.*`
 * routing.
 */
final class AbstractRestResourceCrudTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            MoonShineServiceProvider::class,
        ];
    }

    private function makeResource(): CrudTestPostResource
    {
        return new CrudTestPostResource(app(CoreContract::class));
    }

    public function test_create_item_posts_payload_and_returns_upstream_body(): void
    {
        Http::fake([
            'https://api.blog.test/v1/posts' => Http::response([
                'id' => 42,
                'title' => 'Fresh',
                'body' => 'Ink',
            ], 201),
        ]);

        $result = $this->makeResource()->createItem([
            'title' => 'Fresh',
            'body' => 'Ink',
        ]);

        $this->assertSame(42, $result['id']);
        $this->assertSame('Fresh', $result['title']);

        Http::assertSent(fn (Request $r): bool => $r->method() === 'POST'
            && str_contains($r->url(), '/posts')
            && ! str_contains($r->url(), '/posts/'));
    }

    public function test_create_item_guarantees_primary_key_on_empty_response(): void
    {
        // 201 Created with empty body happens with some APIs (Laravel
        // accepts {} as "accepted"). normalizeCrudItem has nothing to
        // fall back on for create (no pre-existing id) — with an empty
        // upstream body we surface an empty array rather than synthesise
        // a fake id. This test pins that non-behaviour.
        Http::fake([
            'https://api.blog.test/v1/posts' => Http::response([], 201),
        ]);

        $result = $this->makeResource()->createItem(['title' => 'x']);

        $this->assertSame([], $result);
    }

    public function test_update_item_puts_payload_when_no_mutations(): void
    {
        Http::fake([
            'https://api.blog.test/v1/posts/7' => Http::sequence()
                ->push(['id' => 7, 'title' => 'Old'], 200) // findItem pre-check
                ->push(['id' => 7, 'title' => 'New'], 200), // PUT
        ]);

        $result = $this->makeResource()->updateItem(7, ['title' => 'New']);

        $this->assertSame(7, $result['id']);
        $this->assertSame('New', $result['title']);

        Http::assertSent(fn (Request $r): bool => $r->method() === 'PUT'
            && str_contains($r->url(), '/posts/7'));
    }

    public function test_update_item_propagates_not_found_when_precheck_fails(): void
    {
        Http::fake([
            'https://api.blog.test/v1/posts/999' => Http::response(null, 404),
        ]);

        $this->expectException(NotFoundHttpException::class);

        $this->makeResource()->updateItem(999, ['title' => 'x']);
    }

    public function test_update_item_honours_mutations_split_with_when_present(): void
    {
        // /posts/5 is hit twice: findItem pre-check + post-update canonical
        // refetch via fetchCanonicalItemData(). Use a sequence so both
        // calls get a real response.
        Http::fake([
            'https://api.blog.test/v1/posts/5' => Http::sequence()
                ->push(['id' => 5, 'status' => 'todo', 'title' => 'Keep'], 200)
                ->push(['id' => 5, 'status' => 'done', 'title' => 'Keep'], 200),
            'https://api.blog.test/v1/posts/5/core' => Http::response(null, 204),
            'https://api.blog.test/v1/posts/5/status' => Http::response(null, 204),
        ]);

        $resource = new CrudTestPostResource(app(CoreContract::class), [
            'update' => [
                ['method' => 'patch', 'path' => '/posts/{id}/core'],
                [
                    'method' => 'patch',
                    'path' => '/posts/{id}/status',
                    'when_present' => 'status',
                ],
            ],
        ]);

        // Payload WITH status — both mutations must fire.
        $resource->updateItem(5, ['title' => 'Keep', 'status' => 'done']);

        Http::assertSent(fn (Request $r): bool => $r->method() === 'PATCH' && str_contains($r->url(), '/posts/5/core'));
        Http::assertSent(fn (Request $r): bool => $r->method() === 'PATCH' && str_contains($r->url(), '/posts/5/status'));
    }

    public function test_update_item_with_when_present_skips_mutation_when_key_absent(): void
    {
        Http::fake([
            'https://api.blog.test/v1/posts/5' => Http::sequence()
                ->push(['id' => 5, 'status' => 'todo', 'title' => 'Keep'], 200)
                ->push(['id' => 5, 'status' => 'todo', 'title' => 'Keep2'], 200),
            'https://api.blog.test/v1/posts/5/core' => Http::response(null, 204),
            'https://api.blog.test/v1/posts/5/status' => Http::response(null, 204),
        ]);

        $resource = new CrudTestPostResource(app(CoreContract::class), [
            'update' => [
                ['method' => 'patch', 'path' => '/posts/{id}/core'],
                [
                    'method' => 'patch',
                    'path' => '/posts/{id}/status',
                    'when_present' => 'status',
                ],
            ],
        ]);

        // Payload WITHOUT status — status endpoint must stay quiet.
        $resource->updateItem(5, ['title' => 'Keep2']);

        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), '/posts/5/status'));
    }

    public function test_delete_item_precheck_then_delete(): void
    {
        Http::fake([
            'https://api.blog.test/v1/posts/3' => Http::sequence()
                ->push(['id' => 3, 'title' => 'x'], 200) // findItem pre-check
                ->push(null, 204), // DELETE
        ]);

        $this->makeResource()->deleteItem(3);

        Http::assertSent(fn (Request $r): bool => $r->method() === 'DELETE' && str_contains($r->url(), '/posts/3'));
    }

    public function test_delete_item_not_found_surfaces_as_symfony_not_found(): void
    {
        Http::fake([
            'https://api.blog.test/v1/posts/404' => Http::response(null, 404),
        ]);

        $this->expectException(NotFoundHttpException::class);

        $this->makeResource()->deleteItem(404);
    }

    public function test_create_item_upstream_500_propagates_via_request_exception(): void
    {
        Http::fake([
            'https://api.blog.test/v1/posts' => Http::response(['error' => 'boom'], 500),
        ]);

        $this->expectException(RequestException::class);

        $this->makeResource()->createItem(['title' => 'boom']);
    }
}

/**
 * Concrete fixture resource with optional declarative mutations so we
 * can exercise the mutations-split update path without touching the
 * broader regression suite.
 */
class CrudTestPostResource extends AbstractRestResource
{
    /**
     * @param array<string, mixed> $mutations
     */
    public function __construct(CoreContract $core, array $mutations = [])
    {
        parent::__construct($core);
        $this->mutations = $mutations;
    }

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
}
