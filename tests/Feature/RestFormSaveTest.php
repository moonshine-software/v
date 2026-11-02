<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\ApiAdmin\Fields\RestBelongsToMany;
use App\ApiAdmin\Resources\AbstractRestResource;
use App\ApiAdmin\ServiceConfig\ResponseMapping;
use App\ApiAdmin\ServiceConfig\ServiceConfig;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use MoonShine\Contracts\Core\DependencyInjection\CoreContract;
use MoonShine\Core\TypeCasts\MixedDataWrapper;
use MoonShine\Crud\Collections\Fields;
use MoonShine\Laravel\Models\MoonshineUser;
use MoonShine\UI\Fields\Text;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class RestFormSaveTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        RestBelongsToMany::flushCaches();
        Http::preventStrayRequests();
        app()->instance(ServiceConfig::class, new ServiceConfig(
            name: 'form-save', baseUrl: 'https://form-save.test', apiKey: '', responseMapping: new ResponseMapping,
        ));
    }

    /** @return iterable<string, array{string, int|string, int}> */
    public static function updates(): iterable
    {
        yield 'empty response' => ['id', 7, 204];
        yield 'custom opaque key' => ['uuid', 'opaque-key', 204];
        yield 'partial response without key' => ['id', 7, 200];
    }

    #[DataProvider('updates')]
    public function test_update_preserves_identity_and_saves_relations_without_a_response_key(string $primary, int|string $id, int $status): void
    {
        app()->instance(ServiceConfig::class, new ServiceConfig(
            name: 'form-save', baseUrl: 'https://form-save.test', apiKey: '', responseMapping: new ResponseMapping(primaryKey: $primary),
        ));
        Http::fake([
            'https://form-save.test/posts/'.$id => Http::response($status === 204 ? null : ['title' => 'Canonical'], $status),
            'https://form-save.test/posts/'.$id.'/tags' => Http::response(null, 204),
        ]);
        request()->merge(['title' => 'Updated', 'tags' => [2]]);
        $resource = new RestFormSaveResource(app(CoreContract::class));
        $result = $resource->save(new MixedDataWrapper([$primary => $id], $id));

        $this->assertSame($id, $result->getKey());
        $this->assertSame($status === 204 ? 'Updated' : 'Canonical', $result->toArray()['title']);
        Http::assertSentCount(2);
        Http::assertSent(static fn (Request $request): bool => $request->method() === 'PUT'
            && $request->url() === 'https://form-save.test/posts/'.$id.'/tags' && $request['ids'] === [2]);
    }

    public function test_create_does_not_invent_an_identity_for_an_empty_response(): void
    {
        Http::fake(['*' => Http::response(null, 201)]);
        $resource = new RestFormSaveResource(app(CoreContract::class));

        $this->assertNull($resource->save(new MixedDataWrapper([]), Fields::make([]))->getKey());
        Http::assertSentCount(1);
    }

    public function test_ajax_form_reports_partial_save_when_relation_write_fails(): void
    {
        $this->actingAs(new MoonshineUser(['id' => 1]), 'moonshine');
        moonshine()->resources([RestFormSaveResource::class]);
        Http::fake([
            'https://form-save.test/posts/7' => Http::response(['data' => ['id' => 7, 'title' => 'Updated']]),
            'https://form-save.test/posts/7/tags' => Http::response(['password' => 'private-relation-secret'], 500),
        ]);

        $this->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->putJson('/admin/resource/rest-form-save-resource/crud/7', ['title' => 'Updated', 'tags' => [2]])
            ->assertUnprocessable()
            ->assertSee('The record was saved, but this relation could not be saved.')
            ->assertDontSee('private-relation-secret');

        Http::assertSent(static fn (Request $request): bool => $request->method() === 'PUT' && $request->url() === 'https://form-save.test/posts/7');
        Http::assertSent(static fn (Request $request): bool => $request->method() === 'PUT' && $request->url() === 'https://form-save.test/posts/7/tags');
    }

    /** @return iterable<string, array{string, bool}> */
    public static function relationFailures(): iterable
    {
        foreach (['loadOptions', 'syncRelation', 'loadCurrentAssociations', 'primeAssociations'] as $operation) {
            yield $operation.' HTTP error' => [$operation, false];
            yield $operation.' connection error' => [$operation, true];
        }
    }

    #[DataProvider('relationFailures')]
    public function test_relation_diagnostics_never_log_upstream_bodies_or_exception_urls(string $operation, bool $network): void
    {
        Http::fake(static fn () => $network
            ? throw new ConnectionException('https://form-save.test/tags?api_key=private-query-secret')
            : Http::response(['password' => 'private-response-secret'], 500));
        $logs = [];
        Log::listen(static function (MessageLogged $event) use (&$logs): void {
            $logs[] = [$event->message, $event->context];
        });
        $field = RestBelongsToMany::make('Tags', 'tags')
            ->resource(new RestFormSaveResource(app(CoreContract::class)))
            ->endpoint('/tags')->attachEndpoint('/posts/{parent_id}/tags');
        $parent = new MixedDataWrapper(['id' => 7], 7);

        match ($operation) {
            'loadOptions' => $this->assertSame([], $field->loadOptions()),
            'syncRelation' => $this->assertFalse($field->syncRelation([2], $parent)),
            'loadCurrentAssociations' => $this->assertSame('', $field->fillData($parent)->preview()),
            'primeAssociations' => $this->assertSame([], $field
                ->batchAttachEndpoint('/posts/relations?ids={ids}')
                ->batchAttachMapper(static fn (array $items): array => $items)->primeAssociations([7])),
            default => $this->fail('Unknown relation operation: '.$operation),
        };

        $recorded = json_encode($logs, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('private-query-secret', $recorded);
        $this->assertStringNotContainsString('private-response-secret', $recorded);
        $this->assertContains([
            '[FIX:rest-relations] RestBelongsToMany::'.$operation.' — failed',
            ['field' => 'tags', 'exception' => $network ? ConnectionException::class : RequestException::class, 'status' => $network ? null : 500],
        ], $logs);
    }
}

class RestFormSaveResource extends AbstractRestResource
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
        return [
            Text::make('Title', 'title'),
            RestBelongsToMany::make('Tags', 'tags')->endpoint('/tags')->attachEndpoint('/posts/{parent_id}/tags'),
        ];
    }
}
