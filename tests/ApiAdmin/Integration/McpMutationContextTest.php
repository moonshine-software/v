<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Integration;

use App\ApiAdmin\MCP\EndpointBinder;
use App\ApiAdmin\Providers\ApiAdminServiceProvider;
use App\ApiAdmin\Resources\AbstractRestResource;
use App\ApiAdmin\ServiceConfig\ResponseMapping;
use App\ApiAdmin\ServiceConfig\ServiceConfig;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use MoonShine\Contracts\Core\DependencyInjection\CoreContract;
use MoonShine\Laravel\Providers\MoonShineServiceProvider;
use MoonShine\MultiPanels\Providers\MultiPanelServiceProvider;
use Orchestra\Testbench\TestCase;

final class McpMutationContextTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [MoonShineServiceProvider::class, MultiPanelServiceProvider::class, ApiAdminServiceProvider::class];
    }

    public function test_parent_context_is_bound_in_explicit_mutation_paths_and_not_sent_as_payload(): void
    {
        Http::fake(['https://context.test/*' => Http::response(['id' => 17, 'title' => 'Updated'])]);

        $resource = $this->resource();
        EndpointBinder::bind($resource, ['parent_id' => 'project a']);
        $resource->createItem(['title' => 'Created']);
        $resource->updateItem(17, ['title' => 'Updated', 'parent_id' => 'attacker']);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://context.test/projects/project%20a/stages'
            && $request->data() === ['title' => 'Created']);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
            && $request->url() === 'https://context.test/projects/project%20a/stages/17'
            && $request->data() === ['title' => 'Updated', 'project_id' => 'project a', 'id' => '17']);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '{')
            || str_contains($request->url(), '%7B') || str_contains($request->url(), '/attacker/'));
    }

    public function test_parent_context_is_ignored_for_non_relation_resources_including_body_templates(): void
    {
        Http::fake(['*' => Http::response(['id' => 1])]);
        $resource = new class(app(CoreContract::class)) extends AbstractRestResource
        {
            protected array $mutations = ['create' => ['path' => '/stages', 'body' => ['title' => '{title}']]];

            protected function resolveServiceConfig(): ServiceConfig
            {
                return new ServiceConfig(name: 'context', baseUrl: 'https://context.test', apiKey: '', responseMapping: new ResponseMapping, authType: 'none');
            }

            protected function endpoint(): string
            {
                return '/stages';
            }
        };

        $binding = EndpointBinder::bind($resource, ['title' => 'Ignored']);
        $this->assertTrue($binding['ignoredParentContext']);
        $resource->createItem(['title' => 'Submitted']);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->data() === ['title' => 'Submitted']);
    }

    private function resource(): AbstractRestResource
    {
        return new class(app(CoreContract::class)) extends AbstractRestResource
        {
            protected array $mutations = [
                'create' => ['method' => 'POST', 'path' => '/projects/{parent_id}/stages'],
                'update' => [['method' => 'PATCH', 'path' => '/projects/{parent_id}/stages/{id}',
                    'body' => ['title' => '{title}', 'project_id' => '{parent_id}', 'id' => '{id}']]],
            ];

            protected function resolveServiceConfig(): ServiceConfig
            {
                return new ServiceConfig(name: 'context', baseUrl: 'https://context.test', apiKey: '', responseMapping: new ResponseMapping, authType: 'none');
            }

            protected function endpoint(): string
            {
                return '/projects/{parent_id}/stages';
            }

            protected function pages(): array
            {
                return [];
            }
        };
    }
}
