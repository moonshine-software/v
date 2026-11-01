<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Integration;

use App\ApiAdmin\Resources\AbstractRestResource;
use App\ApiAdmin\ServiceConfig\ResponseMapping;
use App\ApiAdmin\ServiceConfig\ServiceConfig;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use MoonShine\Contracts\Core\DependencyInjection\CoreContract;
use MoonShine\Contracts\Core\TypeCasts\DataWrapperContract;
use MoonShine\Contracts\UI\FieldContract;
use MoonShine\Laravel\Providers\MoonShineServiceProvider;
use Orchestra\Testbench\TestCase;

/**
 * Regression coverage for AbstractRestResource::extractItemData.
 *
 * REST APIs commonly use different wrappers for list vs single-item
 * responses — e.g. GET /tasks returns `{"tasks": [...]}` but GET /tasks/{id}
 * returns `{"task": {...}}`. Before the singular-unwrap fallback, edit pages
 * threw 404 because the caster saw `{"task": {...}}` at the top level and
 * couldn't locate the primary key.
 */
final class RestResourceItemExtractionTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [MoonShineServiceProvider::class];
    }

    public function test_find_item_unwraps_singular_wrapper_when_data_key_absent(): void
    {
        Http::fake([
            'https://api.example.test/v1/tasks/uuid-42' => Http::response([
                'task' => [
                    'id' => 'uuid-42',
                    'title' => 'Hello',
                ],
            ]),
        ]);

        $resource = $this->makeResource(new ResponseMapping(dataKey: 'tasks'));
        $resource->setItemID('uuid-42');

        $item = $resource->findItem();

        $this->assertInstanceOf(DataWrapperContract::class, $item);
        $this->assertSame('uuid-42', $item->getKey());
        $this->assertSame('Hello', $item->toArray()['title'] ?? null);
    }

    public function test_find_item_prefers_configured_data_key_over_singular_unwrap(): void
    {
        Http::fake([
            'https://api.example.test/v1/tasks/7' => Http::response([
                'data' => [
                    'id' => 7,
                    'title' => 'Configured',
                ],
                // Extra noise so unwrap fallback can't be used as a lucky path
                'meta' => ['from_cache' => false],
            ]),
        ]);

        $resource = $this->makeResource(new ResponseMapping(dataKey: 'data'));
        $resource->setItemID(7);

        $item = $resource->findItem();

        $this->assertInstanceOf(DataWrapperContract::class, $item);
        $this->assertSame(7, $item->getKey());
        $this->assertSame('Configured', $item->toArray()['title'] ?? null);
    }

    public function test_find_item_walks_detail_data_key_dot_path(): void
    {
        // Real-world shape from a task-tracker API: GET /projects/1 returns a
        // double-nested envelope `{"resource": {"data": {...}}}` that the
        // default data_key lookup + one-key-unwrap heuristic can't peel
        // correctly — the caster ends up holding `{"data": {...}}` as the
        // item, primary_key doesn't resolve, form renders empty.
        Http::fake([
            'https://api.example.test/v1/tasks/9' => Http::response([
                'resource' => [
                    'data' => [
                        'id' => 9,
                        'title' => 'Deep envelope',
                        'client' => ['id' => 4, 'name' => 'Acme'],
                    ],
                ],
                'meta' => ['trace_id' => 'x'],
            ]),
        ]);

        $resource = $this->makeResource(new ResponseMapping(
            dataKey: 'data',
            detailDataKey: 'resource.data',
        ));
        $resource->setItemID(9);

        $item = $resource->findItem();

        $this->assertInstanceOf(DataWrapperContract::class, $item);
        $this->assertSame(9, $item->getKey());
        $this->assertSame('Deep envelope', $item->toArray()['title'] ?? null);
        $clientArr = $item->toArray()['client'] ?? null;
        $this->assertSame(4, is_array($clientArr) ? ($clientArr['id'] ?? null) : null);
    }

    public function test_find_item_falls_back_when_detail_data_key_misses(): void
    {
        // When detail_data_key is set but doesn't resolve (misconfigured or
        // the upstream occasionally returns a flatter shape), the extractor
        // logs a warning and falls back to the regular data_key lookup so
        // the page does not catastrophically fail.
        Http::fake([
            'https://api.example.test/v1/tasks/3' => Http::response([
                // detail_data_key="resource.data" misses; data_key="data" hits.
                'data' => ['id' => 3, 'title' => 'Fallback path'],
            ]),
        ]);

        $resource = $this->makeResource(new ResponseMapping(
            dataKey: 'data',
            detailDataKey: 'resource.data',
        ));
        $resource->setItemID(3);

        $item = $resource->findItem();

        $this->assertInstanceOf(DataWrapperContract::class, $item);
        $this->assertSame(3, $item->getKey());
        $this->assertSame('Fallback path', $item->toArray()['title'] ?? null);
    }

    public function test_find_item_returns_flat_body_when_no_wrapper(): void
    {
        Http::fake([
            'https://api.example.test/v1/tasks/5' => Http::response([
                'id' => 5,
                'title' => 'Flat',
            ]),
        ]);

        $resource = $this->makeResource(new ResponseMapping(dataKey: 'data'));
        $resource->setItemID(5);

        $item = $resource->findItem();

        $this->assertInstanceOf(DataWrapperContract::class, $item);
        $this->assertSame(5, $item->getKey());
        $this->assertSame('Flat', $item->toArray()['title'] ?? null);
    }

    public function test_find_item_via_list_strategy_reads_list_endpoint(): void
    {
        // Covers the "tag catalogue" pattern: the upstream exposes GET /tasks
        // (list) + POST/PUT for writes, but no per-id GET. We fetch the
        // whole list filtered by id and extract the matching row.
        Http::fake([
            'https://api.example.test/v1/tasks*' => Http::response([
                'data' => [
                    ['id' => 42, 'name' => 'forty-two'],
                ],
            ]),
        ]);

        $mapping = new ResponseMapping(
            dataKey: 'data',
            detailStrategy: 'list',
        );

        $resource = $this->makeResource($mapping);
        $resource->setItemID(42);

        $item = $resource->findItem();

        $this->assertInstanceOf(DataWrapperContract::class, $item);
        $this->assertSame(42, $item->getKey());
        $this->assertSame('forty-two', $item->toArray()['name'] ?? null);

        Http::assertSent(function (Request $r): bool {
            $path = parse_url($r->url(), PHP_URL_PATH);

            return is_string($path) && str_ends_with($path, '/tasks')
                && str_contains($r->url(), 'id=42');
        });
    }

    public function test_find_item_via_list_strategy_returns_null_on_empty_response(): void
    {
        Http::fake([
            'https://api.example.test/v1/tasks*' => Http::response(['data' => []]),
        ]);

        $resource = $this->makeResource(new ResponseMapping(
            dataKey: 'data',
            detailStrategy: 'list',
        ));
        $resource->setItemID(999);

        $this->assertNull($resource->findItem());
    }

    public function test_find_item_salvages_first_json_object_from_dirty_body(): void
    {
        // Real-world gotcha (observed on a Laravel backend):
        // Laravel returns a clean 201 with the created resource, then a
        // post-save listener fires and fails, Laravel's exception handler
        // writes the error JSON into the SAME response body. json_decode
        // sees concatenated {..}{..} and returns null → save() sees empty
        // data → MoonShine redirects to index instead of edit because
        // there's no primary_key to build the URL from. We salvage by
        // reading up to the first balanced top-level `{...}`.
        Http::fake([
            'https://api.example.test/v1/tasks/5' => Http::response(
                '{"data":{"id":5,"title":"Ok"}}{"message":"HTTP 404","exception":"..."}',
                200,
                ['Content-Type' => 'application/json'],
            ),
        ]);

        $resource = $this->makeResource(new ResponseMapping(dataKey: 'data'));
        $resource->setItemID(5);

        $item = $resource->findItem();

        $this->assertInstanceOf(DataWrapperContract::class, $item);
        $this->assertSame(5, $item->getKey());
        $this->assertSame('Ok', $item->toArray()['title'] ?? null);
    }

    public function test_find_item_does_not_unwrap_a_list_value(): void
    {
        // When the only top-level key is a list (e.g. `{"items": [...]}`),
        // the unwrap fallback must NOT treat it as the item — lists are not
        // valid item payloads, and silently collapsing a list to its first
        // element would hide real API contract bugs.
        Http::fake([
            'https://api.example.test/v1/tasks/9' => Http::response([
                'items' => [
                    ['id' => 1],
                    ['id' => 2],
                ],
            ]),
        ]);

        $resource = $this->makeResource(new ResponseMapping(dataKey: 'data'));
        $resource->setItemID(9);

        $item = $resource->findItem();

        $this->assertInstanceOf(DataWrapperContract::class, $item);
        // Unwrap skipped → caster sees the full body with `items` key. That
        // key isn't `id`, so getKey() is null. This is the safe failure mode.
        $this->assertNull($item->getKey());
    }

    private function makeResource(ResponseMapping $mapping): AbstractRestResource
    {
        $core = app(CoreContract::class);

        return new class($core, $mapping) extends AbstractRestResource
        {
            public function __construct(
                CoreContract $core,
                private readonly ResponseMapping $mapping,
            ) {
                parent::__construct($core);
            }

            protected function resolveServiceConfig(): ServiceConfig
            {
                return new ServiceConfig(
                    name: 'example',
                    baseUrl: 'https://api.example.test/v1',
                    apiKey: 'key',
                    responseMapping: $this->mapping,
                );
            }

            protected function endpoint(): string
            {
                return '/tasks';
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
