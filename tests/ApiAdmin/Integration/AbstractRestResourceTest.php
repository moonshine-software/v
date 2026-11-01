<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Integration;

use App\ApiAdmin\Resources\AbstractRestResource;
use App\ApiAdmin\ServiceConfig\RealtimeConfig;
use App\ApiAdmin\ServiceConfig\ResponseMapping;
use App\ApiAdmin\ServiceConfig\ServiceConfig;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use MoonShine\Contracts\Core\DependencyInjection\CoreContract;
use MoonShine\Contracts\Core\Paginator\PaginatorContract;
use MoonShine\Contracts\Core\TypeCasts\DataWrapperContract;
use MoonShine\Contracts\UI\FieldContract;
use MoonShine\Core\TypeCasts\MixedDataWrapper;
use MoonShine\Crud\Collections\Fields;
use MoonShine\Laravel\Providers\MoonShineServiceProvider;
use MoonShine\UI\Fields\Image;
use MoonShine\UI\Fields\Json;
use Orchestra\Testbench\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\ApiAdmin\Fixtures\MockApi\BlogApiService;

class AbstractRestResourceTest extends TestCase
{
    private AbstractRestResource $resource;

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

        $core = app(CoreContract::class);
        $this->resource = new TestBlogPostResource($core);
    }

    public function test_hosted_existing_upload_fields_are_rejected_before_shared_storage_is_touched(): void
    {
        config(['api-admin.hosted.mode' => 'hosted']);
        $fields = Fields::make([
            Json::make('Nested', 'nested')->fields([
                Image::make('Image', 'image'),
            ]),
        ]);
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Native file/image fields require trusted local mode');
        $this->resource->save(new MixedDataWrapper(['nested' => [['image' => '../tenant-b/private.jpg']]]), $fields);
    }

    public function test_http_client_has_base_url_and_auth(): void
    {
        $client = $this->resource->httpClient();

        $this->assertInstanceOf(PendingRequest::class, $client);
    }

    public function test_get_items_returns_paginated_collection(): void
    {
        $items = $this->resource->getItems();

        $this->assertNotEmpty($items);
    }

    public function test_save_creates_new_item(): void
    {
        Http::fake([
            '*' => Http::response([
                'id' => 99,
                'title' => 'New Post',
                'body' => 'Content',
            ], 201),
        ]);

        $item = new MixedDataWrapper(['title' => 'New Post', 'body' => 'Content']);

        $result = $this->resource->save($item);

        $this->assertInstanceOf(DataWrapperContract::class, $result);
        $resultArray = $result->toArray();
        // The save method applies field values and posts data, then casts the response
        $this->assertNotEmpty($resultArray, 'Result should not be empty. Got: '.json_encode($resultArray));
    }

    public function test_save_updates_existing_item(): void
    {
        Http::fake([
            '*' => Http::response([
                'id' => 1,
                'title' => 'Updated Post',
            ]),
        ]);

        $item = new MixedDataWrapper(['id' => 1, 'title' => 'Updated Post'], 1);

        $result = $this->resource->save($item);

        $this->assertInstanceOf(DataWrapperContract::class, $result);
        $resultArray = $result->toArray();
        $this->assertNotEmpty($resultArray);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
            && str_contains($request->url(), '/posts/1'));
    }

    public function test_delete_item(): void
    {
        Http::fake([
            'https://api.blog.test/v1/posts/1' => Http::response(null, 204),
        ]);

        $item = new MixedDataWrapper(['id' => 1], 1);

        $result = $this->resource->delete($item);

        $this->assertTrue($result);
    }

    public function test_realtime_refresh_metadata_uses_service_config_and_resource_endpoint(): void
    {
        $resource = new class(app(CoreContract::class)) extends AbstractRestResource
        {
            protected function resolveServiceConfig(): ServiceConfig
            {
                return new ServiceConfig(
                    name: 'Blog API',
                    baseUrl: 'https://api.blog.test/v1',
                    apiKey: '',
                    responseMapping: new ResponseMapping,
                    realtime: new RealtimeConfig(
                        enabled: true,
                        refreshDebounceMs: 1250,
                        refreshStrategy: 'notice',
                    ),
                );
            }

            protected function endpoint(): string
            {
                return '/posts/{parent_id}/comments';
            }
        };

        $metadata = $resource->realtimeRefreshMetadata('detail');

        $this->assertTrue($metadata['enabled']);
        $this->assertSame('Blog API', $metadata['service']);
        $this->assertSame('comments', $metadata['resource']);
        $this->assertSame('detail', $metadata['page']);
        $this->assertSame(['api-admin.blog-api.comments'], $metadata['channels']);
        $this->assertSame('notice', $metadata['refreshStrategy']);
        $this->assertSame(1250, $metadata['debounceMs']);
    }

    public function test_mass_delete(): void
    {
        Http::fake([
            'https://api.blog.test/v1/posts/*' => Http::response(null, 204),
        ]);

        $this->resource->massDelete([1, 2, 3]);

        Http::assertSentCount(3);
    }

    public function test_default_query_rides_every_list_and_detail_request(): void
    {
        Http::fake([
            '*' => Http::response(['data' => [['id' => 1]], 'total' => 1, 'per_page' => 25, 'current_page' => 1, 'last_page' => 1]),
        ]);

        $resource = new class(app(CoreContract::class)) extends AbstractRestResource
        {
            protected array $defaultQuery = [
                'index' => ['for_entity' => 'project'],
                'detail' => ['include' => 'meta'],
            ];

            protected function resolveServiceConfig(): ServiceConfig
            {
                return new ServiceConfig(
                    name: 'tracker',
                    baseUrl: 'https://api.tracker.test/v1',
                    apiKey: 'k',
                    responseMapping: new ResponseMapping,
                );
            }

            protected function endpoint(): string
            {
                return '/statuses';
            }

            /** @return iterable<FieldContract> */
            protected function fields(): iterable
            {
                return [];
            }
        };

        // List — for_entity must ride
        $resource->getItems();
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'for_entity=project')
            && str_contains($r->url(), '/statuses'));

        // Detail — include must ride
        Http::fake([
            '*' => Http::response(['data' => ['id' => 7]]),
        ]);
        $resource->setItemID(7);
        $resource->findItem();
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/statuses/7')
            && str_contains($r->url(), 'include=meta'));
    }

    public function test_post_index_request_sends_body_for_list_and_list_detail_strategy(): void
    {
        Http::fake([
            '*' => Http::response([
                'data' => [
                    'customers' => [
                        ['id' => 7, 'email' => 'a@example.test'],
                    ],
                ],
            ]),
        ]);

        $resource = new class(app(CoreContract::class)) extends AbstractRestResource
        {
            protected string $indexMethod = 'POST';

            protected array $indexBody = ['ids' => []];

            protected function resolveServiceConfig(): ServiceConfig
            {
                return new ServiceConfig(
                    name: 'customers',
                    baseUrl: 'https://api.customers.test/internal/v1',
                    apiKey: 'k',
                    responseMapping: new ResponseMapping(
                        dataKey: 'data.customers',
                        primaryKey: 'id',
                        paginationType: 'none',
                    ),
                );
            }

            protected function endpoint(): string
            {
                return '/customers/list';
            }

            protected function getDetailStrategyOverride(): string
            {
                return 'list';
            }

            protected function getDetailListKeyParamOverride(): string
            {
                return 'ids';
            }

            /** @return iterable<FieldContract> */
            protected function fields(): iterable
            {
                return [];
            }
        };

        $resource->getItems();

        Http::assertSent(fn (Request $r): bool => $r->method() === 'POST'
            && str_contains($r->url(), '/customers/list')
            && ($r->data()['ids'] ?? null) === []);

        Http::fake([
            '*' => Http::response([
                'data' => [
                    'customers' => [
                        ['id' => 7, 'email' => 'a@example.test'],
                    ],
                ],
            ]),
        ]);

        $resource->setItemID(7);
        $item = $resource->findItem(true);

        $this->assertSame(7, $item->toArray()['id']);
        Http::assertSent(fn (Request $r): bool => $r->method() === 'POST'
            && str_contains($r->url(), '/customers/list')
            && ($r->data()['ids'] ?? null) === [7]);
    }

    public function test_default_query_is_overridden_by_user_pagination(): void
    {
        Http::fake([
            '*' => Http::response(['data' => [], 'total' => 0, 'per_page' => 25, 'current_page' => 1, 'last_page' => 1]),
        ]);

        $resource = new class(app(CoreContract::class)) extends AbstractRestResource
        {
            protected array $defaultQuery = [
                'index' => ['per_page' => 999, 'for_entity' => 'project'],
            ];

            protected function resolveServiceConfig(): ServiceConfig
            {
                return new ServiceConfig(
                    name: 'x',
                    baseUrl: 'https://api.x.test',
                    apiKey: '',
                    responseMapping: new ResponseMapping,
                );
            }

            protected function endpoint(): string
            {
                return '/items';
            }

            /** @return iterable<FieldContract> */
            protected function fields(): iterable
            {
                return [];
            }
        };

        $resource->getItems();

        // The explicit per_page from pagination overrides the default_query
        // value (25 wins, not 999). for_entity still rides because there's
        // no user-driven value for it.
        Http::assertSent(function (Request $r): true {
            $this->assertStringContainsString('per_page=25', $r->url());
            $this->assertStringNotContainsString('per_page=999', $r->url());
            $this->assertStringContainsString('for_entity=project', $r->url());

            return true;
        });
    }

    public function test_cursor_pagination_builds_cursor_paginator(): void
    {
        Http::fake([
            '*' => Http::response([
                'data' => [
                    ['id' => 1, 'title' => 'A'],
                    ['id' => 2, 'title' => 'B'],
                ],
                'cursor' => ['next' => 'cursor-v2-opaque', 'has_next' => true],
            ]),
        ]);

        $resource = new class(app(CoreContract::class)) extends AbstractRestResource
        {
            protected function resolveServiceConfig(): ServiceConfig
            {
                return new ServiceConfig(
                    name: 'feed',
                    baseUrl: 'https://api.feed.test/v1',
                    apiKey: 'k',
                    responseMapping: new ResponseMapping(
                        dataKey: 'data',
                        paginationType: 'cursor',
                        nextCursorKey: 'cursor.next',
                        cursorQueryParam: 'cursor',
                    ),
                );
            }

            protected function endpoint(): string
            {
                return '/tasks';
            }

            /** @return iterable<FieldContract> */
            protected function fields(): iterable
            {
                return [];
            }
        };

        $paginator = $resource->getItems();

        // MoonShine's IterableComponent only recognises its own PaginatorContract
        // — if we returned Laravel's CursorPaginator here, the list renderer
        // falls back to `new Collection($paginator)` which via Arrayable
        // unwraps the envelope keys into three pseudo-rows. We therefore
        // emit MoonShine's native Paginator with simple=true + a populated
        // nextPageUrl that carries the opaque cursor token.
        $this->assertInstanceOf(PaginatorContract::class, $paginator);
        $this->assertCount(2, $paginator->getOriginalData());
        $this->assertTrue($paginator->isSimple());
        $this->assertNotNull($paginator->getNextPageUrl());
        $this->assertStringContainsString('cursor=cursor-v2-opaque', $paginator->getNextPageUrl());

        // Regression: pagination.blade.php reads `previous`/`next`/`showing`
        // etc. unconditionally from the translates array. A paginator
        // without these keys throws "Undefined array key" on render, which
        // surfaces as a toast on every list page — build-time oversight
        // we fixed by mirroring what PaginatorCaster injects.
        $translates = $paginator->getTranslates();
        foreach (['previous', 'next', 'showing', 'to', 'of', 'results'] as $key) {
            $this->assertArrayHasKey(
                $key,
                $translates,
                "cursor paginator must expose translates['{$key}'] — pagination blade reads it directly",
            );
        }
    }

    public function test_link_header_pagination_builds_paginator_with_rel_urls_and_total(): void
    {
        Http::fake([
            '*' => Http::response(
                body: ['data' => [['id' => 1], ['id' => 2]]],
                headers: [
                    'Link' => '<https://api.gh.test/v1/issues?page=1>; rel="first", <https://api.gh.test/v1/issues?page=2>; rel="next", <https://api.gh.test/v1/issues?page=7>; rel="last"',
                    'X-Total-Count' => '142',
                ],
            ),
        ]);

        $resource = new class(app(CoreContract::class)) extends AbstractRestResource
        {
            protected function resolveServiceConfig(): ServiceConfig
            {
                return new ServiceConfig(
                    name: 'gh',
                    baseUrl: 'https://api.gh.test/v1',
                    apiKey: 'k',
                    responseMapping: new ResponseMapping(
                        dataKey: 'data',
                        paginationType: 'link_header',
                        totalHeader: 'X-Total-Count',
                    ),
                );
            }

            protected function endpoint(): string
            {
                return '/issues';
            }

            /** @return iterable<FieldContract> */
            protected function fields(): iterable
            {
                return [];
            }
        };

        $paginator = $resource->getItems();

        $this->assertInstanceOf(PaginatorContract::class, $paginator);
        $this->assertCount(2, $paginator->getOriginalData());
        $this->assertFalse($paginator->isSimple(), 'total+last_page present → full paginator');
        $this->assertSame(142, $paginator->getTotal());
        $this->assertSame(7, $paginator->getLastPage());
        $this->assertSame('https://api.gh.test/v1/issues?page=2', $paginator->getNextPageUrl());
        $this->assertSame('https://api.gh.test/v1/issues?page=1', $paginator->getFirstPageUrl());
    }

    public function test_link_header_pagination_falls_back_to_simple_without_last_rel(): void
    {
        Http::fake([
            '*' => Http::response(
                body: ['data' => [['id' => 1]]],
                headers: [
                    'Link' => '<https://api.gl.test/v1/mr?page=2>; rel="next"',
                    // No X-Total — gitlab-old style.
                ],
            ),
        ]);

        $resource = new class(app(CoreContract::class)) extends AbstractRestResource
        {
            protected function resolveServiceConfig(): ServiceConfig
            {
                return new ServiceConfig(
                    name: 'gl',
                    baseUrl: 'https://api.gl.test/v1',
                    apiKey: 'k',
                    responseMapping: new ResponseMapping(
                        dataKey: 'data',
                        paginationType: 'link_header',
                        totalHeader: '',
                    ),
                );
            }

            protected function endpoint(): string
            {
                return '/mr';
            }

            /** @return iterable<FieldContract> */
            protected function fields(): iterable
            {
                return [];
            }
        };

        $paginator = $resource->getItems();

        $this->assertInstanceOf(PaginatorContract::class, $paginator);
        $this->assertTrue($paginator->isSimple(), 'missing rel="last" → simple mode');
        $this->assertNull($paginator->getTotal());
        $this->assertSame('https://api.gl.test/v1/mr?page=2', $paginator->getNextPageUrl());
    }

    public function test_list_emits_flat_filter_format_when_configured(): void
    {
        Http::fake([
            '*' => Http::response(['tasks' => []]),
        ]);

        $resource = new class(app(CoreContract::class)) extends AbstractRestResource
        {
            protected function resolveServiceConfig(): ServiceConfig
            {
                return new ServiceConfig(
                    name: 'tasks',
                    baseUrl: 'https://api.tasks.test/v1',
                    apiKey: 'k',
                    responseMapping: new ResponseMapping(
                        dataKey: 'tasks',
                        filterFormat: 'flat',
                        supportsSorting: false,
                    ),
                );
            }

            protected function endpoint(): string
            {
                return '/tasks';
            }

            /** @return iterable<FieldContract> */
            protected function fields(): iterable
            {
                return [];
            }

            /** @return array<string, mixed> */
            public function getFilterParams(): array
            {
                return ['status' => 'todo'];
            }
        };

        $resource->getItems();

        Http::assertSent(function (Request $request): true {
            $url = $request->url();

            // Flat filter encoding — key lives at top-level query
            $this->assertStringContainsString('status=todo', $url);
            // Must NOT emit the JSON:API-nested form in flat mode
            $this->assertStringNotContainsString('filter%5Bstatus%5D', $url);
            $this->assertStringNotContainsString('filter[status]', $url);
            // Supports_sorting=false must suppress sort param entirely
            $this->assertStringNotContainsString('sort=', $url);

            return true;
        });
    }

    /**
     * CQRS-style APIs often don't expose a single RESTful update endpoint —
     * each mutation has its own verb+path (e.g. PUT /tasks/{id}/assignee,
     * POST /tasks/{id}/status-transitions). Subclasses override
     * performUpdate/performCreate to route writes to the right endpoint
     * without having to rewrite all of save().
     */
    public function test_declarative_mutations_route_create_and_update(): void
    {
        // This is the happy path for a CQRS-style API (like task-manager)
        // configured entirely through MCP — no custom Resource code.
        Http::fake([
            'https://api.tasks.test/v1/tasks/42/status-transitions' => Http::response(['ok' => true]),
            'https://api.tasks.test/v1/tasks/42/assignee' => Http::response(['ok' => true]),
            'https://api.tasks.test/v1/tasks/42' => Http::response([
                'task' => ['id' => 42, 'title' => 'after-update', 'status' => 'done', 'assignee_id' => 'alice'],
            ]),
            'https://api.tasks.test/v1/tasks' => Http::response([
                'task' => ['id' => 99, 'title' => 'new', 'description' => 'd'],
            ], 201),
        ]);

        $resource = new class(app(CoreContract::class)) extends AbstractRestResource
        {
            protected array $mutations = [
                'create' => [
                    'method' => 'POST',
                    'path' => '/tasks',
                    'body_from' => ['title', 'description'],
                ],
                'update' => [
                    [
                        'method' => 'POST',
                        'path' => '/tasks/{id}/status-transitions',
                        'body' => ['target_status' => '{status}'],
                        'when_present' => 'status',
                        'ignore_status' => [409],
                    ],
                    [
                        'method' => 'PUT',
                        'path' => '/tasks/{id}/assignee',
                        'body' => ['assignee_id' => '{assignee_id}'],
                        'when_present' => 'assignee_id',
                    ],
                ],
            ];

            protected function resolveServiceConfig(): ServiceConfig
            {
                return new ServiceConfig(
                    name: 'tasks',
                    baseUrl: 'https://api.tasks.test/v1',
                    apiKey: 'k',
                    responseMapping: new ResponseMapping(dataKey: 'tasks'),
                );
            }

            protected function endpoint(): string
            {
                return '/tasks';
            }

            /** @return iterable<FieldContract> */
            protected function fields(): iterable
            {
                return [];
            }
        };

        // --- CREATE path --------------------------------------------------
        $new = new MixedDataWrapper(
            ['title' => 'new', 'description' => 'd', 'status' => 'todo'],
        );
        $created = $resource->save($new);

        $this->assertInstanceOf(DataWrapperContract::class, $created);
        Http::assertSent(function (Request $request): bool {
            if ($request->method() !== 'POST' || ! str_ends_with($request->url(), '/tasks')) {
                return false;
            }
            $body = $request->data();
            // body_from restricts payload: status must NOT be sent on create
            $this->assertArrayHasKey('title', $body);
            $this->assertArrayHasKey('description', $body);
            $this->assertArrayNotHasKey('status', $body);

            return true;
        });

        // --- UPDATE path: both mutations dispatched, then GET -------------
        Http::fake([
            'https://api.tasks.test/v1/tasks/42/status-transitions' => Http::response(['ok' => true]),
            'https://api.tasks.test/v1/tasks/42/assignee' => Http::response(['ok' => true]),
            'https://api.tasks.test/v1/tasks/42' => Http::response([
                'task' => ['id' => 42, 'title' => 'after', 'status' => 'done', 'assignee_id' => 'alice'],
            ]),
        ]);

        $existing = new MixedDataWrapper(
            ['id' => 42, 'status' => 'done', 'assignee_id' => 'alice'],
            42,
        );
        $updated = $resource->save($existing);

        $this->assertSame(42, $updated->getKey());
        Http::assertSent(fn (Request $r): bool => $r->method() === 'POST'
            && str_contains($r->url(), '/tasks/42/status-transitions')
            && $r->data() === ['target_status' => 'done']);
        Http::assertSent(fn (Request $r): bool => $r->method() === 'PUT'
            && str_contains($r->url(), '/tasks/42/assignee')
            && $r->data() === ['assignee_id' => 'alice']);
        Http::assertSent(fn (Request $r): bool => $r->method() === 'GET'
            && str_ends_with($r->url(), '/tasks/42'));
    }

    public function test_declarative_update_skips_mutation_when_column_absent(): void
    {
        Http::fake([
            'https://api.tasks.test/v1/tasks/7/assignee' => Http::response(['ok' => true]),
            'https://api.tasks.test/v1/tasks/7' => Http::response(['task' => ['id' => 7]]),
        ]);

        $resource = new class(app(CoreContract::class)) extends AbstractRestResource
        {
            protected array $mutations = [
                'update' => [
                    [
                        'method' => 'POST',
                        'path' => '/tasks/{id}/status-transitions',
                        'body' => ['target_status' => '{status}'],
                        'when_present' => 'status',
                    ],
                    [
                        'method' => 'PUT',
                        'path' => '/tasks/{id}/assignee',
                        'body' => ['assignee_id' => '{assignee_id}'],
                        'when_present' => 'assignee_id',
                    ],
                ],
            ];

            protected function resolveServiceConfig(): ServiceConfig
            {
                return new ServiceConfig(
                    name: 'tasks',
                    baseUrl: 'https://api.tasks.test/v1',
                    apiKey: 'k',
                    responseMapping: new ResponseMapping(dataKey: 'tasks'),
                );
            }

            protected function endpoint(): string
            {
                return '/tasks';
            }

            /** @return iterable<FieldContract> */
            protected function fields(): iterable
            {
                return [];
            }
        };

        $item = new MixedDataWrapper(['id' => 7, 'assignee_id' => 'bob'], 7);
        $resource->save($item);

        Http::assertSent(fn (Request $r): bool => $r->method() === 'PUT'
            && str_contains($r->url(), '/tasks/7/assignee'));
        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), 'status-transitions'));
    }

    public function test_save_dispatches_through_perform_update_hook(): void
    {
        Http::fake([
            '*tasks/42/assignee' => Http::response([
                'task' => ['id' => 42, 'title' => 'routed'],
            ]),
        ]);

        $resource = new class(app(CoreContract::class)) extends AbstractRestResource
        {
            protected function resolveServiceConfig(): ServiceConfig
            {
                return new ServiceConfig(
                    name: 'tasks',
                    baseUrl: 'https://api.tasks.test/v1',
                    apiKey: 'k',
                    responseMapping: new ResponseMapping,
                );
            }

            protected function endpoint(): string
            {
                return '/tasks';
            }

            /** @return iterable<FieldContract> */
            protected function fields(): iterable
            {
                return [];
            }

            protected function performUpdate(int|string $key, array $data): Response
            {
                return $this->httpClient()->put("/tasks/{$key}/assignee", $data);
            }
        };

        $item = new MixedDataWrapper(['id' => 42, 'assignee_id' => 'alice'], 42);

        $result = $resource->save($item);

        $this->assertInstanceOf(DataWrapperContract::class, $result);
        Http::assertSent(
            fn (Request $request): bool => $request->method() === 'PUT'
                && str_contains($request->url(), '/tasks/42/assignee'),
        );
    }

    public function test_delete_without_key_returns_false(): void
    {
        $item = new MixedDataWrapper([]);

        $result = $this->resource->delete($item);

        $this->assertFalse($result);
    }

    /**
     * Regression: list-only catalogues (upstream
     * `Route::resource(...)->only(['index','store','update','destroy'])`
     * — no show route) blew up after save when the resource routed updates
     * through declarative mutations. performUpdate used to do a raw
     * `GET /{id}` for canonical state, ignoring the resource-level
     * detail_strategy override, and upstream returned 405. The refetch
     * must use the same list-filter routing as findItem().
     */
    public function test_save_with_mutations_refetch_honours_list_detail_strategy_override(): void
    {
        Http::fake([
            // PATCH mutation succeeds.
            'https://api.tracker.test/v1/tags/1' => Http::response(['ok' => true]),
            // List endpoint used for refetch (list-strategy refetch).
            'https://api.tracker.test/v1/tags*' => Http::response([
                'data' => [
                    ['id' => 1, 'name' => 'after'],
                ],
            ]),
        ]);

        $resource = new class(app(CoreContract::class)) extends AbstractRestResource
        {
            protected array $mutations = [
                'update' => [
                    [
                        'method' => 'PATCH',
                        'path' => '/tags/{id}',
                        'body_from' => ['name'],
                    ],
                ],
            ];

            protected function resolveServiceConfig(): ServiceConfig
            {
                return new ServiceConfig(
                    name: 'tracker',
                    baseUrl: 'https://api.tracker.test/v1',
                    apiKey: 'k',
                    responseMapping: new ResponseMapping(dataKey: 'data'),
                );
            }

            protected function endpoint(): string
            {
                return '/tags';
            }

            protected function getDetailStrategyOverride(): string
            {
                return 'list';
            }

            protected function getDetailListKeyParamOverride(): string
            {
                return 'id';
            }

            /** @return iterable<FieldContract> */
            protected function fields(): iterable
            {
                return [];
            }
        };

        $item = new MixedDataWrapper(['id' => 1, 'name' => 'after'], 1);
        $result = $resource->save($item);

        $this->assertSame(1, $result->getKey());

        // The mutation itself still goes to PATCH /tags/1.
        Http::assertSent(
            fn (Request $r): bool => $r->method() === 'PATCH' && str_ends_with($r->url(), '/tags/1'),
        );

        // Refetch uses the list endpoint + detail_list_key_param override,
        // NOT a raw GET /tags/1 (which upstream doesn't support).
        Http::assertSent(fn (Request $r): bool => $r->method() === 'GET'
            && str_contains($r->url(), '/tags?')
            && str_contains($r->url(), 'id=1'));
        Http::assertNotSent(
            fn (Request $r): bool => $r->method() === 'GET' && str_ends_with($r->url(), '/tags/1'),
        );
    }

    /**
     * Sanity-check the refactor: when detail_strategy stays at the default
     * ("endpoint"), mutations-powered updates still refetch via
     * GET /{id} — same behaviour as before, just plumbed through save()
     * instead of performUpdate.
     */
    public function test_save_with_mutations_refetch_uses_endpoint_strategy_by_default(): void
    {
        Http::fake([
            'https://api.tasks.test/v1/tasks/7/assignee' => Http::response(['ok' => true]),
            'https://api.tasks.test/v1/tasks/7' => Http::response([
                'task' => ['id' => 7, 'assignee_id' => 'bob'],
            ]),
        ]);

        $resource = new class(app(CoreContract::class)) extends AbstractRestResource
        {
            protected array $mutations = [
                'update' => [
                    [
                        'method' => 'PUT',
                        'path' => '/tasks/{id}/assignee',
                        'body' => ['assignee_id' => '{assignee_id}'],
                    ],
                ],
            ];

            protected function resolveServiceConfig(): ServiceConfig
            {
                return new ServiceConfig(
                    name: 'tasks',
                    baseUrl: 'https://api.tasks.test/v1',
                    apiKey: 'k',
                    responseMapping: new ResponseMapping(dataKey: 'tasks'),
                );
            }

            protected function endpoint(): string
            {
                return '/tasks';
            }

            /** @return iterable<FieldContract> */
            protected function fields(): iterable
            {
                return [];
            }
        };

        $item = new MixedDataWrapper(['id' => 7, 'assignee_id' => 'bob'], 7);
        $result = $resource->save($item);

        $this->assertSame(7, $result->getKey());

        Http::assertSent(
            fn (Request $r): bool => $r->method() === 'GET' && str_ends_with($r->url(), '/tasks/7'),
        );
    }
}

/**
 * Concrete test resource extending AbstractRestResource.
 */
class TestBlogPostResource extends AbstractRestResource
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
    protected function fields(): iterable
    {
        return [];
    }
}
