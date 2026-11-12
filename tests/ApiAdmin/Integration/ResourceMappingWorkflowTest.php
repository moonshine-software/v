<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Integration;

use App\ApiAdmin\MCP\McpServer;
use App\ApiAdmin\MCP\ResourceLocator;
use App\ApiAdmin\MCP\SpecStore\SpecStore;
use App\ApiAdmin\Providers\ApiAdminServiceProvider;
use App\ApiAdmin\ServiceConfig\ServiceConfig;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use MoonShine\Laravel\Providers\MoonShineServiceProvider;
use MoonShine\MultiPanels\Providers\MultiPanelServiceProvider;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\ApiAdmin\Concerns\UsesIsolatedProjectRoot;

final class ResourceMappingWorkflowTest extends TestCase
{
    use UsesIsolatedProjectRoot;

    private string $serviceName;

    private McpServer $server;

    protected function getPackageProviders($app): array
    {
        return [MoonShineServiceProvider::class, MultiPanelServiceProvider::class, ApiAdminServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $this->useIsolatedProjectRoot($app);
        $app['config']->set('api-admin.specs_path', base_path('.ai-factory/specs'));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->serviceName = 'workflow'.bin2hex(random_bytes(8));
        $service = ServiceConfig::fromArray([
            'name' => $this->serviceName,
            'base_url' => 'https://mapping-workflow.test',
            'api_key' => 'one-shared-token',
            'auth_type' => 'bearer',
            'panel' => $this->serviceName.'-admin',
            'response_mapping' => [
                'data_key' => 'data', 'pagination_type' => 'cursor',
                'filter_param_name' => 'filters', 'search_param_name' => 'term', 'sort_param_name' => 'ordering',
            ],
        ]);
        config(['api-admin.services.'.$this->serviceName => $service->toArray()]);
        app()->instance('api-admin.service.'.$this->serviceName, $service);
        $this->server = new McpServer;
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        $this->removeIsolatedProjectRoot();
        parent::tearDown();
    }

    public function test_partial_mapping_round_trips_through_mcp_and_regeneration_without_extra_services(): void
    {
        $mapping = ['data_key' => '', 'pagination_type' => 'none'];
        $this->create('permission', $mapping);
        $read = $this->tool('get_resource', ['name' => 'permission']);
        $spec = $read['spec'];
        $this->assertIsArray($spec);
        $this->assertSame($mapping, $spec['response_mapping']);

        $fields = [['column' => 'id', 'type' => 'id'], ['column' => 'name', 'type' => 'text', 'label' => 'Permission name']];
        $update = ['name' => 'permission', 'fields' => $fields];
        foreach ($spec as $key => $value) {
            if (is_string($key) && $key !== 'fields') {
                $update[$key] = $value;
            }
        }
        $this->tool('update_resource', $update);
        $updated = $this->tool('get_resource', ['name' => 'permission']);
        $this->assertSame($mapping, data_get($updated, 'spec.response_mapping'));
        $this->assertSame($fields, data_get($updated, 'spec.fields'));

        $resource = new ResourceLocator()->instantiate($this->serviceName, 'permission');
        $this->assertSame('', $resource->getServiceConfig()->responseMapping->dataKey);
        $this->assertSame('none', $resource->getServiceConfig()->responseMapping->paginationType);
        $this->assertSame('term', $resource->getServiceConfig()->responseMapping->searchParamName);

        $this->create('task', []);
        $sibling = new ResourceLocator()->instantiate($this->serviceName, 'task');
        $this->assertSame('data', $sibling->getServiceConfig()->responseMapping->dataKey);
        $this->assertSame('cursor', $sibling->getServiceConfig()->responseMapping->paginationType);
        $this->assertSame($resource->getServiceConfig()->panel, $sibling->getServiceConfig()->panel);
        $services = config('api-admin.services');
        $this->assertIsArray($services);
        $this->assertCount(1, $services);
    }

    public function test_fields_only_update_preserves_mapping_and_setting_detail_uses_the_list(): void
    {
        $mapping = ['primary_key' => 'name', 'pagination_type' => 'none', 'detail_strategy' => 'list'];
        $fields = [['column' => 'name', 'type' => 'text'], ['column' => 'value', 'type' => 'text']];
        $this->create('setting', $mapping, $fields);
        $this->tool('update_resource', ['name' => 'setting', 'fields' => $fields]);

        $read = $this->tool('get_resource', ['name' => 'setting']);
        $this->assertSame($mapping, data_get($read, 'spec.response_mapping'));
        Http::fake(static fn (Request $request) => parse_url($request->url(), PHP_URL_PATH) === '/settings'
            ? Http::response(['data' => [['name' => 'site_title', 'value' => 'Preserved']]])
            : Http::response(['message' => 'No detail endpoint'], 405));
        $detail = $this->tool('fetch_resource_item', ['resource' => 'setting', 'id' => 'site_title']);
        $this->assertSame('Preserved', data_get($detail, 'item.value'));
        Http::assertNotSent(static fn (Request $request): bool => str_contains($request->url(), '/settings/'));
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function explicitMappings(): iterable
    {
        yield 'clear overrides' => [[]];
        yield 'replace overrides rather than merge' => [['next_cursor_key' => 'meta.next_cursor']];
    }

    /** @param array<string, string> $replacement */
    #[DataProvider('explicitMappings')]
    public function test_explicit_update_mapping_replaces_or_clears_saved_overrides(array $replacement): void
    {
        $this->create('permission', ['data_key' => '', 'primary_key' => 'name', 'pagination_type' => 'none']);
        $updated = $this->tool('update_resource', [
            'name' => 'permission', 'fields' => [['column' => 'name', 'type' => 'text']], 'response_mapping' => $replacement,
        ]);
        $read = $this->tool('get_resource', ['name' => 'permission']);
        $this->assertSame($replacement, data_get($read, 'spec.response_mapping'));
        $file = $updated['file'];
        $this->assertIsString($file);
        $source = File::get($file);
        $this->assertStringNotContainsString("'primary_key' => 'name'", $source);
        $this->assertStringNotContainsString("'pagination_type' => 'none'", $source);
        if ($replacement === []) {
            $this->assertStringNotContainsString('protected array $responseMapping', $source);
        } else {
            $this->assertStringContainsString("'next_cursor_key' => 'meta.next_cursor'", $source);
        }
    }

    public function test_fields_only_update_without_a_saved_mapping_keeps_service_inheritance(): void
    {
        foreach (['no_override', 'legacy'] as $name) {
            $created = $this->tool('create_resource', ['name' => $name, 'endpoint' => '/'.$name, 'fields' => []]);
            if ($name === 'legacy') {
                File::delete(SpecStore::path($this->serviceName, $name));
            }
            $this->tool('update_resource', ['name' => $name, 'fields' => []]);
            $read = $this->tool('get_resource', ['name' => $name]);
            $spec = $read['spec'];
            $this->assertIsArray($spec);
            $this->assertArrayNotHasKey('response_mapping', $spec);
            $file = $created['file'];
            $this->assertIsString($file);
            $this->assertStringNotContainsString('protected array $responseMapping', File::get($file));
        }
    }

    public function test_add_field_preserves_the_saved_resource_mapping(): void
    {
        $mapping = ['primary_key' => 'name', 'pagination_type' => 'none', 'detail_strategy' => 'list'];
        $this->create('setting', $mapping, [['column' => 'name', 'type' => 'text']]);
        $this->tool('add_field', ['resource' => 'setting', 'field' => ['column' => 'value', 'type' => 'text']]);
        $read = $this->tool('get_resource', ['name' => 'setting']);
        $this->assertSame($mapping, data_get($read, 'spec.response_mapping'));
        $this->assertSame('value', data_get($read, 'spec.fields.1.column'));
    }

    public function test_search_filters_sort_count_and_aggregate_use_the_effective_resource_mapping(): void
    {
        $this->create('permission', ['data_key' => '', 'pagination_type' => 'none', 'search_param_name' => 'q', 'filter_param_name' => 'where']);
        $items = [['id' => 1, 'name' => 'Read', 'score' => 3], ['id' => 2, 'name' => 'Write', 'score' => 7]];
        Http::fake(['*' => Http::response($items)]);
        $query = ['resource' => 'permission', 'filter' => ['active' => 'yes'], 'search' => 'Read', 'sort' => '-id'];

        $fetch = $this->tool('fetch_resource_items', $query);
        $this->assertSame($items, data_get($fetch, 'data.items'));
        $this->assertSame('none', data_get($fetch, 'data.pagination.strategy'));
        $count = $this->tool('count_resource', $query);
        $this->assertSame(2, $count['count']);
        $aggregate = $this->tool('aggregate_resource', [...$query, 'operation' => 'sum', 'field' => 'score']);
        $this->assertSame(10.0, $aggregate['result']);

        Http::assertSentCount(3);
        foreach (Http::recorded() as [$request]) {
            $this->assertInstanceOf(Request::class, $request);
            $urlQuery = parse_url($request->url(), PHP_URL_QUERY);
            $this->assertIsString($urlQuery);
            parse_str($urlQuery, $params);
            $this->assertSame('Read', $params['q']);
            $this->assertSame(['active' => 'yes'], $params['where']);
            $this->assertArrayNotHasKey('term', $params);
            $this->assertArrayNotHasKey('filters', $params);
            $this->assertSame(['Bearer one-shared-token'], $request->header('Authorization'));
        }
        Http::assertSent(static fn (Request $request): bool => str_contains($request->url(), 'ordering=-id'));
    }

    public function test_string_primary_keys_work_for_mcp_detail_create_update_and_delete(): void
    {
        $this->create('setting', ['primary_key' => 'name', 'pagination_type' => 'none', 'detail_strategy' => 'list'], [
            ['column' => 'name', 'type' => 'text'], ['column' => 'value', 'type' => 'text'],
        ]);
        $state = ['site_title' => ['name' => 'site_title', 'value' => 'Original']];
        Http::fake(static function (Request $request) use (&$state) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($request->method() === 'GET' && $path === '/settings') {
                return Http::response(['data' => array_values($state)]);
            }
            if ($request->method() === 'POST' && $path === '/settings') {
                $data = $request->data();
                $name = $data['name'] ?? null;
                if (! is_string($name)) {
                    return Http::response(['errors' => ['name' => ['Required']]], 422);
                }
                $state[$name] = $data;

                return Http::response(['data' => $data], 201);
            }
            if ($path === '/settings/site_title' && $request->method() === 'PUT') {
                $state['site_title'] = ['name' => 'site_title', ...$request->data()];

                return Http::response(null, 204);
            }
            if ($path === '/settings/site_title' && $request->method() === 'DELETE') {
                unset($state['site_title']);

                return Http::response(null, 204);
            }

            return Http::response(null, 405);
        });

        $detail = $this->tool('fetch_resource_item', ['resource' => 'setting', 'id' => 'site_title']);
        $this->assertSame('site_title', data_get($detail, 'item.name'));
        $created = $this->tool('create_resource_item', ['resource' => 'setting', 'payload' => ['name' => 'site_subtitle', 'value' => 'Subtitle']]);
        $this->assertSame('site_subtitle', $created['primary_key_value']);
        $updated = $this->tool('update_resource_item', ['resource' => 'setting', 'id' => 'site_title', 'payload' => ['value' => 'Updated']]);
        $this->assertSame('site_title', $updated['primary_key_value']);
        $this->assertSame('Updated', $state['site_title']['value']);
        $deleted = $this->tool('delete_resource_item', ['resource' => 'setting', 'id' => 'site_title', 'confirm' => true]);
        $this->assertSame('site_title', $deleted['deleted_id']);
        $this->assertArrayNotHasKey('site_title', $state);
        Http::assertNotSent(static fn (Request $request): bool => $request->method() === 'GET'
            && str_contains($request->url(), '/settings/'));
    }

    /** @param array<string, bool|string> $mapping
     * @param list<array<string, mixed>>|null $fields
     */
    private function create(string $name, array $mapping, ?array $fields = null): void
    {
        $this->tool('create_resource', [
            'name' => $name, 'endpoint' => '/'.($name === 'setting' ? 'settings' : $name),
            'fields' => $fields ?? [['column' => 'id', 'type' => 'id', 'methods' => ['sortable']], ['column' => 'name', 'type' => 'text']],
            'response_mapping' => $mapping,
        ]);
    }

    /** @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function tool(string $name, array $params): array
    {
        $result = $this->server->executeTool($name, ['service' => $this->serviceName, ...$params]);
        $this->assertTrue($result['success'] ?? false, (string) json_encode($result));

        return $result;
    }
}
