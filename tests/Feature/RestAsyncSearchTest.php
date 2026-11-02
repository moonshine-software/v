<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\ApiAdmin\Fields\RestBelongsTo;
use App\ApiAdmin\Resources\AbstractRestResource;
use App\ApiAdmin\ServiceConfig\ResponseMapping;
use App\ApiAdmin\ServiceConfig\ServiceConfig;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use MoonShine\Contracts\Core\DependencyInjection\CoreContract;
use MoonShine\Laravel\Models\MoonshineUser;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class RestAsyncSearchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(new MoonshineUser(['id' => 1]), 'moonshine');
        app()->instance(ServiceConfig::class, new ServiceConfig(
            name: 'search', baseUrl: 'https://search.example.test', apiKey: '', responseMapping: new ResponseMapping,
        ));
        moonshine()->resources([AsyncSearchResource::class]);
        Http::preventStrayRequests();
    }

    /** @return iterable<string, array{string}> */
    public static function searchParameters(): iterable
    {
        yield 'default search' => ['search'];
        yield 'custom search' => ['q'];
    }

    #[DataProvider('searchParameters')]
    public function test_search_reaches_records_beyond_the_first_upstream_page(string $parameter): void
    {
        app()->instance(ServiceConfig::class, new ServiceConfig(
            name: 'search', baseUrl: 'https://search.example.test', apiKey: '',
            responseMapping: new ResponseMapping(searchParamName: $parameter),
        ));
        Http::fake(static fn (Request $request) => Http::response([
            'data' => ($request->data()[$parameter] ?? '') === 'Late Owner'
                ? [['id' => 26, 'name' => 'Late Owner']]
                : array_map(static fn (int $id): array => ['id' => $id, 'name' => 'Owner '.$id], range(1, 25)),
            'total' => 26, 'per_page' => 25, 'current_page' => 1, 'last_page' => 2,
        ]));

        $this->getJson($this->searchUrl(['query' => 'Late Owner', 'for_entity' => 'admin']))
            ->assertOk()->assertExactJson([['value' => '26', 'label' => 'Late Owner']]);

        Http::assertSentCount(1);
        Http::assertSent(static fn (Request $request): bool => $request[$parameter] === 'Late Owner'
            && $request['for_entity'] === 'project');
    }

    public function test_opening_the_dropdown_keeps_the_existing_scope_and_result_limit(): void
    {
        Http::fake(['*' => Http::response(['data' => array_map(
            static fn (int $id): array => ['id' => $id, 'name' => 'Owner '.$id], range(1, 25),
        )])]);

        $this->getJson($this->searchUrl())->assertOk()->assertJsonCount(20);

        Http::assertSent(static fn (Request $request): bool => $request->url() === 'https://search.example.test/owners?for_entity=project');
    }

    public function test_search_cannot_overwrite_a_server_defined_scope_parameter(): void
    {
        app()->instance(ServiceConfig::class, new ServiceConfig(
            name: 'search', baseUrl: 'https://search.example.test', apiKey: '',
            responseMapping: new ResponseMapping(searchParamName: 'for_entity'),
        ));
        Http::fake(['*' => Http::response(['data' => [['id' => 26, 'name' => 'Late Owner']]])]);

        $this->getJson($this->searchUrl(['query' => 'Late Owner']))
            ->assertOk()->assertExactJson([['value' => '26', 'label' => 'Late Owner']]);

        Http::assertSent(static fn (Request $request): bool => $request['for_entity'] === 'project');
    }

    /** @return iterable<string, array{string}> */
    public static function detailStrategies(): iterable
    {
        yield 'single endpoint' => ['endpoint'];
        yield 'filtered list' => ['list'];
    }

    #[DataProvider('detailStrategies')]
    public function test_the_saved_selection_renders_its_label_beyond_page_one(string $strategy): void
    {
        app()->instance(ServiceConfig::class, new ServiceConfig(
            name: 'search', baseUrl: 'https://search.example.test', apiKey: '',
            responseMapping: new ResponseMapping(detailStrategy: $strategy, detailDataKey: $strategy === 'list' ? 'resource' : ''),
        ));
        Http::fake(['*' => Http::response(['data' => $strategy === 'list'
            ? [['id' => 26, 'name' => 'Late Owner']]
            : ['id' => 26, 'name' => 'Late Owner']])]);

        $this->assertStringContainsString('Late Owner', $this->renderSelectedOwner());

        Http::assertSentCount(1);
        Http::assertSent(static fn (Request $request): bool => $request->url() === ($strategy === 'list'
            ? 'https://search.example.test/owners?for_entity=project&id=26'
            : 'https://search.example.test/owners/26?for_entity=project'));
    }

    /** @return iterable<string, array{int, int}> */
    public static function rejectedSelections(): iterable
    {
        yield 'mismatched record' => [200, 99];
        yield 'authentication denied' => [401, 26];
        yield 'authorization denied' => [403, 26];
    }

    #[DataProvider('rejectedSelections')]
    public function test_selected_label_lookup_rejects_wrong_records_and_never_falls_back_after_auth_denial(int $status, int $id): void
    {
        Http::fake(['*' => Http::response(['data' => ['id' => $id, 'name' => 'Forbidden Label']], $status)]);

        $this->assertStringNotContainsString('Forbidden Label', $this->renderSelectedOwner());

        Http::assertSentCount(1);
    }

    public function test_a_catalog_without_detail_endpoints_keeps_its_selected_label(): void
    {
        Http::fake([
            'https://search.example.test/owners/26*' => Http::response([], 404),
            'https://search.example.test/owners?*' => Http::response(['data' => [['id' => 26, 'name' => 'Catalog Owner']]]),
        ]);

        $this->assertStringContainsString('Catalog Owner', $this->renderSelectedOwner());

        Http::assertSentCount(2);
    }

    public function test_a_failed_catalog_fallback_does_not_copy_response_secrets_to_logs(): void
    {
        Http::fake([
            'https://search.example.test/owners/26*' => Http::response([], 404),
            'https://search.example.test/owners?*' => Http::response(['password' => 'private-catalog-secret'], 500),
        ]);
        $logs = [];
        Log::listen(static function (MessageLogged $event) use (&$logs): void {
            $logs[] = [$event->message, $event->context];
        });

        $this->assertStringNotContainsString('private-catalog-secret', $this->renderSelectedOwner());
        $this->assertStringNotContainsString('private-catalog-secret', json_encode($logs, JSON_THROW_ON_ERROR));
        Http::assertSentCount(2);
    }

    /** @return iterable<string, array{bool}> */
    public static function failures(): iterable
    {
        yield 'HTTP response body' => [false];
        yield 'connection error URL' => [true];
    }

    #[DataProvider('failures')]
    public function test_upstream_failures_never_copy_secrets_to_logs_or_the_browser(bool $network): void
    {
        Http::fake(static fn () => $network
            ? throw new ConnectionException('https://search.example.test/owners?api_key=private-query-secret')
            : Http::response(['password' => 'private-response-secret'], 500));
        $logs = [];
        Log::listen(static function (MessageLogged $event) use (&$logs): void {
            $logs[] = [$event->message, $event->context];
        });

        $this->getJson($this->searchUrl())->assertOk()->assertExactJson([]);

        $recorded = json_encode($logs, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('private-query-secret', $recorded);
        $this->assertStringNotContainsString('private-response-secret', $recorded);
        $this->assertContains([
            'RestAsyncSearchController: failed',
            ['service' => 'search', 'exception' => $network ? ConnectionException::class : RequestException::class, 'status' => $network ? null : 500],
        ], $logs);
    }

    /** @param array<string, string> $parameters */
    private function searchUrl(array $parameters = []): string
    {
        $resource = new AsyncSearchResource(app(CoreContract::class));

        return '/api-admin/async-search?'.http_build_query([
            '_resource' => $resource->getUriKey(), '_relation' => 'owner_id', ...$parameters,
        ]);
    }

    private function renderSelectedOwner(): string
    {
        $field = RestBelongsTo::make('Owner', 'owner_id')
            ->endpoint('/owners')->endpointQuery(['for_entity' => 'project'])
            ->resource(new AsyncSearchResource(app(CoreContract::class)));
        $field->setValue(26);

        $rendered = $field->render();
        $html = $rendered instanceof Renderable ? $rendered->render() : $rendered;
        $this->assertIsString($html);

        return $html;
    }
}

class AsyncSearchResource extends AbstractRestResource
{
    protected function resolveServiceConfig(): ServiceConfig
    {
        return app(ServiceConfig::class);
    }

    protected function endpoint(): string
    {
        return '/posts';
    }

    protected function formFields(): iterable
    {
        return [RestBelongsTo::make('Owner', 'owner_id')->endpoint('/owners')->endpointQuery(['for_entity' => 'project'])];
    }
}
