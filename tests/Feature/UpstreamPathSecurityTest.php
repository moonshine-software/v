<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\ApiAdmin\Fields\RestBelongsTo;
use App\ApiAdmin\Fields\RestBelongsToMany;
use App\ApiAdmin\Fields\RestHasMany;
use App\ApiAdmin\Http\PathSegment;
use App\ApiAdmin\MCP\McpServer;
use App\ApiAdmin\Resources\AbstractRestResource;
use App\ApiAdmin\ServiceConfig\ResponseMapping;
use App\ApiAdmin\ServiceConfig\ServiceConfig;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use MoonShine\Contracts\Core\DependencyInjection\CoreContract;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class UpstreamPathSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'api-admin.resources_path' => base_path('tests/ApiAdmin/Fixtures/Resources'),
            'api-admin.resources_namespace' => 'Tests\\ApiAdmin\\Fixtures\\Resources',
            'api-admin.specs_path' => '/nonexistent/upstream-path-security-specs',
        ]);
        app()->instance('api-admin.tenant.local.service.blog', $this->service());
        Http::preventStrayRequests();
        Http::fake(['https://path.example.test/*' => Http::response(['id' => 7, 'title' => 'Unchanged'])]);
    }

    /** @return iterable<string, array{string, string}> */
    public static function unsafeItemIds(): iterable
    {
        foreach (['../users/7', '..', '.', '%2e%2e', '%252e%252e', '7/../../users/1', '7\\..\\users', "7\n"] as $id) {
            foreach (['fetch_resource_item', 'update_resource_item', 'delete_resource_item'] as $tool) {
                yield $tool.' '.json_encode($id) => [$tool, $id];
            }
        }
    }

    #[DataProvider('unsafeItemIds')]
    public function test_mcp_item_tools_reject_path_manipulation_before_contacting_upstream(string $tool, string $id): void
    {
        $result = app(McpServer::class)->executeTool($tool, [
            'service' => 'blog', 'resource' => 'post', 'id' => $id, 'payload' => ['title' => 'Changed'], 'confirm' => true,
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('VALIDATION', $result['code']);
        Http::assertNothingSent();
    }

    public function test_mcp_relation_context_rejects_dot_segments_before_contacting_upstream(): void
    {
        $result = app(McpServer::class)->executeTool('fetch_resource_items', [
            'service' => 'blog', 'resource' => 'comment', 'parent_context' => ['parent_id' => '..'],
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('VALIDATION', $result['code']);
        Http::assertNothingSent();
    }

    public function test_opaque_item_id_is_encoded_as_one_segment_without_changing_the_id(): void
    {
        $id = 'user+test@example.test?scope=all#id';
        $result = app(McpServer::class)->executeTool('delete_resource_item', [
            'service' => 'blog', 'resource' => 'post', 'id' => $id, 'confirm' => true,
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame($id, $result['deleted_id']);
        Http::assertSentCount(2);
        Http::assertSent(static fn (Request $request): bool => $request->method() === 'DELETE'
            && $request->url() === 'https://path.example.test/posts/'.rawurlencode($id));
    }

    public function test_mutation_path_values_are_encoded_but_body_values_stay_unchanged(): void
    {
        $value = 'Tom & Jerry?admin=true#fragment';
        $resource = $this->mutationResource();
        $resource->createItem(['owner' => $value]);

        Http::assertSent(static fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://path.example.test/owners/'.rawurlencode($value).'/posts'
            && $request['owner'] === $value);
    }

    public function test_mutation_path_cannot_be_changed_by_form_payload(): void
    {
        $resource = $this->mutationResource();

        try {
            $resource->createItem(['owner' => '../users']);
            $this->fail('Unsafe mutation path was accepted.');
        } catch (ValidationException $exception) {
            $this->assertSame(422, $exception->status);
        }

        Http::assertNothingSent();
    }

    public function test_relation_field_endpoint_rejects_dot_segments(): void
    {
        $field = new class('Owner', 'owner_id') extends RestBelongsTo
        {
            public function resolveUnsafeEndpoint(): ?string
            {
                return $this->resolveRelationEndpoint('/owners/{owner_id}/posts', ['owner_id' => '..']);
            }
        };

        try {
            $field->resolveUnsafeEndpoint();
            $this->fail('Unsafe relation path was accepted.');
        } catch (ValidationException $exception) {
            $this->assertSame(422, $exception->status);
        }

        Http::assertNothingSent();
    }

    public function test_regular_numeric_uuid_and_zero_ids_keep_their_paths(): void
    {
        foreach ([0, 7, '92219d06-e92c-4ed0-a489-e1e4fd199065', 'external_id.7~a'] as $id) {
            $this->assertSame((string) $id, PathSegment::encode($id));
        }
    }

    public function test_mass_delete_validates_all_ids_before_deleting_any_item(): void
    {
        try {
            $this->mutationResource()->massDelete([7, '../users/1']);
            $this->fail('Unsafe mass delete was accepted.');
        } catch (ValidationException $exception) {
            $this->assertSame(422, $exception->status);
        }

        Http::assertNothingSent();
    }

    public function test_has_many_rejects_unsafe_child_ids_before_contacting_upstream(): void
    {
        $field = new RestHasMany('Comments', 'comments')
            ->resource($this->mutationResource())
            ->endpoint('/posts/{parent_id}/comments')
            ->parentId(7);

        foreach (['loadSingleItem', 'deleteItemForParent', 'updateItemForParent'] as $method) {
            try {
                match ($method) {
                    'loadSingleItem' => $field->loadSingleItem('../users/1'),
                    'deleteItemForParent' => $field->deleteItemForParent('../users/1', 7),
                    'updateItemForParent' => $field->updateItemForParent(['title' => 'Changed'], '../users/1', 7),
                };
                $this->fail('Unsafe child ID was accepted.');
            } catch (ValidationException $exception) {
                $this->assertSame(422, $exception->status);
            }
        }

        Http::assertNothingSent();
    }

    public function test_batch_relation_ids_cannot_inject_query_parameters(): void
    {
        $field = new RestBelongsToMany('Tags', 'tags')
            ->resource($this->mutationResource())
            ->batchAttachEndpoint('/tags-batch?ids={ids}&scope=default')
            ->endpointQuery(['scope' => 'project'])
            ->batchAttachMapper(static fn (array $payload): array => []);
        $field->primeAssociations(['7&scope=all', 9]);

        Http::assertSent(static function (Request $request): bool {
            $queryString = parse_url($request->url(), PHP_URL_QUERY);
            parse_str(is_string($queryString) ? $queryString : '', $query);

            return str_starts_with($request->url(), 'https://path.example.test/tags-batch?')
                && $query === ['ids' => '7&scope=all,9', 'scope' => 'project'];
        });
    }

    private function service(): ServiceConfig
    {
        return new ServiceConfig(name: 'blog', baseUrl: 'https://path.example.test', apiKey: 'synthetic', responseMapping: new ResponseMapping);
    }

    private function mutationResource(): AbstractRestResource
    {
        return new class(app(CoreContract::class)) extends AbstractRestResource
        {
            protected array $mutations = [
                'create' => ['method' => 'POST', 'path' => '/owners/{owner}/posts', 'body' => ['owner' => '{owner}']],
            ];

            protected function resolveServiceConfig(): ServiceConfig
            {
                return new ServiceConfig(name: 'blog', baseUrl: 'https://path.example.test', apiKey: 'synthetic', responseMapping: new ResponseMapping);
            }

            protected function endpoint(): string
            {
                return '/posts';
            }
        };
    }
}
