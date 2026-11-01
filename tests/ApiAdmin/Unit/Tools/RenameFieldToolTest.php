<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Unit\Tools;

use App\ApiAdmin\MCP\Generator\ResourceGenerator;
use App\ApiAdmin\MCP\SpecStore\SpecStore;
use App\ApiAdmin\MCP\Tools\RenameFieldTool;
use App\ApiAdmin\Providers\ApiAdminServiceProvider;
use App\ApiAdmin\ServiceConfig\ResponseMapping;
use App\ApiAdmin\ServiceConfig\ServiceConfig;
use Illuminate\Support\Facades\File;
use MoonShine\Laravel\Providers\MoonShineServiceProvider;
use MoonShine\MultiPanels\Providers\MultiPanelServiceProvider;
use Orchestra\Testbench\TestCase;

final class RenameFieldToolTest extends TestCase
{
    private string $serviceSlug;

    private string $resourcesRoot;

    protected function getPackageProviders($app): array
    {
        return [
            MoonShineServiceProvider::class,
            MultiPanelServiceProvider::class,
            ApiAdminServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $this->resourcesRoot = sys_get_temp_dir().'/moonshine-rename-field-resources-'.uniqid('', true);
        $app['config']->set('api-admin.resources_path', $this->resourcesRoot);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->serviceSlug = 'rnsvc'.substr(str_replace('.', '', uniqid('', true)), 0, 8);

        config(["api-admin.services.{$this->serviceSlug}" => [
            'config' => "App\\MoonShine\\ApiAdmin\\Configs\\{$this->serviceSlug}Config",
            'panel' => $this->serviceSlug.'-admin',
            'enabled' => true,
        ]]);

        app()->forgetInstance("api-admin.service.{$this->serviceSlug}");
        app()->instance(
            "api-admin.service.{$this->serviceSlug}",
            new ServiceConfig(
                name: $this->serviceSlug,
                baseUrl: 'https://api.test/v1',
                apiKey: '',
                responseMapping: new ResponseMapping(primaryKey: 'id'),
                authType: 'bearer',
            ),
        );
    }

    protected function tearDown(): void
    {
        $sidecarDir = dirname(SpecStore::path($this->serviceSlug, 'x'));
        if (File::isDirectory($sidecarDir)) {
            File::deleteDirectory($sidecarDir);
        }
        if (File::isDirectory($this->resourcesRoot)) {
            File::deleteDirectory($this->resourcesRoot);
        }
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function seedResource(array $extra = []): void
    {
        $params = array_merge([
            'service' => $this->serviceSlug,
            'name' => 'task',
            'endpoint' => '/tasks',
            'fields' => [
                ['column' => 'id', 'type' => 'id'],
                ['column' => 'priority', 'type' => 'text'],
            ],
        ], $extra);

        $_ = (new ResourceGenerator)->generate($params);
    }

    /**
     * @return array<string, mixed>
     */
    private function readSpec(): array
    {
        $spec = SpecStore::read($this->serviceSlug, 'task');
        $this->assertIsArray($spec);

        return $spec;
    }

    public function test_renames_field_in_fields_list(): void
    {
        $this->seedResource();

        $result = (new RenameFieldTool)->execute([
            'service' => $this->serviceSlug,
            'resource' => 'task',
            'from' => 'priority',
            'to' => 'urgency',
        ]);

        $this->assertSame('priority', $result['from']);
        $this->assertSame('urgency', $result['to']);

        $spec = $this->readSpec();
        $fields = $spec['fields'];
        $this->assertIsArray($fields);
        $this->assertSame(['id', 'urgency'], array_column($fields, 'column'));
    }

    public function test_rewrites_search_rules_filters_layout(): void
    {
        $this->seedResource([
            'search' => ['priority'],
            'rules' => ['priority' => 'required'],
            'filters' => [['column' => 'priority', 'type' => 'text']],
            'layout' => [
                'form' => [
                    'tree' => [
                        'type' => 'box',
                        'children' => [
                            ['type' => 'fields', 'columns' => ['priority', 'id']],
                        ],
                    ],
                    'groups' => [['label' => 'Main', 'columns' => ['priority']]],
                ],
            ],
        ]);

        $result = (new RenameFieldTool)->execute([
            'service' => $this->serviceSlug,
            'resource' => 'task',
            'from' => 'priority',
            'to' => 'urgency',
        ]);

        $counts = $result['references_updated'];
        $this->assertIsArray($counts);
        $this->assertSame(1, $counts['search']);
        $this->assertSame(1, $counts['rules']);
        $this->assertSame(1, $counts['filters']);
        $this->assertSame(1, $counts['layout_groups']);
        $this->assertSame(1, $counts['layout_tree']);

        $spec = $this->readSpec();
        $this->assertSame(['urgency'], $spec['search']);

        $rules = $spec['rules'];
        $this->assertIsArray($rules);
        $this->assertArrayHasKey('urgency', $rules);
        $this->assertArrayNotHasKey('priority', $rules);

        $filters = $spec['filters'];
        $this->assertIsArray($filters);
        $first = $filters[0];
        $this->assertIsArray($first);
        $this->assertSame('urgency', $first['column']);
    }

    public function test_rewrites_mutations_body_from_when_present_and_path_placeholders(): void
    {
        $this->seedResource([
            'mutations' => [
                'create' => ['method' => 'POST', 'path' => '/tasks', 'body_from' => ['priority']],
                'update' => [
                    [
                        'method' => 'PUT',
                        'path' => '/tasks/{id}/priority/{priority}',
                        'when_present' => 'priority',
                    ],
                ],
            ],
        ]);

        $result = (new RenameFieldTool)->execute([
            'service' => $this->serviceSlug,
            'resource' => 'task',
            'from' => 'priority',
            'to' => 'urgency',
        ]);

        $counts = $result['references_updated'];
        $this->assertIsArray($counts);
        $this->assertGreaterThanOrEqual(2, $counts['mutations']);

        $spec = $this->readSpec();
        $mutations = $spec['mutations'];
        $this->assertIsArray($mutations);
        $create = $mutations['create'];
        $this->assertIsArray($create);
        $this->assertSame(['urgency'], $create['body_from']);

        $update = $mutations['update'];
        $this->assertIsArray($update);
        $entry = $update[0];
        $this->assertIsArray($entry);
        $this->assertSame('urgency', $entry['when_present']);
        $path = $entry['path'];
        $this->assertIsString($path);
        $this->assertStringContainsString('/{urgency}', $path);
    }

    public function test_update_mutations_false_skips_mutation_rewrite(): void
    {
        $this->seedResource([
            'mutations' => [
                'create' => ['method' => 'POST', 'body_from' => ['priority']],
            ],
        ]);

        $result = (new RenameFieldTool)->execute([
            'service' => $this->serviceSlug,
            'resource' => 'task',
            'from' => 'priority',
            'to' => 'urgency',
            'update_mutations' => false,
        ]);

        $counts = $result['references_updated'];
        $this->assertIsArray($counts);
        $this->assertSame(0, $counts['mutations']);

        $spec = $this->readSpec();
        $mutations = $spec['mutations'];
        $this->assertIsArray($mutations);
        $create = $mutations['create'];
        $this->assertIsArray($create);
        $this->assertSame(['priority'], $create['body_from']);
    }

    public function test_rewrites_sibling_depends_on_and_endpoint_query(): void
    {
        $this->seedResource([
            'fields' => [
                ['column' => 'id', 'type' => 'id'],
                ['column' => 'project_id', 'type' => 'belongs_to'],
                [
                    'column' => 'status',
                    'type' => 'belongs_to',
                    'depends_on' => ['project_id'],
                    'endpoint_query' => ['project_id' => '{project_id}'],
                ],
            ],
        ]);

        $result = (new RenameFieldTool)->execute([
            'service' => $this->serviceSlug,
            'resource' => 'task',
            'from' => 'project_id',
            'to' => 'project_uuid',
        ]);

        $counts = $result['references_updated'];
        $this->assertIsArray($counts);
        $this->assertSame(1, $counts['depends_on']);
        $this->assertSame(1, $counts['endpoint_query']);

        $spec = $this->readSpec();
        $fields = $spec['fields'];
        $this->assertIsArray($fields);
        $statusField = $fields[2];
        $this->assertIsArray($statusField);
        $this->assertSame(['project_uuid'], $statusField['depends_on']);
        // Only the placeholder (value side) follows the local rename — the key
        // is the upstream API param name and stays bound to whatever the
        // upstream expects, regardless of our local column id.
        $this->assertSame(['project_id' => '{project_uuid}'], $statusField['endpoint_query']);
    }

    public function test_rejects_when_to_already_exists(): void
    {
        $this->seedResource([
            'fields' => [
                ['column' => 'id', 'type' => 'id'],
                ['column' => 'priority', 'type' => 'text'],
                ['column' => 'urgency', 'type' => 'text'],
            ],
        ]);

        $result = (new RenameFieldTool)->execute([
            'service' => $this->serviceSlug,
            'resource' => 'task',
            'from' => 'priority',
            'to' => 'urgency',
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('FIELD_ALREADY_EXISTS', $result['code']);
    }

    public function test_rejects_unknown_from(): void
    {
        $this->seedResource();

        $result = (new RenameFieldTool)->execute([
            'service' => $this->serviceSlug,
            'resource' => 'task',
            'from' => 'never_existed',
            'to' => 'whatever',
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('FIELD_NOT_FOUND', $result['code']);
    }

    public function test_rewrites_column_in_per_page_overrides(): void
    {
        $this->seedResource([
            'fields' => [
                ['column' => 'id', 'type' => 'id'],
                ['column' => 'priority', 'type' => 'text'],
            ],
            'form_fields' => [
                ['column' => 'priority', 'type' => 'text'],
            ],
            'index_fields' => [
                ['column' => 'priority', 'type' => 'text'],
            ],
        ]);

        $result = (new RenameFieldTool)->execute([
            'service' => $this->serviceSlug,
            'resource' => 'task',
            'from' => 'priority',
            'to' => 'urgency',
        ]);

        $this->assertSame('urgency', $result['to']);

        $spec = $this->readSpec();
        $form = $spec['form_fields'];
        $this->assertIsArray($form);
        $this->assertSame(['urgency'], array_column($form, 'column'));

        $index = $spec['index_fields'];
        $this->assertIsArray($index);
        $this->assertSame(['urgency'], array_column($index, 'column'));
    }

    public function test_rejects_when_from_equals_to(): void
    {
        $result = (new RenameFieldTool)->execute([
            'service' => $this->serviceSlug,
            'resource' => 'task',
            'from' => 'priority',
            'to' => 'priority',
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('VALIDATION', $result['code']);
    }
}
