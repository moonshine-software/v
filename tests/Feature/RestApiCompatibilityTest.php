<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\ApiAdmin\Instructor\InstructorDiscoveryService;
use App\ApiAdmin\MCP\ResourceRequestContext;
use App\ApiAdmin\MCP\Tools\ProbeEndpointTool;
use App\ApiAdmin\MCP\Tools\RegisterServiceTool;
use App\ApiAdmin\Resources\AbstractRestResource;
use App\ApiAdmin\ServiceConfig\ResponseMapping;
use App\ApiAdmin\ServiceConfig\ServiceConfig;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Request as BrowserRequest;
use Illuminate\Support\Facades\Http;
use MoonShine\Contracts\Core\DependencyInjection\CoreContract;
use MoonShine\Contracts\Core\Paginator\PaginatorContract;
use MoonShine\Core\Paginator\Paginator;
use MoonShine\Crud\Collections\Fields;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

final class RestApiCompatibilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    /** @return iterable<string, array{list<array{id: int}>}> */
    public static function mismatchedLists(): iterable
    {
        yield 'one wrong record' => [[['id' => 1]]];
        yield 'unfiltered list' => [[['id' => 1], ['id' => 2]]];
    }

    /** @param list<array{id: int}> $items */
    #[DataProvider('mismatchedLists')]
    public function test_a_missing_list_record_cannot_be_saved_as_another_record(array $items): void
    {
        Http::fake(['*' => Http::response(['data' => $items])]);
        $resource = $this->resource(new ResponseMapping(detailStrategy: 'list'));
        $resource->setItemID(999);
        $this->assertNull($resource->findItem());

        try {
            $item = $resource->findItem(orFail: true);
            $resource->save($item, new Fields([]));
            $this->fail('A mismatched record was accepted.');
        } catch (NotFoundHttpException) {
            Http::assertSentCount(2);
            Http::assertNotSent(static fn (Request $request): bool => $request->method() !== 'GET');
        }
    }

    public function test_list_lookup_finds_a_matching_record_after_other_records(): void
    {
        Http::fake(['*' => Http::response(['data' => [['id' => 1], ['id' => 42]]])]);
        $resource = $this->resource(new ResponseMapping(detailStrategy: 'list'));
        $resource->setItemID(42);

        $this->assertSame(42, $resource->findItem(orFail: true)->getKey());
    }

    /** @return iterable<string, array{int, ?string, ?string, list<array{id: int}>}> */
    public static function djangoPages(): iterable
    {
        yield 'first' => [1, null, 'https://upstream.test/items?page=2', [['id' => 1], ['id' => 2]]];
        yield 'middle' => [2, 'https://upstream.test/items?page=1', 'https://upstream.test/items?page=3', [['id' => 3]]];
        yield 'last' => [3, 'https://upstream.test/items?page=2', null, [['id' => 4]]];
        yield 'empty' => [1, null, null, []];
    }

    /** @param list<array{id: int}> $items */
    #[DataProvider('djangoPages')]
    public function test_django_navigation_works_without_invented_page_metadata(int $page, ?string $previous, ?string $next, array $items): void
    {
        Http::fake(['*' => Http::response(['count' => 4, 'next' => $next, 'previous' => $previous, 'results' => $items])]);
        request()->query->replace(['_component_name' => 'items', 'sort' => 'id']);
        $resource = $this->resource(ResponseMapping::fromArray(RegisterServiceTool::RESPONSE_FORMAT_PRESETS['django']));
        $paginator = ResourceRequestContext::seed($resource, ['page' => $page, 'per_page' => 25], static fn ($resource) => $resource->getItems());

        $this->assertInstanceOf(Paginator::class, $paginator);
        $this->assertSame($page, $paginator->getCurrentPage());
        $this->assertSame(4, $paginator->getTotal());
        $this->assertNull($paginator->getLastPage());
        $this->assertTrue($paginator->isSimple());
        $this->assertSame($next === null ? null : request()->url().'?_component_name=items&sort=id&page='.($page + 1), $paginator->getNextPageUrl());
        $this->assertSame($previous === null ? null : request()->url().'?_component_name=items&sort=id&page='.($page - 1), $paginator->getPrevPageUrl());
        $this->assertSame($items, $paginator->getOriginalData()->all());
        $this->assertStringNotContainsString('https://upstream.test', (string) $paginator);
        $paginator->setPath('https://panel.test/component');
        $this->assertSame($next === null ? null : 'https://panel.test/component?_component_name=items&sort=id&page='.($page + 1), $paginator->getNextPageUrl());
        Http::assertSent(static fn (Request $request): bool => $request['page'] === $page && $request['page_size'] === 25);
    }

    public function test_spring_pages_translate_both_directions_and_keep_request_context_fresh(): void
    {
        Http::fake(static fn (Request $request) => Http::response([
            'content' => [['id' => 7]], 'totalElements' => 60, 'size' => 20, 'number' => $request['page'], 'totalPages' => 3,
        ]));
        $mapping = ResponseMapping::fromArray([
            'data_key' => 'content', 'total_key' => 'totalElements', 'per_page_key' => 'size',
            'current_page_key' => 'number', 'last_page_key' => 'totalPages',
            'per_page_param_name' => 'size', 'zero_based_pages' => true,
        ]);
        $resource = $this->resource(ServiceConfig::fromArray($this->service($mapping)->toArray())->responseMapping);

        foreach ([1, 2, 3] as $page) {
            $paginator = ResourceRequestContext::seed($resource, ['page' => $page, 'per_page' => 20], static fn ($resource) => $resource->getItems());
            $this->assertInstanceOf(PaginatorContract::class, $paginator);
            $this->assertSame($page, $paginator->getCurrentPage());
            $this->assertSame(3, $paginator->getLastPage());
            $this->assertSame($page === 3 ? null : request()->url().'?page='.($page + 1), $paginator->getNextPageUrl());
            Http::assertSent(static fn (Request $request): bool => $request['page'] === $page - 1
                && $request['size'] === 20 && ! isset($request['per_page']));
        }
    }

    /** @return iterable<string, array{int, bool}> */
    public static function linkHeaderPages(): iterable
    {
        foreach ([false, true] as $withLastPage) {
            foreach ([1, 2, 3] as $page) {
                yield ($withLastPage ? 'numbered' : 'simple').' page '.$page => [$page, $withLastPage];
            }
        }
    }

    #[DataProvider('linkHeaderPages')]
    public function test_link_header_navigation_stays_in_the_panel_and_preserves_request_context(int $page, bool $withLastPage): void
    {
        $links = ['<https://upstream.test/items?page=1&api_key=upstream-secret>; rel="first"'];
        if ($page > 1) {
            $links[] = '<https://upstream.test/items?page='.($page - 1).'&api_key=upstream-secret>; rel="prev"';
        }
        if ($page < 3) {
            $links[] = '<https://upstream.test/items?page='.($page + 1).'&api_key=upstream-secret>; rel="next"';
        }
        if ($withLastPage) {
            $links[] = '<https://upstream.test/items?page=3&api_key=upstream-secret>; rel="last"';
        }
        Http::fake(['*' => Http::response(['data' => [['id' => $page]]], headers: ['Link' => implode(', ', $links), 'X-Total-Count' => '3'])]);
        $context = ['sort' => '-id', 'search' => 'some words', 'filter' => ['status' => 'published'], '_component_name' => 'items'];
        app()->instance('request', BrowserRequest::create('https://panel.test/admin/items', parameters: [...$context, 'page' => $page]));
        $mapping = new ResponseMapping(paginationType: 'link_header', totalHeader: 'X-Total-Count');
        $paginator = ResourceRequestContext::seed($this->resource($mapping), ['page' => $page], static fn ($resource) => $resource->getItems());

        $this->assertInstanceOf(Paginator::class, $paginator);
        $this->assertSame(! $withLastPage, $paginator->isSimple());
        $this->assertSame($page, $paginator->getCurrentPage());
        $this->assertSame($withLastPage ? 3 : null, $paginator->getLastPage());
        $this->assertSame(3, $paginator->getTotal());
        $this->assertSame($page === 3 ? null : request()->fullUrlWithQuery(['page' => $page + 1]), $paginator->getNextPageUrl());
        $this->assertSame($page === 1 ? null : request()->fullUrlWithQuery(['page' => $page - 1]), $paginator->getPrevPageUrl());
        $this->assertSame(request()->fullUrlWithQuery(['page' => 1]), $paginator->getFirstPageUrl());
        $this->assertSame($withLastPage ? request()->fullUrlWithQuery(['page' => 3]) : null, $paginator->getLastPageUrl());
        $this->assertStringNotContainsString('upstream.test', (string) $paginator);
        $this->assertStringNotContainsString('upstream-secret', (string) $paginator);
        $paginator->setPath('https://panel.test/component');
        if ($page < 3) {
            $nextUrl = $paginator->getNextPageUrl();
            $this->assertNotNull($nextUrl);
            $this->assertStringStartsWith('https://panel.test/component?', $nextUrl);
            parse_str((string) parse_url($nextUrl, PHP_URL_QUERY), $query);
            $this->assertSame([...$context, 'page' => (string) ($page + 1)], $query);
        }
        Http::assertSent(static fn (Request $request): bool => $request['page'] === $page);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidLinkPages(): iterable
    {
        yield 'missing' => ['https://upstream.test/items'];
        yield 'array' => ['https://upstream.test/items?page[]=2'];
        yield 'zero' => ['https://upstream.test/items?page=0'];
        yield 'negative' => ['https://upstream.test/items?page=-1'];
        yield 'not a number' => ['https://upstream.test/items?page=invalid'];
    }

    #[DataProvider('invalidLinkPages')]
    public function test_invalid_link_header_pages_do_not_render_external_navigation(string $url): void
    {
        Http::fake(['*' => Http::response(['data' => []], headers: ['Link' => '<'.$url.'>; rel="next"'])]);
        $paginator = $this->resource(new ResponseMapping(paginationType: 'link_header'))->getItems();

        $this->assertInstanceOf(Paginator::class, $paginator);
        $this->assertNull($paginator->getNextPageUrl());
        $this->assertStringNotContainsString('upstream.test', (string) $paginator);
    }

    public function test_laravel_pagination_keeps_one_based_pages_and_per_page(): void
    {
        Http::fake(['*' => Http::response(['data' => [['id' => 7]], 'total' => 60, 'per_page' => 20, 'current_page' => 2, 'last_page' => 3, 'path' => '/api/items'])]);
        $paginator = ResourceRequestContext::seed($this->resource(new ResponseMapping), ['page' => 2, 'per_page' => 20], static fn ($resource) => $resource->getItems());

        $this->assertInstanceOf(PaginatorContract::class, $paginator);
        $this->assertSame(2, $paginator->getCurrentPage());
        $this->assertFalse($paginator->isSimple());
        $this->assertSame('/api/items?page=3', $paginator->getNextPageUrl());
        $paginator->setPath('https://panel.test/component');
        $this->assertSame('https://panel.test/component?page=3', $paginator->getNextPageUrl());
        Http::assertSent(static fn (Request $request): bool => $request['page'] === 2 && $request['per_page'] === 20);
    }

    public function test_probe_and_browser_discovery_keep_django_navigation_mapping(): void
    {
        Http::fake(['*' => Http::response(['count' => 50, 'next' => 'https://compatibility.test/items?page=2', 'previous' => null, 'results' => [['id' => 7]]])]);
        app()->instance('api-admin.tenant.local.service.compatibility', $this->service(new ResponseMapping));
        $probe = new ProbeEndpointTool()->execute(['service' => 'compatibility', 'path' => '/items']);
        $discovery = new InstructorDiscoveryService()->discover('https://compatibility.test', ['/items']);

        $this->assertTrue($probe['success']);
        $this->assertTrue($discovery['success']);
        foreach ([$probe['guessed_mapping'], $discovery['probes'][0]['guessed_mapping']] as $mapping) {
            $this->assertIsArray($mapping);
            $this->assertSame('next', $mapping['next_page_url_key']);
            $this->assertSame('previous', $mapping['prev_page_url_key']);
            $this->assertSame('page_size', $mapping['per_page_param_name']);
        }
    }

    private function service(ResponseMapping $mapping): ServiceConfig
    {
        return new ServiceConfig(name: 'compatibility', baseUrl: 'https://compatibility.test', apiKey: '', responseMapping: $mapping);
    }

    private function resource(ResponseMapping $mapping): AbstractRestResource
    {
        return new class(app(CoreContract::class), $this->service($mapping)) extends AbstractRestResource
        {
            public function __construct(CoreContract $core, private readonly ServiceConfig $service)
            {
                parent::__construct($core);
            }

            protected function resolveServiceConfig(): ServiceConfig
            {
                return $this->service;
            }

            protected function endpoint(): string
            {
                return '/items';
            }
        };
    }
}
