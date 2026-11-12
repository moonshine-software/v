<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\ApiAdmin\MCP\Generator\ResourceGenerator;
use App\ApiAdmin\MCP\ItemSerializer;
use App\ApiAdmin\MCP\ResourceLocator;
use App\ApiAdmin\MCP\Tools\CountResourceTool;
use App\ApiAdmin\MCP\Tools\FetchResourceItemsTool;
use App\ApiAdmin\Resources\AbstractRestResource;
use App\ApiAdmin\ServiceConfig\ServiceConfig;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use MoonShine\Contracts\Core\Paginator\PaginatorContract;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ResourceResponseMappingTest extends TestCase
{
    private string $resourcePath;

    private ServiceConfig $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resourcePath = sys_get_temp_dir().'/v-resource-mapping-'.bin2hex(random_bytes(8));
        $name = 'mapping_'.bin2hex(random_bytes(8));
        $this->service = ServiceConfig::fromArray([
            'name' => $name,
            'base_url' => 'https://mapping.test/api',
            'api_key' => 'mapping-test-token',
            'auth_type' => 'bearer',
            'auth_behavior' => 'none',
            'panel' => 'mapping-admin',
            'tenant_id' => 'mapping-tenant',
            'response_mapping' => ['data_key' => 'data', 'pagination_type' => 'cursor', 'filter_param_name' => 'filters'],
            'realtime' => ['enabled' => true, 'webhook_secret' => 'mapping-test-webhook-secret'],
        ]);
        config([
            'api-admin.resources_path' => $this->resourcePath,
            'api-admin.specs_path' => $this->resourcePath.'/.specs',
            'api-admin.services.'.$name => $this->service->toArray(),
        ]);
        app()->instance('api-admin.service.'.$name, $this->service);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->resourcePath);
        parent::tearDown();
    }

    public function test_bare_array_permissions_are_consistent_in_the_resource_and_mcp_fetch_and_count(): void
    {
        $items = [['id' => 1, 'name' => 'tasks.view'], ['id' => 2, 'name' => 'tasks.update']];
        Http::fake(['*' => Http::response($items)]);
        $resource = $this->generate('permission', ['data_key' => '', 'pagination_type' => 'none']);

        $serialized = ItemSerializer::serializePaginator($resource->getItems(), $resource->getServiceConfig()->responseMapping);
        $this->assertSame($items, $serialized['items']);
        $this->assertSame('none', $serialized['pagination']['strategy']);

        $params = ['service' => $this->service->name, 'resource' => 'permission'];
        $fetch = new FetchResourceItemsTool()->execute($params);
        $this->assertTrue($fetch['success'], (string) json_encode($fetch));
        $data = $fetch['data'] ?? null;
        $this->assertIsArray($data);
        $this->assertSame($items, $data['items']);
        $pagination = $data['pagination'] ?? null;
        $this->assertIsArray($pagination);
        $this->assertSame('none', $pagination['strategy']);
        $count = new CountResourceTool()->execute($params);
        $this->assertTrue($count['success'], (string) json_encode($count));
        $this->assertSame(2, $count['count']);
        $this->assertArrayNotHasKey('warning', $count);
        Http::assertSent(static fn (Request $request): bool => $request->header('Authorization') === ['Bearer mapping-test-token']);
    }

    /** @return iterable<string, array{string}> */
    public static function cursorPaths(): iterable
    {
        yield 'nested cursor' => ['meta.next_cursor'];
        yield 'top-level cursor' => ['next_cursor'];
    }

    #[DataProvider('cursorPaths')]
    public function test_resource_cursor_override_is_used_by_browser_and_mcp(string $path): void
    {
        $body = ['data' => [['id' => 9]]];
        data_set($body, $path, 'next-token');
        $this->assertIsArray($body);
        Http::fake(['*' => Http::response($body)]);
        $resource = $this->generate('notification', ['next_cursor_key' => $path]);

        $paginator = $resource->getItems();
        $this->assertInstanceOf(PaginatorContract::class, $paginator);
        $this->assertSame(request()->fullUrlWithQuery(['cursor' => 'next-token']), $paginator->getNextPageUrl());
        $fetch = new FetchResourceItemsTool()->execute(['service' => $this->service->name, 'resource' => 'notification']);
        $this->assertTrue($fetch['success'], (string) json_encode($fetch));
        $data = $fetch['data'] ?? null;
        $this->assertIsArray($data);
        $pagination = $data['pagination'] ?? null;
        $this->assertIsArray($pagination);
        $this->assertSame('next-token', $pagination['next_cursor']);
        $this->assertTrue($pagination['has_more']);
    }

    public function test_string_primary_key_override_applies_to_caster_and_list_lookup(): void
    {
        Http::fake(['*' => Http::response(['data' => [['name' => 'other', 'value' => 'x'], ['name' => 'site_title', 'value' => 'Tasky']]])]);
        $resource = $this->generate('setting', ['primary_key' => 'name', 'detail_strategy' => 'list', 'pagination_type' => 'none']);
        $this->assertSame('site_title', $resource->getCaster()->cast(['name' => 'site_title'])->getKey());
        $resource->setItemID('site_title');

        $item = $resource->findItem(orFail: true);
        $this->assertSame('site_title', $item->getKey());
        $this->assertSame('Tasky', data_get($item->getOriginal(), 'value'));
    }

    public function test_override_preserves_service_identity_and_secrets_without_changing_siblings(): void
    {
        $original = $this->service->toArray();
        $resource = $this->generate('permission', ['data_key' => '', 'pagination_type' => 'none']);
        $sibling = $this->generate('task', []);
        $expected = array_replace($original, [
            'response_mapping' => array_replace($original['response_mapping'], ['data_key' => '', 'pagination_type' => 'none']),
        ]);

        $this->assertSame($expected, $resource->getServiceConfig()->toArray());
        $this->assertSame($original, $this->service->toArray());
        $this->assertSame($this->service, $sibling->getServiceConfig());
    }

    /** @param array<string, bool|string> $mapping */
    private function generate(string $name, array $mapping): AbstractRestResource
    {
        $result = new ResourceGenerator()->generate([
            'service' => $this->service->name,
            'name' => $name,
            'endpoint' => '/'.$name,
            'fields' => [],
            'response_mapping' => $mapping,
        ]);
        require $result['file'];
        foreach ($result['pages'] as $page) {
            require $page;
        }

        return new ResourceLocator()->instantiate($this->service->name, $name);
    }
}
