<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Unit;

use App\ApiAdmin\MCP\Generator\ResourceGenerator;
use App\ApiAdmin\MCP\ServiceRegistry;
use App\ApiAdmin\MCP\Tools\CreateResourceTool;
use App\ApiAdmin\MCP\Tools\GetResourceTool;
use App\ApiAdmin\MCP\Tools\UpdateResourceTool;
use App\Models\User;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use MoonShine\Contracts\Core\TypeCasts\DataWrapperContract;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use RuntimeException;

class ResourceGeneratorTest extends TestCase
{
    /** @return iterable<string, array{string, bool}> */
    public static function relatedResourceKinds(): iterable
    {
        foreach (['belongs_to', 'belongs_to_many', 'has_one', 'has_many'] as $type) {
            yield $type.' same service' => [$type, false];
            yield $type.' cross service' => [$type, true];
        }
    }

    #[DataProvider('relatedResourceKinds')]
    public function test_generator_links_registered_related_resource_mapping_on_fields_and_filters(string $type, bool $crossService): void
    {
        $generator = new ResourceGenerator;
        $targetService = $crossService ? 'settings_api' : 'tasky';
        $target = $generator->generate([
            'service' => $targetService, 'name' => 'setting', 'endpoint' => '/settings', 'fields' => [],
            'response_mapping' => ['primary_key' => 'name', 'pagination_type' => 'none'],
        ]);
        $originalTarget = File::get($target['file']);
        $field = [
            'column' => 'settings', 'type' => $type, 'endpoint' => '/settings', 'related_resource' => 'settings',
            'fields' => [['column' => 'ignored_inline_child', 'type' => 'text']],
        ];
        if ($crossService) {
            $field['target_service'] = $targetService;
        }
        $parent = $generator->generate([
            'service' => 'tasky', 'name' => 'project', 'endpoint' => '/projects',
            'fields' => [$field], 'filters' => [$field],
        ]);

        $expected = '->relatedResource(\\'.$target['class'].'::class)';
        foreach ($parent['pages'] as $page) {
            $code = File::get($page);
            $this->assertStringContainsString($expected, $code);
            $this->assertStringContainsString("->endpoint('/settings')", $code, 'Explicit linked-resource routes must not become parent-nested endpoints.');
            $this->assertStringNotContainsString('->valueKey(', $code, 'An omitted value_key must inherit the linked resource primary key.');
        }
        $this->assertSame(2, substr_count(File::get($parent['pages']['index']), $expected));
        $this->assertSame([], $parent['relationResources']);
        $this->assertSame($originalTarget, File::get($target['file']), 'Explicit targets must not be overwritten by inline child fields.');
    }

    /** @return iterable<string, array{string, array<string, bool|string>, bool}> */
    public static function globalHasManyEndpoints(): iterable
    {
        foreach ([false, true] as $crossService) {
            $label = $crossService ? 'cross service' : 'same service';
            yield 'permissions '.$label => ['permission', ['data_key' => '', 'pagination_type' => 'none'], $crossService];
            yield 'transcriptions '.$label => ['transcription', ['next_cursor_key' => 'meta.next_cursor'], $crossService];
        }
    }

    /** @param array<string, bool|string> $mapping */
    #[DataProvider('globalHasManyEndpoints')]
    public function test_linked_has_many_keeps_global_endpoint_and_scope_with_different_response_mappings(string $targetName, array $mapping, bool $crossService): void
    {
        $generator = new ResourceGenerator;
        $service = $crossService ? 'lookups' : 'tasky';
        $endpoint = '/'.($targetName === 'permission' ? 'permissions' : 'transcriptions');
        $target = $generator->generate([
            'service' => $service, 'name' => $targetName, 'endpoint' => $endpoint,
            'fields' => [], 'response_mapping' => $mapping,
        ]);
        $field = [
            'column' => $targetName, 'type' => 'has_many', 'endpoint' => $endpoint, 'related_resource' => $targetName,
            'endpoint_query' => ['project_id' => '{id}', 'scope' => 'root'],
        ];
        if ($crossService) {
            $field['target_service'] = $service;
        }
        $parent = $generator->generate([
            'service' => 'tasky', 'name' => 'task', 'endpoint' => '/tasks', 'fields' => [$field],
        ]);

        foreach ($parent['pages'] as $page) {
            $code = File::get($page);
            $this->assertStringContainsString("->endpoint('{$endpoint}')", $code);
            $this->assertStringContainsString('->relatedResource(\\'.$target['class'].'::class)', $code);
            $this->assertStringContainsString("->endpointQuery(['project_id' => '{id}', 'scope' => 'root'])", $code);
            $this->assertStringNotContainsString('/tasks/{parent_id}/', $code);
        }
        $this->assertSame(0, $this->lintPhp($parent['pages']['form']));
    }

    public function test_linked_has_many_preserves_an_explicit_parent_placeholder_endpoint(): void
    {
        $generator = new ResourceGenerator;
        (void) $generator->generate(['service' => 'tasky', 'name' => 'transcription', 'endpoint' => '/transcriptions', 'fields' => []]);
        $parent = $generator->generate([
            'service' => 'tasky', 'name' => 'task', 'endpoint' => '/tasks',
            'fields' => [[
                'column' => 'transcriptions', 'type' => 'has_many', 'related_resource' => 'transcription',
                'endpoint' => '/projects/{project_id}/transcriptions',
            ]],
        ]);

        $this->assertStringContainsString("->endpoint('/projects/{project_id}/transcriptions')", File::get($parent['pages']['form']));
    }

    public function test_explicit_id_value_key_overrides_the_related_resource_primary_key_on_fields_and_filters(): void
    {
        $generator = new ResourceGenerator;
        (void) $generator->generate([
            'service' => 'tasky', 'name' => 'setting', 'endpoint' => '/settings', 'fields' => [],
            'response_mapping' => ['primary_key' => 'name'],
        ]);
        $field = ['column' => 'setting_id', 'type' => 'belongs_to', 'endpoint' => '/settings', 'related_resource' => 'setting', 'value_key' => 'id'];
        $parent = $generator->generate([
            'service' => 'tasky', 'name' => 'project', 'endpoint' => '/projects', 'fields' => [$field], 'filters' => [$field],
        ]);

        $this->assertSame(2, substr_count(File::get($parent['pages']['index']), "->valueKey('id')"));
        $this->assertStringContainsString("->valueKey('id')", File::get($parent['pages']['form']));
    }

    public function test_response_mapping_and_related_resource_survive_get_resource_and_regeneration(): void
    {
        $generator = new ResourceGenerator;
        $mapping = ['primary_key' => 'name', 'pagination_type' => 'none', 'detail_strategy' => 'list'];
        $target = $generator->generate([
            'service' => 'tasky', 'name' => 'setting', 'endpoint' => '/settings', 'fields' => [], 'response_mapping' => $mapping,
        ]);
        $parent = $generator->generate([
            'service' => 'tasky', 'name' => 'project', 'endpoint' => '/projects',
            'fields' => [['column' => 'setting', 'type' => 'belongs_to', 'related_resource' => 'setting', 'endpoint' => '/settings']],
        ]);
        $targetCode = File::get($target['file']);
        $parentForm = File::get($parent['pages']['form']);
        $targetSpec = $this->readResourceSpec('tasky', 'setting');
        $this->assertSame($mapping, $targetSpec['response_mapping']);
        $targetRegenerated = $generator->generate([...$targetSpec, 'service' => 'tasky', 'name' => 'setting']);
        $this->assertSame($targetCode, File::get($targetRegenerated['file']));
        $parentSpec = $this->readResourceSpec('tasky', 'project');
        $parentRegenerated = $generator->generate([...$parentSpec, 'service' => 'tasky', 'name' => 'project']);
        $this->assertSame($parentForm, File::get($parentRegenerated['pages']['form']));
        $this->assertStringNotContainsString('protected array $responseMapping', File::get($parentRegenerated['file']));
    }

    /** @return array<string, mixed> */
    private function readResourceSpec(string $service, string $name): array
    {
        $result = new GetResourceTool()->execute(['service' => $service, 'name' => $name]);
        $this->assertTrue($result['success']);
        $spec = $result['spec'] ?? null;
        $this->assertIsArray($spec);
        $params = [];
        foreach ($spec as $key => $value) {
            $this->assertIsString($key);
            $params[$key] = $value;
        }

        return $params;
    }

    public function test_related_resource_uses_the_hosted_tenant_namespace(): void
    {
        config(['api-admin.hosted.mode' => 'hosted', 'api-admin.services' => ['tasky' => ['tenant_id' => 'local']]]);
        $generator = new ResourceGenerator;
        $target = $generator->generate(['service' => 'tasky', 'name' => 'setting', 'endpoint' => '/settings', 'fields' => []]);
        $parent = $generator->generate([
            'service' => 'tasky', 'name' => 'project', 'endpoint' => '/projects',
            'fields' => [['column' => 'setting', 'type' => 'belongs_to', 'related_resource' => 'setting']],
        ]);

        $this->assertStringContainsString('\\Tenants\\Local\\Tasky\\Setting\\', $target['class']);
        $this->assertStringContainsString('->relatedResource(\\'.$target['class'].'::class)', File::get($parent['pages']['form']));
    }

    public function test_related_resource_schema_is_available_on_every_field_list(): void
    {
        foreach ([CreateResourceTool::schema(), UpdateResourceTool::schema()] as $schema) {
            $input = $schema['inputSchema'] ?? null;
            $this->assertIsArray($input);
            $properties = $input['properties'] ?? null;
            $this->assertIsArray($properties);
            foreach (['fields', 'index_fields', 'form_fields', 'detail_fields', 'filters'] as $key) {
                $fieldList = $properties[$key] ?? null;
                $this->assertIsArray($fieldList);
                $items = $fieldList['items'] ?? null;
                $this->assertIsArray($items);
                $fieldProperties = $items['properties'] ?? null;
                $this->assertIsArray($fieldProperties);
                $this->assertArrayHasKey('related_resource', $fieldProperties);
            }
        }
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidRelatedResources(): iterable
    {
        yield 'arbitrary class' => [User::class];
        yield 'path traversal' => ['../setting'];
        yield 'PHP source' => ["setting'); phpinfo(); ('"];
        yield 'missing resource' => ['not_generated'];
        yield 'wrong type' => [[]];
    }

    #[DataProvider('invalidRelatedResources')]
    public function test_related_resource_requires_a_registered_resource_name(mixed $name): void
    {
        $this->expectException(InvalidArgumentException::class);
        (void) new ResourceGenerator()->generate([
            'service' => 'tasky', 'name' => 'project', 'endpoint' => '/projects',
            'fields' => [['column' => 'setting', 'type' => 'belongs_to', 'related_resource' => $name]],
        ]);
    }

    /** @param array<string, bool|string> $mapping */
    #[DataProvider('responseMappings')]
    public function test_generator_emits_partial_response_mapping_for_main_and_relation_resources(array $mapping, string $endpoint): void
    {
        $result = new ResourceGenerator()->generate([
            'service' => 'mapping',
            'name' => 'record',
            'endpoint' => $endpoint,
            'fields' => [['column' => 'name', 'type' => 'text']],
            'response_mapping' => $mapping,
        ]);

        $code = File::get($result['file']);
        $this->assertStringContainsString('protected array $responseMapping = [', $code);
        foreach ($mapping as $key => $value) {
            $this->assertStringContainsString("'{$key}' => ".var_export($value, true), $code);
        }
        $this->assertStringNotContainsString('current_page_key', $code, 'Overrides must remain partial so siblings and service defaults are preserved.');
        $this->assertStringNotContainsString('{{ responseMappingProperty }}', $code);
    }

    /** @return iterable<string, array{array<string, bool|string>, string}> */
    public static function responseMappings(): iterable
    {
        yield 'bare array' => [['data_key' => '', 'pagination_type' => 'none'], '/permissions'];
        yield 'nested cursor on relation' => [['next_cursor_key' => 'meta.next_cursor', 'supports_sorting' => false], '/projects/{parent_id}/logs'];
        yield 'name primary key' => [['primary_key' => 'name'], '/settings'];
    }

    public function test_response_mapping_schema_is_available_to_create_and_update(): void
    {
        $create = CreateResourceTool::schema()['inputSchema']['properties']['response_mapping'] ?? null;
        $updateInput = UpdateResourceTool::schema()['inputSchema'] ?? null;
        $this->assertIsArray($updateInput);
        $updateProperties = $updateInput['properties'] ?? null;
        $this->assertIsArray($updateProperties);
        $update = $updateProperties['response_mapping'] ?? null;

        $this->assertIsArray($create);
        $this->assertIsArray($update);
        $updateDescription = $update['description'] ?? null;
        $this->assertIsString($updateDescription);
        $this->assertStringContainsString('Omit to preserve', $updateDescription);
        unset($create['description'], $update['description']);
        $this->assertSame($create, $update);
        $properties = $create['properties'] ?? null;
        $this->assertIsArray($properties);
        $this->assertArrayHasKey('primary_key', $properties);
        $this->assertArrayHasKey('data_key', $properties);
        $this->assertArrayHasKey('next_cursor_key', $properties);
    }

    /** @param array<string, mixed>|string $mapping */
    #[DataProvider('invalidResponseMappings')]
    public function test_generator_rejects_invalid_response_mapping(array|string $mapping): void
    {
        $this->expectException(InvalidArgumentException::class);
        (void) new ResourceGenerator()->generate([
            'service' => 'mapping', 'name' => 'record', 'endpoint' => '/records',
            'fields' => [], 'response_mapping' => $mapping,
        ]);
    }

    /** @return iterable<string, array{array<string, mixed>|string}> */
    public static function invalidResponseMappings(): iterable
    {
        yield 'scalar' => ['data'];
        yield 'unknown key' => [['data_path' => 'data']];
        yield 'wrong scalar type' => [['primary_key' => 7]];
        yield 'wrong boolean type' => [['supports_sorting' => 'false']];
        yield 'unknown pagination' => [['pagination_type' => 'offset']];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $path = sys_get_temp_dir().'/moonshine-test-resources-'.bin2hex(random_bytes(8));
        config(['api-admin.resources_path' => $path, 'api-admin.specs_path' => $path.'/.specs']);
    }

    protected function tearDown(): void
    {
        $pathConfig = config('api-admin.resources_path');
        $path = is_string($pathConfig) ? $pathConfig : '';

        if ($path !== '' && File::isDirectory($path)) {
            File::deleteDirectory($path);
        }

        parent::tearDown();
    }

    public function test_generates_resource_directory_with_pages(): void
    {
        $generator = new ResourceGenerator;

        $result = $generator->generate([
            'service' => 'blog',
            'name' => 'post',
            'endpoint' => '/posts',
            'panel' => 'blog-admin',
            'fields' => [
                ['column' => 'id', 'type' => 'id'],
                ['column' => 'title', 'type' => 'text', 'label' => 'Title'],
                ['column' => 'body', 'type' => 'textarea', 'label' => 'Body'],
            ],
        ]);

        $this->assertArrayHasKey('file', $result);
        $this->assertArrayHasKey('pages', $result);
        $this->assertStringContainsString('PostResource', $result['className']);

        // Resource file lives inside its service + resource directory
        $this->assertStringEndsWith('/Blog/Post/PostResource.php', $result['file']);
        $this->assertFileExists($result['file']);

        // Pages are generated under Blog/Post/Pages/
        $this->assertFileExists($result['pages']['index']);
        $this->assertFileExists($result['pages']['form']);
        $this->assertFileExists($result['pages']['detail']);

        // Resource holds metadata only — no field definitions
        $resourceContent = File::get($result['file']);
        $this->assertStringContainsString('namespace App\MoonShine\ApiAdmin\Resources\Blog\Post;', $resourceContent);
        $this->assertStringContainsString('class PostResource extends MultiPanelRestResource', $resourceContent);
        $this->assertStringContainsString("protected string \$panel = 'blog-admin'", $resourceContent);
        $this->assertStringContainsString("return '/posts'", $resourceContent);
        $this->assertStringContainsString('PostIndexPage::class', $resourceContent);
        $this->assertStringContainsString('PostFormPage::class', $resourceContent);
        $this->assertStringContainsString('PostDetailPage::class', $resourceContent);
        $this->assertStringNotContainsString('Text::make', $resourceContent);
        $this->assertStringNotContainsString('protected function indexFields', $resourceContent);

        // Fields live on the pages
        $indexContent = File::get($result['pages']['index']);
        $this->assertStringContainsString('namespace App\MoonShine\ApiAdmin\Resources\Blog\Post\Pages;', $indexContent);
        $this->assertStringContainsString('use App\ApiAdmin\Pages\RestIndexPage as IndexPage;', $indexContent);
        $this->assertStringContainsString('final class PostIndexPage extends IndexPage', $indexContent);
        $this->assertStringContainsString('protected function fields(): iterable', $indexContent);
        $this->assertStringContainsString("Text::make('Title', 'title')", $indexContent);

        $formContent = File::get($result['pages']['form']);
        $this->assertStringContainsString('final class PostFormPage extends RestFormPage', $formContent);
        $this->assertStringContainsString("Textarea::make('Body', 'body')", $formContent);
        // Main form wraps fields in Box via fields() (native MoonShine pattern),
        // not via RestFormPage::mainLayer() — the stub must keep producing that shape.
        $this->assertStringContainsString('use MoonShine\\UI\\Components\\Layout\\Box;', $formContent);
        $this->assertStringContainsString('Box::make([', $formContent);

        $detailContent = File::get($result['pages']['detail']);
        $this->assertStringContainsString('final class PostDetailPage extends DetailPage', $detailContent);
        $this->assertStringContainsString('ID::make', $detailContent);
    }

    public function test_same_resource_name_in_different_services_does_not_collide(): void
    {
        $generator = new ResourceGenerator;

        $blogResult = $generator->generate([
            'service' => 'blog',
            'name' => 'post',
            'endpoint' => '/posts',
            'fields' => [['column' => 'id', 'type' => 'id']],
        ]);

        $cmsResult = $generator->generate([
            'service' => 'cms',
            'name' => 'post',
            'endpoint' => '/posts',
            'fields' => [['column' => 'id', 'type' => 'id']],
        ]);

        // Different files under different service directories
        $this->assertStringEndsWith('/Blog/Post/PostResource.php', $blogResult['file']);
        $this->assertStringEndsWith('/Cms/Post/PostResource.php', $cmsResult['file']);
        $this->assertNotEquals($blogResult['file'], $cmsResult['file']);

        // Generated class names are data; the classes need not exist in a fresh checkout.
        $this->assertSame('App\\MoonShine\\ApiAdmin\\Resources\\'.'Blog\\Post\\PostResource', $blogResult['class']);
        $this->assertSame('App\\MoonShine\\ApiAdmin\\Resources\\Cms\\Post\\PostResource', $cmsResult['class']);

        // Both files exist simultaneously
        $this->assertFileExists($blogResult['file']);
        $this->assertFileExists($cmsResult['file']);
    }

    public function test_generates_resource_with_relation_fields(): void
    {
        $generator = new ResourceGenerator;

        $result = $generator->generate([
            'service' => 'blog',
            'name' => 'article',
            'endpoint' => '/articles',
            'fields' => [
                ['column' => 'id', 'type' => 'id'],
                ['column' => 'category_id', 'type' => 'belongs_to', 'label' => 'Category', 'endpoint' => '/categories'],
            ],
        ]);

        // Relation fields live on pages
        $indexContent = File::get($result['pages']['index']);
        $this->assertStringContainsString('RestBelongsTo', $indexContent);
        $this->assertStringContainsString("->endpoint('/categories')", $indexContent);
    }

    public function test_generator_emits_target_service_for_cross_service_relation_fields(): void
    {
        $generator = new ResourceGenerator;

        $result = $generator->generate([
            'service' => 'blog',
            'name' => 'article',
            'endpoint' => '/articles',
            'fields' => [
                ['column' => 'id', 'type' => 'id'],
                [
                    'column' => 'brand_id',
                    'type' => 'belongs_to',
                    'label' => 'Brand',
                    'target_service' => 'shop',
                    'endpoint' => '/brands',
                ],
                [
                    'column' => 'same_service_category_id',
                    'type' => 'belongs_to',
                    'label' => 'Category',
                    'target_service' => 'blog',
                    'endpoint' => '/categories',
                ],
            ],
        ]);

        $indexContent = File::get($result['pages']['index']);
        $formContent = File::get($result['pages']['form']);
        $detailContent = File::get($result['pages']['detail']);

        foreach ([$indexContent, $formContent, $detailContent] as $content) {
            $this->assertStringContainsString("RestBelongsTo::make('Brand', 'brand_id')", $content);
            $this->assertStringContainsString("->targetService('shop')", $content);
            $this->assertStringContainsString("->endpoint('/brands')", $content);
        }
        $this->assertStringNotContainsString("->targetService('blog')", $indexContent);
    }

    public function test_generates_belongs_to_many_with_attach_endpoint(): void
    {
        $generator = new ResourceGenerator;

        $result = $generator->generate([
            'service' => 'blog',
            'name' => 'post',
            'endpoint' => '/posts',
            'fields' => [
                ['column' => 'id', 'type' => 'id'],
                ['column' => 'tags', 'type' => 'belongs_to_many', 'endpoint' => '/tags'],
            ],
        ]);

        $formContent = File::get($result['pages']['form']);
        $this->assertStringContainsString('RestBelongsToMany', $formContent);
        $this->assertStringContainsString("->endpoint('/tags')", $formContent);
        $this->assertStringContainsString("->attachEndpoint('/posts/{parent_id}/tags')", $formContent);
    }

    public function test_generates_belongs_to_many_with_custom_attach_endpoint(): void
    {
        $generator = new ResourceGenerator;

        $result = $generator->generate([
            'service' => 'blog',
            'name' => 'post',
            'endpoint' => '/posts',
            'fields' => [
                ['column' => 'id', 'type' => 'id'],
                ['column' => 'tags', 'type' => 'belongs_to_many', 'endpoint' => '/tags', 'attach_endpoint' => '/posts/{parent_id}/sync-tags'],
            ],
        ]);

        $formContent = File::get($result['pages']['form']);
        $this->assertStringContainsString("->attachEndpoint('/posts/{parent_id}/sync-tags')", $formContent);
    }

    public function test_generator_skips_cross_service_relation_resource_generation(): void
    {
        $generator = new ResourceGenerator;

        $result = $generator->generate([
            'service' => 'blog',
            'name' => 'post',
            'endpoint' => '/posts',
            'fields' => [
                ['column' => 'id', 'type' => 'id'],
                [
                    'column' => 'variants',
                    'type' => 'has_many',
                    'target_service' => 'shop',
                    'endpoint' => '/products/{parent_id}/variants',
                    'fields' => [
                        ['column' => 'name', 'type' => 'text'],
                    ],
                ],
            ],
        ]);

        $this->assertSame([], $result['relationResources']);
        $formContent = File::get($result['pages']['form']);
        $this->assertStringContainsString("RestHasMany::make('Variants', 'variants')", $formContent);
        $this->assertStringContainsString("->targetService('shop')", $formContent);
        $this->assertStringContainsString("->endpoint('/products/{parent_id}/variants')", $formContent);
        $this->assertStringNotContainsString('resource: VariantResource::class', $formContent);
    }

    public function test_generator_emits_password_with_raw_for_password_type(): void
    {
        // `type: password` must map to the native MoonShine Password with
        // `->raw()` auto-applied. `->raw()` (MoonShine 4.x) disables
        // Hasher::make on apply, so the raw value is POSTed to the upstream
        // API — upstream hashes it, and double-hashing would lock users out.
        // The previous RestPassword subclass became redundant once native
        // Password exposed a raw mode; generator is now the source of truth.
        $generator = new ResourceGenerator;

        $result = $generator->generate([
            'service' => 'blog',
            'name' => 'user',
            'endpoint' => '/users',
            'fields' => [
                ['column' => 'id', 'type' => 'id'],
                ['column' => 'password', 'type' => 'password'],
            ],
        ]);

        $formContent = File::get($result['pages']['form']);

        $this->assertStringContainsString('use MoonShine\\UI\\Fields\\Password;', $formContent);
        $this->assertStringContainsString("Password::make('Password', 'password')->raw()", $formContent);
        $this->assertStringNotContainsString('RestPassword', $formContent);
    }

    public function test_generator_filters_fields_by_hide_on_per_page(): void
    {
        // MoonShine 4+ has no hideOnForm/hideOnCreate/hideOnUpdate on
        // fields. Per-page visibility is now expressed by omitting the
        // field from that page's fields() — which is what the `hide_on`
        // spec key drives on the generator side.
        $generator = new ResourceGenerator;

        $result = $generator->generate([
            'service' => 'blog',
            'name' => 'post',
            'endpoint' => '/posts',
            'fields' => [
                ['column' => 'id', 'type' => 'id'],
                ['column' => 'title', 'type' => 'text'],
                ['column' => 'slug', 'type' => 'slug', 'hide_on' => ['form']],
                ['column' => 'created_at', 'type' => 'date', 'hide_on' => ['form', 'index']],
                ['column' => 'secret_token', 'type' => 'text', 'hide_on' => 'form'], // single-string shape
            ],
        ]);

        $index = File::get($result['pages']['index']);
        $form = File::get($result['pages']['form']);
        $detail = File::get($result['pages']['detail']);

        // slug: visible on index+detail, hidden on form.
        // Note: MoonShine ships no dedicated Slug field — the generator
        // aliases 'slug' to Text for UI parity.
        $this->assertStringContainsString("Text::make('Slug', 'slug')", $index);
        $this->assertStringContainsString("Text::make('Slug', 'slug')", $detail);
        $this->assertStringNotContainsString("'slug'", $form);

        // created_at: hidden on index AND form, visible only on detail
        $this->assertStringNotContainsString("'created_at'", $index);
        $this->assertStringNotContainsString("'created_at'", $form);
        $this->assertStringContainsString("'created_at'", $detail);

        // secret_token hidden on form only (single-string shape works too)
        $this->assertStringContainsString("'secret_token'", $index);
        $this->assertStringNotContainsString("'secret_token'", $form);
    }

    public function test_generator_drops_legacy_v2_v3_methods(): void
    {
        // MoonShine v2/v3 had hideOnForm / hideOnCreate / hideOnUpdate on
        // the base Field class. v4 removed them (per-page fields() replaces
        // that API), so if an agent picks these up from old docs the
        // generator must drop them instead of emitting a call that fails at
        // render time with "Call to undefined method".
        $generator = new ResourceGenerator;

        $result = $generator->generate([
            'service' => 'blog',
            'name' => 'post',
            'endpoint' => '/posts',
            'fields' => [
                ['column' => 'id', 'type' => 'id'],
                [
                    'column' => 'title',
                    'type' => 'text',
                    // First two are legacy, third is a made-up typo,
                    // last is genuine v4.
                    'methods' => ['hideOnForm', 'hideOnCreate', 'foobarizeInfinitely', 'required'],
                ],
            ],
        ]);

        $form = File::get($result['pages']['form']);

        $this->assertStringContainsString('->required()', $form);
        $this->assertStringNotContainsString('hideOnForm', $form);
        $this->assertStringNotContainsString('hideOnCreate', $form);
        $this->assertStringNotContainsString('foobarizeInfinitely', $form);
    }

    public function test_generator_never_emits_empty_or_trailing_invocation(): void
    {
        // Guards against the "Object of type Select is not callable" bug:
        // when an MCP caller passed garbage in `methods` (empty name, nested
        // arrays in args, etc.), the generator used to splice `->()` or
        // bare `()` into the field chain, which PHP then tried to invoke on
        // the previous expression's result at render time. The generator
        // must sanitize inputs so no trailing / empty invocation leaks out.
        $generator = new ResourceGenerator;

        $result = $generator->generate([
            'service' => 'taskmanager',
            'name' => 'task',
            'endpoint' => '/tasks',
            'fields' => [
                ['column' => 'id', 'type' => 'id'],
                [
                    'column' => 'status',
                    'type' => 'select',
                    'label' => 'Status',
                    // Deliberately hostile shapes — empty string, object
                    // without `name`, nested array `args`, null entry.
                    'methods' => [
                        'sortable',
                        '',
                        ['args' => ['oops']],
                        ['name' => ''],
                        ['name' => 'required'],
                        null,
                    ],
                    'options' => ['todo' => 'Todo', 'done' => 'Done'],
                ],
            ],
        ]);

        foreach (['index', 'form', 'detail'] as $page) {
            $content = File::get($result['pages'][$page]);

            $this->assertStringNotContainsString('])(),', $content, "{$page}: trailing () after options(...)");
            $this->assertStringNotContainsString('->()', $content, "{$page}: empty method invocation");
            $this->assertStringContainsString('->sortable()', $content);
            $this->assertStringContainsString('->required()', $content);
            $this->assertStringContainsString("->options(['todo' => 'Todo', 'done' => 'Done'])", $content);
        }
    }

    public function test_generator_emits_endpoint_query_on_relation_field(): void
    {
        $generator = new ResourceGenerator;

        $result = $generator->generate([
            'service' => 'tracker',
            'name' => 'project',
            'endpoint' => '/projects',
            'fields' => [
                ['column' => 'id', 'type' => 'id'],
                [
                    'column' => 'status_id',
                    'type' => 'belongs_to',
                    'endpoint' => '/statuses',
                    'endpoint_query' => ['for_entity' => 'project'],
                ],
            ],
        ]);

        $form = File::get($result['pages']['form']);

        $this->assertStringContainsString("->endpoint('/statuses')", $form);
        $this->assertStringContainsString("->endpointQuery(['for_entity' => 'project'])", $form);
        $this->assertSame(0, $this->lintPhp($result['pages']['form']));
    }

    public function test_generator_emits_default_query_property(): void
    {
        // Many scoped / multi-tenant APIs demand specific query params
        // just to return anything (e.g. `?for_entity=project` on statuses).
        // Without default_query, the index page errors on first render.
        $generator = new ResourceGenerator;

        $result = $generator->generate([
            'service' => 'tracker',
            'name' => 'status',
            'endpoint' => '/statuses',
            'fields' => [['column' => 'id', 'type' => 'id']],
            'default_query' => [
                'detail' => ['include' => 'meta'],
                // Hostile inputs — must be dropped silently
                'mutate' => ['nope' => 1],
                'index' => ['for_entity' => 'project', '' => 'bad-key', 'nested_obj' => ['x' => 1]],
            ],
        ]);

        $resource = File::get($result['file']);

        $this->assertStringContainsString('protected array $defaultQuery', $resource);
        $this->assertStringContainsString("'for_entity' => 'project'", $resource);
        $this->assertStringContainsString("'index' =>", $resource);
        // Nested object was not scalar/list → dropped, must not emit
        $this->assertStringNotContainsString("'nested_obj'", $resource);
        $this->assertSame(0, $this->lintPhp($result['file']));
    }

    public function test_generator_omits_default_query_when_absent(): void
    {
        $generator = new ResourceGenerator;

        $result = $generator->generate([
            'service' => 'blog',
            'name' => 'post',
            'endpoint' => '/posts',
            'fields' => [['column' => 'id', 'type' => 'id']],
        ]);

        $this->assertStringNotContainsString('$defaultQuery', File::get($result['file']));
        $this->assertSame(0, $this->lintPhp($result['file']));
    }

    public function test_generator_emits_post_list_request_property(): void
    {
        $generator = new ResourceGenerator;

        $result = $generator->generate([
            'service' => 'customers',
            'name' => 'customer',
            'endpoint' => '/customers/list',
            'fields' => [['column' => 'id', 'type' => 'id']],
            'list_request' => [
                'method' => 'POST',
                'body' => ['ids' => []],
            ],
        ]);

        $resource = File::get($result['file']);

        $this->assertStringContainsString('protected string $indexMethod = \'POST\';', $resource);
        $this->assertStringContainsString('protected array $indexBody =', $resource);
        $this->assertStringContainsString("'ids' => [", $resource);
        $this->assertSame(0, $this->lintPhp($result['file']));
    }

    public function test_generator_restricts_crud_actions_for_limited_apis(): void
    {
        // task-manager API allows CREATE/VIEW/UPDATE but returns 405 for
        // DELETE /tasks/{id}. The Resource must override activeActions() so
        // the delete button never renders and the delete route returns 403
        // before the HTTP 405 leaks to the user.
        $generator = new ResourceGenerator;

        $result = $generator->generate([
            'service' => 'taskmanager',
            'name' => 'task',
            'endpoint' => '/tasks',
            'fields' => [['column' => 'id', 'type' => 'id']],
            'actions' => ['create', 'view', 'update'],
        ]);

        $resource = File::get($result['file']);

        $this->assertStringContainsString('protected function activeActions()', $resource);
        $this->assertStringContainsString('Action::CREATE', $resource);
        $this->assertStringContainsString('Action::VIEW', $resource);
        $this->assertStringContainsString('Action::UPDATE', $resource);
        $this->assertStringNotContainsString('Action::DELETE', $resource);
        $this->assertStringNotContainsString('Action::MASS_DELETE', $resource);
        $this->assertSame(0, $this->lintPhp($result['file']));
    }

    public function test_generator_omits_active_actions_method_when_absent(): void
    {
        $generator = new ResourceGenerator;

        $result = $generator->generate([
            'service' => 'blog',
            'name' => 'post',
            'endpoint' => '/posts',
            'fields' => [['column' => 'id', 'type' => 'id']],
        ]);

        $resource = File::get($result['file']);

        $this->assertStringNotContainsString('activeActions', $resource);
        $this->assertStringNotContainsString('{{ actionsMethod }}', $resource);
        $this->assertSame(0, $this->lintPhp($result['file']));
    }

    public function test_generator_emits_mutations_property_for_cqrs_api(): void
    {
        $generator = new ResourceGenerator;

        $result = $generator->generate([
            'service' => 'taskmanager',
            'name' => 'task',
            'endpoint' => '/tasks',
            'fields' => [
                ['column' => 'id', 'type' => 'id'],
                ['column' => 'title', 'type' => 'text'],
                ['column' => 'description', 'type' => 'textarea'],
                ['column' => 'status', 'type' => 'select'],
                ['column' => 'assignee_id', 'type' => 'text'],
            ],
            'mutations' => [
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
                    // Hostile entries should be dropped silently
                    [],
                    ['method' => 'NOTREAL'],
                    'not-an-array',
                ],
            ],
        ]);

        $resource = File::get($result['file']);

        $this->assertStringContainsString('protected array $mutations =', $resource);
        $this->assertStringContainsString("'create' =>", $resource);
        $this->assertStringContainsString("'update' =>", $resource);
        $this->assertStringContainsString("'body_from' =>", $resource);
        $this->assertStringContainsString("'/tasks/{id}/status-transitions'", $resource);
        $this->assertStringContainsString("'target_status' => '{status}'", $resource);
        $this->assertStringContainsString("'when_present' => 'status'", $resource);
        $this->assertStringContainsString('409', $resource);
        $this->assertStringNotContainsString('NOTREAL', $resource);

        // Must parse as valid PHP
        $this->assertSame(0, $this->lintPhp($result['file']));
    }

    public function test_generator_omits_mutations_when_absent(): void
    {
        $generator = new ResourceGenerator;

        $result = $generator->generate([
            'service' => 'blog',
            'name' => 'post',
            'endpoint' => '/posts',
            'fields' => [['column' => 'id', 'type' => 'id']],
        ]);

        $resource = File::get($result['file']);

        $this->assertStringNotContainsString('$mutations', $resource);
        $this->assertStringNotContainsString('{{ mutationsProperty }}', $resource);
        $this->assertSame(0, $this->lintPhp($result['file']));
    }

    public function test_generator_emits_rules_method_when_rules_provided(): void
    {
        $generator = new ResourceGenerator;

        $result = $generator->generate([
            'service' => 'taskmanager',
            'name' => 'task',
            'endpoint' => '/tasks',
            'fields' => [
                ['column' => 'id', 'type' => 'id'],
                ['column' => 'title', 'type' => 'text'],
                ['column' => 'assignee_id', 'type' => 'text'],
            ],
            'rules' => [
                'title' => 'required|string|max:255',
                'assignee_id' => ['required', 'uuid'],
                // Hostile input — must not crash or leak
                'badcolumn; drop table' => 'required',
                'emptylist' => [],
                'nonsense' => 42,
            ],
        ]);

        $form = File::get($result['pages']['form']);

        $this->assertStringContainsString('protected function rules(', $form);
        $this->assertStringContainsString("'title' => 'required|string|max:255'", $form);
        $this->assertStringContainsString("'assignee_id' => ['required', 'uuid']", $form);
        $this->assertStringNotContainsString('badcolumn', $form);
        $this->assertStringNotContainsString('emptylist', $form);
        $this->assertStringNotContainsString('nonsense', $form);
        $this->assertStringContainsString(
            DataWrapperContract::class,
            $form,
        );

        // Syntactically valid PHP
        $this->assertSame(
            0,
            $this->lintPhp($result['pages']['form']),
            'Generated FormPage must parse as valid PHP: '.$form,
        );
    }

    public function test_generator_refuses_to_write_files_with_unreplaced_placeholders(): void
    {
        // Regression: when the stub picks up a new `{{ slot }}` but the
        // running MCP process still has an opcache-cached older
        // ResourceGenerator that doesn't know about it, str_replace
        // leaves the placeholder literal — and the file ends up with
        // e.g. `{{ groupMethod }}` at class level, producing a parse
        // error that blocks every subsequent autoload. This guard
        // throws BEFORE writing so the file on disk stays intact.
        $generator = new ResourceGenerator;

        // Simulate a stub whose placeholder the generator doesn't know
        // about by writing a temporary stub file with a bogus slot and
        // pointing File::get at it via the stubs dir. Easiest path: put
        // an extra unknown slot into the normal stub location via a
        // fixture file and directly test the guard method via reflection.
        $guard = new ReflectionMethod($generator, 'guardAgainstUnreplacedPlaceholders');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('{{ unknownSlot }}');
        $guard->invoke($generator, '<?php class X { {{ unknownSlot }} }', 'fake.stub');
    }

    public function test_generator_guard_passes_clean_content(): void
    {
        $generator = new ResourceGenerator;
        $guard = new ReflectionMethod($generator, 'guardAgainstUnreplacedPlaceholders');

        // No exception = success. Nothing to assert beyond "does not throw".
        $guard->invoke($generator, '<?php class X {}', 'fake.stub');
        $this->addToAssertionCount(1);
    }

    public function test_generator_emits_icon_attribute_when_icon_provided(): void
    {
        // `icon: "users"` should produce `#[Icon('users')]` directly
        // above the class declaration plus the matching `use` import,
        // so MoonShine's MenuItem picks up the sidebar glyph via
        // reflection without any extra wiring on the resource.
        $generator = new ResourceGenerator;

        $result = $generator->generate([
            'service' => 'tracker',
            'name' => 'user',
            'endpoint' => '/users',
            'fields' => [['column' => 'id', 'type' => 'id']],
            'icon' => 'users',
        ]);

        $resourceCode = File::get($result['file']);

        $this->assertStringContainsString('use MoonShine\\Support\\Attributes\\Icon;', $resourceCode);
        $this->assertStringContainsString("#[Icon('users')]", $resourceCode);
        $this->assertSame(0, $this->lintPhp($result['file']));
    }

    public function test_generator_omits_icon_attribute_and_import_when_icon_absent(): void
    {
        // Without an explicit icon we don't emit the attribute AND we
        // don't pull in the Icon import — keeps the generated file tidy
        // and avoids the "unused use" smell that IDEs flag.
        $generator = new ResourceGenerator;

        $result = $generator->generate([
            'service' => 'tracker',
            'name' => 'user',
            'endpoint' => '/users',
            'fields' => [['column' => 'id', 'type' => 'id']],
        ]);

        $resourceCode = File::get($result['file']);

        $this->assertStringNotContainsString('#[Icon(', $resourceCode);
        $this->assertStringNotContainsString('Support\\Attributes\\Icon', $resourceCode);
    }

    public function test_generator_drops_icon_with_unsafe_chars_silently(): void
    {
        // Defensive: if an MCP caller passes a newline or a double-quote
        // inside the icon name, we drop it rather than emit broken PHP.
        // Legitimate custom icon names (kebab-case, slashes for paths)
        // must still be respected.
        $generator = new ResourceGenerator;

        $result = $generator->generate([
            'service' => 'tracker',
            'name' => 'user',
            'endpoint' => '/users',
            'fields' => [['column' => 'id', 'type' => 'id']],
            'icon' => "broken\nname",
        ]);

        $resourceCode = File::get($result['file']);

        $this->assertStringNotContainsString('#[Icon(', $resourceCode);
        $this->assertSame(0, $this->lintPhp($result['file']));
    }

    public function test_generator_omits_get_group_when_no_group_provided(): void
    {
        // Default behavior: no inner MenuGroup. Previously the generator
        // auto-grouped every resource under a label derived from the
        // service name, which duplicated the panel title inside
        // single-service panels ("Tracker Admin" → "Tracker" → resources).
        // MultiPanelRestResource::getGroup() returns null by default;
        // omitting our override falls through to that.
        $generator = new ResourceGenerator;

        $result = $generator->generate([
            'service' => 'tracker',
            'name' => 'project',
            'endpoint' => '/projects',
            'fields' => [['column' => 'id', 'type' => 'id']],
        ]);

        $resourceCode = File::get($result['file']);

        $this->assertStringNotContainsString('public function getGroup', $resourceCode);
        $this->assertStringNotContainsString("return 'Tracker'", $resourceCode);
    }

    public function test_generator_emits_get_group_when_explicit_group_provided(): void
    {
        // Caller-supplied group gets emitted verbatim — enables
        // multi-group layouts within a single panel (e.g. Catalog +
        // Billing under one "Shop Admin" panel).
        $generator = new ResourceGenerator;

        $result = $generator->generate([
            'service' => 'shop',
            'name' => 'product',
            'endpoint' => '/products',
            'fields' => [['column' => 'id', 'type' => 'id']],
            'group' => 'Catalog',
        ]);

        $resourceCode = File::get($result['file']);

        $this->assertStringContainsString('public function getGroup(): ?string', $resourceCode);
        $this->assertStringContainsString("return 'Catalog';", $resourceCode);
    }

    public function test_generator_escapes_quote_in_group_label(): void
    {
        // Labels with quotes/backslashes must round-trip as valid PHP
        // rather than producing a syntax error. We don't advertise this
        // as a feature but the generator should survive hostile-ish
        // input from loose MCP callers.
        $generator = new ResourceGenerator;

        $result = $generator->generate([
            'service' => 'shop',
            'name' => 'product',
            'endpoint' => '/products',
            'fields' => [['column' => 'id', 'type' => 'id']],
            'group' => "Tom's \\ Catalog",
        ]);

        $this->assertSame(0, $this->lintPhp($result['file']));
    }

    public function test_generator_emits_endpoint_on_relation_filter(): void
    {
        // Regression: a belongs_to in `filters[]` used to emit just
        // `RestBelongsTo::make('Project', 'project_id')` with no
        // `->endpoint(...)` chain, so `loadOptions()` saw an empty
        // apiEndpoint and returned `[]` — the filter dropdown silently
        // rendered blank even when the API had data. The index-page
        // field with the same column worked because its spec went
        // through `buildFields()` which handled relation types; the
        // filter path had to grow the same wiring.
        $generator = new ResourceGenerator;

        $result = $generator->generate([
            'service' => 'tracker',
            'name' => 'task',
            'endpoint' => '/tasks',
            'fields' => [
                ['column' => 'id', 'type' => 'id'],
                ['column' => 'title', 'type' => 'text'],
            ],
            'filters' => [
                [
                    'column' => 'project_id',
                    'type' => 'belongs_to',
                    'label' => 'Project',
                    'endpoint' => '/projects',
                ],
                [
                    'column' => 'status_id',
                    'type' => 'belongs_to',
                    'label' => 'Status',
                    'endpoint' => '/statuses',
                    'endpoint_query' => ['for_entity' => 'project'],
                    'label_key' => 'name',
                    'value_key' => 'id',
                ],
                [
                    'column' => 'assignee_id',
                    'type' => 'belongs_to',
                    'label' => 'Assignee',
                    'target_service' => 'crm',
                    'endpoint' => '/users',
                    'label_key' => 'display_name',
                ],
            ],
        ]);

        $index = File::get($result['pages']['index']);

        // Every relation filter must carry its endpoint.
        $this->assertStringContainsString(
            "RestBelongsTo::make('Project', 'project_id')->endpoint('/projects'),",
            $index,
        );
        // endpoint_query is emitted as a literal array, not dropped.
        $this->assertStringContainsString(
            "RestBelongsTo::make('Status', 'status_id')->endpoint('/statuses')->valueKey('id')->endpointQuery(['for_entity' => 'project']),",
            $index,
        );
        // label_key override is emitted when it differs from the default.
        $this->assertStringContainsString(
            "->targetService('crm')->endpoint('/users')->labelKey('display_name'),",
            $index,
        );
        $this->assertSame(0, $this->lintPhp($result['pages']['index']));
    }

    public function test_generator_emits_per_resource_detail_strategy_override(): void
    {
        // Resource-level `detail_strategy: "list"` emits an override method
        // that AbstractRestResource::findItem() consults before falling back
        // to the service default. Use case: a service where most resources
        // have GET /{id} but statuses/tags expose only list + write.
        $generator = new ResourceGenerator;

        $result = $generator->generate([
            'service' => 'tracker',
            'name' => 'status',
            'endpoint' => '/statuses',
            'fields' => [['column' => 'id', 'type' => 'id'], ['column' => 'name', 'type' => 'text']],
            'detail_strategy' => 'list',
            'detail_list_key_param' => 'id',
        ]);

        $resourceCode = File::get($result['file']);

        $this->assertStringContainsString('protected function getDetailStrategyOverride(): ?string', $resourceCode);
        $this->assertStringContainsString("return 'list';", $resourceCode);
        $this->assertStringContainsString('protected function getDetailListKeyParamOverride(): ?string', $resourceCode);
        $this->assertStringContainsString("return 'id';", $resourceCode);
        $this->assertSame(0, $this->lintPhp($result['file']));
    }

    public function test_generator_omits_detail_strategy_override_when_absent(): void
    {
        // No override = no emitted override methods. Keeps service-level
        // default honored without noise in the generated class.
        $generator = new ResourceGenerator;

        $result = $generator->generate([
            'service' => 'tracker',
            'name' => 'user',
            'endpoint' => '/users',
            'fields' => [['column' => 'id', 'type' => 'id']],
        ]);

        $resourceCode = File::get($result['file']);

        $this->assertStringNotContainsString('getDetailStrategyOverride', $resourceCode);
        $this->assertStringNotContainsString('getDetailListKeyParamOverride', $resourceCode);
    }

    public function test_generator_emits_tabs_layout_preset(): void
    {
        // Tabs preset wraps groups in MoonShine's Tabs + Tab components.
        // Unlisted columns are appended to the last tab so nothing silently
        // drops — safer failure mode than a panic when the caller forgot a
        // column in groups.
        $generator = new ResourceGenerator;

        $result = $generator->generate([
            'service' => 'tracker',
            'name' => 'user',
            'endpoint' => '/users',
            'fields' => [
                ['column' => 'first_name', 'type' => 'text'],
                ['column' => 'last_name', 'type' => 'text'],
                ['column' => 'password', 'type' => 'password'],
                ['column' => 'note', 'type' => 'textarea'], // not mentioned — should land in last tab
            ],
            'layout' => [
                'form' => [
                    'type' => 'tabs',
                    'groups' => [
                        ['label' => 'Profile', 'columns' => ['first_name', 'last_name']],
                        ['label' => 'Access', 'columns' => ['password']],
                    ],
                ],
            ],
        ]);

        $form = File::get($result['pages']['form']);

        $this->assertStringContainsString('use MoonShine\\UI\\Components\\Tabs;', $form);
        $this->assertStringContainsString('use MoonShine\\UI\\Components\\Tabs\\Tab;', $form);
        $this->assertStringContainsString('Tabs::make([', $form);
        $this->assertStringContainsString("Tab::make('Profile', [", $form);
        $this->assertStringContainsString("Tab::make('Access', [", $form);
        // Unlisted column lands in the last tab
        $this->assertStringContainsString("'note'", $form);
        $this->assertSame(0, $this->lintPhp($result['pages']['form']));
    }

    public function test_generator_emits_two_column_layout_preset(): void
    {
        // Two-column preset wraps groups in Grid + Column components with
        // configurable span (defaults to 6, equal halves, span sums should
        // total 12 to fill the row).
        $generator = new ResourceGenerator;

        $result = $generator->generate([
            'service' => 'tracker',
            'name' => 'user',
            'endpoint' => '/users',
            'fields' => [
                ['column' => 'first_name', 'type' => 'text'],
                ['column' => 'last_name', 'type' => 'text'],
                ['column' => 'status', 'type' => 'text'],
                ['column' => 'telegram', 'type' => 'text'],
            ],
            'layout' => [
                'form' => [
                    'type' => 'two-column',
                    'groups' => [
                        ['label' => 'Main', 'span' => 8, 'columns' => ['first_name', 'last_name']],
                        ['label' => 'Meta', 'span' => 4, 'columns' => ['status', 'telegram']],
                    ],
                ],
            ],
        ]);

        $form = File::get($result['pages']['form']);

        $this->assertStringContainsString('use MoonShine\\UI\\Components\\Layout\\Grid;', $form);
        $this->assertStringContainsString('use MoonShine\\UI\\Components\\Layout\\Column;', $form);
        $this->assertStringContainsString('Grid::make([', $form);
        $this->assertStringContainsString('->columnSpan(8)', $form);
        $this->assertStringContainsString('->columnSpan(4)', $form);
        $this->assertSame(0, $this->lintPhp($result['pages']['form']));
    }

    public function test_generator_default_layout_unchanged_without_spec(): void
    {
        // No `layout` passed in → output must be byte-identical to the old
        // pre-preset behavior: a single Box wrapping all fields. This guards
        // against accidental regressions in the default path.
        $generator = new ResourceGenerator;

        $result = $generator->generate([
            'service' => 'blog',
            'name' => 'post',
            'endpoint' => '/posts',
            'fields' => [
                ['column' => 'title', 'type' => 'text'],
                ['column' => 'body', 'type' => 'textarea'],
            ],
        ]);

        $form = File::get($result['pages']['form']);

        $this->assertStringContainsString('Box::make([', $form);
        $this->assertStringNotContainsString('Tabs::make', $form);
        $this->assertStringNotContainsString('Grid::make', $form);
        $this->assertStringNotContainsString('Column::make', $form);
    }

    public function test_generator_emits_depends_on_for_cascade_fields(): void
    {
        // Status dropdown scoped by project — client-side cascade P2.
        // Generator must emit ->dependsOn(['project_id']) after endpointQuery.
        $generator = new ResourceGenerator;

        $result = $generator->generate([
            'service' => 'tracker',
            'name' => 'task',
            'endpoint' => '/tasks',
            'fields' => [
                ['column' => 'project_id', 'type' => 'belongs_to', 'endpoint' => '/projects', 'methods' => ['reactive']],
                [
                    'column' => 'status_id',
                    'type' => 'belongs_to',
                    'endpoint' => '/statuses',
                    'endpoint_query' => ['project_id' => '{project_id}'],
                    'depends_on' => ['project_id'],
                ],
            ],
        ]);

        $form = File::get($result['pages']['form']);

        $this->assertStringContainsString("->dependsOn(['project_id'])", $form);
        $this->assertStringContainsString("->endpointQuery(['project_id' => '{project_id}'])", $form);
        $this->assertStringContainsString('->reactive()', $form);
        $this->assertSame(0, $this->lintPhp($result['pages']['form']));
    }

    public function test_generator_skips_depends_on_when_unset(): void
    {
        $generator = new ResourceGenerator;

        $result = $generator->generate([
            'service' => 'blog',
            'name' => 'post',
            'endpoint' => '/posts',
            'fields' => [
                ['column' => 'category_id', 'type' => 'belongs_to', 'endpoint' => '/categories'],
            ],
        ]);

        $form = File::get($result['pages']['form']);

        $this->assertStringNotContainsString('->dependsOn(', $form);
    }

    public function test_generator_emits_recursive_layout_tree(): void
    {
        // Two-column tree: main content on the left (span 8), sidebar with
        // tabs on the right (span 4). Sidebar's single tab is a Collapse
        // around two fields. Verifies AST recursion + label emission +
        // columnSpan + Collapse import.
        $generator = new ResourceGenerator;

        $result = $generator->generate([
            'service' => 'blog',
            'name' => 'article',
            'endpoint' => '/articles',
            'fields' => [
                ['column' => 'title', 'type' => 'text'],
                ['column' => 'body', 'type' => 'textarea'],
                ['column' => 'slug', 'type' => 'slug'],
                ['column' => 'published_at', 'type' => 'datetime'],
            ],
            'layout' => [
                'form' => [
                    'tree' => [
                        'type' => 'grid',
                        'children' => [
                            [
                                'type' => 'column',
                                'span' => 8,
                                'children' => [
                                    [
                                        'type' => 'box',
                                        'children' => [
                                            ['type' => 'fields', 'columns' => ['title', 'body']],
                                        ],
                                    ],
                                ],
                            ],
                            [
                                'type' => 'column',
                                'span' => 4,
                                'children' => [
                                    [
                                        'type' => 'tabs',
                                        'children' => [
                                            [
                                                'type' => 'tab',
                                                'label' => 'Meta',
                                                'children' => [
                                                    [
                                                        'type' => 'collapse',
                                                        'label' => 'Publish',
                                                        'children' => [
                                                            ['type' => 'fields', 'columns' => ['slug', 'published_at']],
                                                        ],
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $form = File::get($result['pages']['form']);

        $this->assertStringContainsString('Grid::make([', $form);
        $this->assertStringContainsString('Column::make([', $form);
        $this->assertStringContainsString('->columnSpan(8)', $form);
        $this->assertStringContainsString('->columnSpan(4)', $form);
        $this->assertStringContainsString('Tabs::make([', $form);
        $this->assertStringContainsString("Tab::make('Meta', [", $form);
        $this->assertStringContainsString("Collapse::make('Publish', [", $form);
        $this->assertStringContainsString('use MoonShine\UI\Components\Collapse;', $form);
        $this->assertSame(0, $this->lintPhp($result['pages']['form']));
    }

    public function test_generator_appends_unclaimed_columns_after_layout_tree(): void
    {
        // Tree references only `title`. Remaining `body` must be appended in
        // a trailing Box so the generator never silently drops fields.
        $generator = new ResourceGenerator;

        $result = $generator->generate([
            'service' => 'blog',
            'name' => 'post',
            'endpoint' => '/posts',
            'fields' => [
                ['column' => 'title', 'type' => 'text'],
                ['column' => 'body', 'type' => 'textarea'],
            ],
            'layout' => [
                'form' => [
                    'tree' => [
                        'type' => 'box',
                        'children' => [
                            ['type' => 'fields', 'columns' => ['title']],
                        ],
                    ],
                ],
            ],
        ]);

        $form = File::get($result['pages']['form']);

        // body shows up twice if wired correctly: once in the fallback Box.
        $this->assertSame(1, substr_count($form, 'Textarea::make'));
        $this->assertSame(1, substr_count($form, 'Text::make'));
        $this->assertSame(2, substr_count($form, 'Box::make(['), 'main tree box + fallback box for unclaimed columns');
        $this->assertSame(0, $this->lintPhp($result['pages']['form']));
    }

    public function test_generator_splits_field_methods_per_mode_with_condition_arg(): void
    {
        // MoonShine's `required()` takes Closure|bool|null — so create-only
        // required becomes `->required($this->getItem() === null)`. That
        // produces the canonical UX: asterisk on the Create page, no
        // asterisk on Edit. Validation is driven separately via `rules`.
        $generator = new ResourceGenerator;

        $result = $generator->generate([
            'service' => 'tracker',
            'name' => 'user',
            'endpoint' => '/users',
            'fields' => [
                ['column' => 'email', 'type' => 'email', 'methods' => ['required']],
                ['column' => 'password', 'type' => 'password', 'methods' => [
                    'create' => ['required'],
                    'update' => [],
                ]],
            ],
        ]);

        $form = File::get($result['pages']['form']);

        // Shared method emitted unconditionally
        $this->assertStringContainsString("Text::make('Email', 'email')->required()", $form);
        // Create-only method emitted with the condition arg form (no ->when wrapper).
        // `->raw()` is auto-emitted before user methods for password type.
        $this->assertStringContainsString(
            "Password::make('Password', 'password')->raw()->required(\$this->getItem() === null)",
            $form,
        );
        $this->assertStringNotContainsString("Password::make('Password', 'password')->raw()->when", $form);
        $this->assertSame(0, $this->lintPhp($result['pages']['form']));
    }

    public function test_generator_wraps_update_only_method_in_when_when_no_condition_arg(): void
    {
        // `eye()` takes no args, so the condition-arg form isn't available
        // — the generator must fall back to `->when($cond, fn ($f) => $f->eye())`.
        // This keeps update-only UI tweaks possible without a hard-coded
        // whitelist of supported methods.
        $generator = new ResourceGenerator;

        $result = $generator->generate([
            'service' => 'tracker',
            'name' => 'user',
            'endpoint' => '/users',
            'fields' => [
                ['column' => 'password', 'type' => 'password', 'methods' => [
                    'create' => [],
                    'update' => ['eye'],
                ]],
            ],
        ]);

        $form = File::get($result['pages']['form']);

        $this->assertStringContainsString(
            '->when($this->getItem() !== null, fn ($f) => $f->eye())',
            $form,
        );
        $this->assertSame(0, $this->lintPhp($result['pages']['form']));
    }

    public function test_generator_collapses_split_methods_shared_in_both_modes(): void
    {
        // A method present in BOTH create and update buckets is shared and
        // emitted unconditionally — no dead `->when(true, …)` noise.
        $generator = new ResourceGenerator;

        $result = $generator->generate([
            'service' => 'tracker',
            'name' => 'user',
            'endpoint' => '/users',
            'fields' => [
                ['column' => 'email', 'type' => 'email', 'methods' => [
                    'create' => ['required', 'sortable'],
                    'update' => ['sortable'],
                ]],
            ],
        ]);

        $form = File::get($result['pages']['form']);

        // `sortable` is shared → unconditional; `required` is create-only
        // → condition-arg form.
        $this->assertStringContainsString(
            "Text::make('Email', 'email')->sortable()->required(\$this->getItem() === null)",
            $form,
        );
        $this->assertStringNotContainsString('->when(', $form);
    }

    public function test_generator_emits_split_methods_as_union_on_non_form_pages(): void
    {
        // Index / Detail don't have a notion of "create vs edit mode" — the
        // generator should render the union of create+update methods as a
        // flat chain on those pages. Otherwise conditional helpers would
        // reference `$this->getItem()` from a page that may not have one.
        $generator = new ResourceGenerator;

        $result = $generator->generate([
            'service' => 'tracker',
            'name' => 'user',
            'endpoint' => '/users',
            'fields' => [
                ['column' => 'email', 'type' => 'email', 'methods' => [
                    'create' => ['required'],
                    'update' => ['sortable'],
                ]],
            ],
        ]);

        $index = File::get($result['pages']['index']);

        $this->assertStringContainsString("Text::make('Email', 'email')", $index);
        $this->assertStringNotContainsString('$this->getItem()', $index);
        $this->assertStringNotContainsString('->when(', $index);
    }

    public function test_generator_splits_rules_per_column_per_mode(): void
    {
        // Per-column per-mode spec lets the caller make one column differ
        // between create and update while everything else stays shared.
        // Classic case: `password` required on create, nullable on edit so
        // leaving the field blank doesn't wipe the upstream value.
        $generator = new ResourceGenerator;

        $result = $generator->generate([
            'service' => 'tracker',
            'name' => 'user',
            'endpoint' => '/users',
            'fields' => [
                ['column' => 'id', 'type' => 'id'],
                ['column' => 'email', 'type' => 'email'],
                ['column' => 'password', 'type' => 'password'],
            ],
            'rules' => [
                'email' => 'required|email',
                'password' => [
                    'create' => 'required|string|min:8|confirmed',
                    'update' => 'nullable|string|min:8|confirmed',
                ],
            ],
        ]);

        $form = File::get($result['pages']['form']);

        $this->assertStringContainsString('if ($item->getKey() === null)', $form);
        $this->assertStringContainsString("'password' => 'required|string|min:8|confirmed'", $form);
        $this->assertStringContainsString("'password' => 'nullable|string|min:8|confirmed'", $form);
        // Shared column appears in BOTH branches
        $this->assertSame(
            2,
            substr_count($form, "'email' => 'required|email'"),
            'Shared email rule must appear in both create and update branches',
        );
        $this->assertSame(0, $this->lintPhp($result['pages']['form']));
    }

    public function test_generator_splits_rules_at_top_level(): void
    {
        // Top-level {create: {...}, update: {...}} is the most explicit shape.
        // Both sub-maps are emitted verbatim; no silent merging of shared keys.
        $generator = new ResourceGenerator;

        $result = $generator->generate([
            'service' => 'tracker',
            'name' => 'user',
            'endpoint' => '/users',
            'fields' => [
                ['column' => 'id', 'type' => 'id'],
                ['column' => 'email', 'type' => 'email'],
                ['column' => 'password', 'type' => 'password'],
            ],
            'rules' => [
                'create' => [
                    'email' => 'required|email|unique:users',
                    'password' => 'required|min:8',
                ],
                'update' => [
                    'email' => 'sometimes|email',
                    'password' => 'nullable|min:8',
                ],
            ],
        ]);

        $form = File::get($result['pages']['form']);

        $this->assertStringContainsString('if ($item->getKey() === null)', $form);
        $this->assertStringContainsString("'email' => 'required|email|unique:users'", $form);
        $this->assertStringContainsString("'email' => 'sometimes|email'", $form);
        $this->assertStringContainsString("'password' => 'required|min:8'", $form);
        $this->assertStringContainsString("'password' => 'nullable|min:8'", $form);
        $this->assertSame(0, $this->lintPhp($result['pages']['form']));
    }

    public function test_generator_collapses_per_mode_rules_when_create_equals_update(): void
    {
        // If the caller splits rules but ends up with identical create/update
        // maps, we collapse back to the single-array form — the $item branch
        // would be dead code, and readers expect a single rules() body when
        // no per-mode variance exists.
        $generator = new ResourceGenerator;

        $result = $generator->generate([
            'service' => 'blog',
            'name' => 'post',
            'endpoint' => '/posts',
            'fields' => [
                ['column' => 'title', 'type' => 'text'],
            ],
            'rules' => [
                'create' => ['title' => 'required|string'],
                'update' => ['title' => 'required|string'],
            ],
        ]);

        $form = File::get($result['pages']['form']);

        $this->assertStringNotContainsString('if ($item->getKey()', $form);
        $this->assertSame(1, substr_count($form, "'title' => 'required|string'"));
    }

    public function test_generator_omits_rules_method_when_rules_absent(): void
    {
        $generator = new ResourceGenerator;

        $result = $generator->generate([
            'service' => 'blog',
            'name' => 'post',
            'endpoint' => '/posts',
            'fields' => [
                ['column' => 'id', 'type' => 'id'],
                ['column' => 'title', 'type' => 'text'],
            ],
        ]);

        $form = File::get($result['pages']['form']);

        $this->assertStringNotContainsString('protected function rules(', $form);
        $this->assertSame(0, $this->lintPhp($result['pages']['form']));
    }

    /**
     * Run `php -l` on a generated file and return the exit code.
     * Treats a passing lint as 0, syntax error as non-zero.
     */
    private function lintPhp(string $path): int
    {
        $output = [];
        $code = 0;
        exec('php -l '.escapeshellarg($path).' 2>&1', $output, $code);

        return $code;
    }

    public function test_service_registry_parses_endpoint_not_group_name(): void
    {
        // Regression: ServiceRegistry::parseEndpoint used to match the first
        // `return '...';` in the file. On main resources `getGroup()` sits
        // above `endpoint()`, so the group name (e.g. "Blog") was captured
        // as the endpoint and then permanently written back by the next
        // update_resource call that didn't pass an explicit endpoint.
        $generator = new ResourceGenerator;

        $_ = $generator->generate([
            'service' => 'blog',
            'name' => 'post',
            'endpoint' => '/posts',
            'fields' => [['column' => 'id', 'type' => 'id']],
        ]);

        $registry = new ServiceRegistry;
        $resources = $registry->listResources('blog');
        $postRow = array_find($resources, fn ($r): bool => $r['base_name'] === 'Post');

        $this->assertNotNull($postRow, 'post resource must be listed after generate');
        $this->assertSame('/posts', $postRow['endpoint'], 'endpoint() return must be parsed, not getGroup()');
    }

    public function test_filter_generator_emits_options_for_select_filters(): void
    {
        // Before this fix, buildFilterFields dropped the `options` key, so a
        // Select filter rendered as an empty dropdown on the index page even
        // when the caller declared static choices (status, role, category...)
        $generator = new ResourceGenerator;

        $result = $generator->generate([
            'service' => 'blog',
            'name' => 'post',
            'endpoint' => '/posts',
            'fields' => [
                ['column' => 'id', 'type' => 'id'],
                ['column' => 'status', 'type' => 'select'],
            ],
            'filters' => [
                [
                    'column' => 'status',
                    'type' => 'select',
                    'label' => 'Status',
                    'options' => ['todo' => 'Todo', 'done' => 'Done'],
                ],
            ],
        ]);

        $indexContent = File::get($result['pages']['index']);

        $this->assertStringContainsString(
            "Select::make('Status', 'status')->options(['todo' => 'Todo', 'done' => 'Done'])",
            $indexContent,
        );
    }

    public function test_generator_expands_structured_method_args(): void
    {
        $generator = new ResourceGenerator;

        $result = $generator->generate([
            'service' => 'taskmanager',
            'name' => 'widget',
            'endpoint' => '/widgets',
            'fields' => [
                ['column' => 'id', 'type' => 'id'],
                [
                    'column' => 'title',
                    'type' => 'text',
                    'methods' => [
                        ['name' => 'placeholder', 'args' => ['Type here']],
                        ['name' => 'nullable', 'args' => [true]],
                    ],
                ],
            ],
        ]);

        $form = File::get($result['pages']['form']);

        $this->assertStringContainsString("->placeholder('Type here')", $form);
        $this->assertStringContainsString('->nullable(true)', $form);
    }

    public function test_generates_relation_resource_for_has_many(): void
    {
        $generator = new ResourceGenerator;

        $result = $generator->generate([
            'service' => 'blog',
            'name' => 'post',
            'endpoint' => '/posts',
            'fields' => [
                ['column' => 'id', 'type' => 'id'],
                ['column' => 'title', 'type' => 'text'],
                [
                    'column' => 'comments',
                    'type' => 'has_many',
                    'endpoint' => '/posts/{parent_id}/comments',
                    'fields' => [
                        ['column' => 'author_name', 'type' => 'text'],
                        ['column' => 'body', 'type' => 'text'],
                    ],
                ],
            ],
        ]);

        // Parent page references the generated relation resource
        $indexContent = File::get($result['pages']['index']);
        $this->assertStringContainsString('resource: CommentResource::class', $indexContent);

        $formContent = File::get($result['pages']['form']);
        $this->assertStringContainsString('->creatable()', $formContent);
        $this->assertStringContainsString('->editable()', $formContent);
        $this->assertStringContainsString('->removable()', $formContent);
        $this->assertStringNotContainsString('->deletable()', $formContent);

        // Detail/index pages render the relation read-only — no CRUD modifiers there.
        $detailContent = File::get($result['pages']['detail']);
        $this->assertStringNotContainsString('->creatable()', $detailContent);
        $this->assertStringNotContainsString('->editable()', $detailContent);
        $this->assertStringNotContainsString('->removable()', $detailContent);

        $indexContent2 = File::get($result['pages']['index']);
        $this->assertStringNotContainsString('->creatable()', $indexContent2);
        $this->assertStringNotContainsString('->editable()', $indexContent2);
        $this->assertStringNotContainsString('->removable()', $indexContent2);

        // Relation resource is generated under the same service directory with pages
        $this->assertArrayHasKey('comments', $result['relationResources']);
        $relResource = $result['relationResources']['comments'];
        $this->assertStringEndsWith('/Blog/Comment/CommentResource.php', $relResource['file']);
        $this->assertFileExists($relResource['file']);
        $this->assertEquals('CommentResource', $relResource['className']);
        $this->assertSame('App\\MoonShine\\ApiAdmin\\Resources\\'.'Blog\\Comment\\CommentResource', $relResource['class']);

        // Relation resource is AbstractRestResource and points to pages
        $relContent = File::get($relResource['file']);
        $this->assertStringContainsString('namespace App\MoonShine\ApiAdmin\Resources\Blog\Comment;', $relContent);
        $this->assertStringContainsString('class CommentResource extends AbstractRestResource', $relContent);
        $this->assertStringContainsString("resolveService('blog')", $relContent);
        $this->assertStringContainsString('CommentIndexPage::class', $relContent);
        $this->assertStringContainsString('CommentFormPage::class', $relContent);
        $this->assertStringContainsString('CommentDetailPage::class', $relContent);
        $this->assertStringNotContainsString('Text::make', $relContent);

        // Fields on the relation form page
        $resourcesPathRaw = config('api-admin.resources_path');
        $resourcesPath = is_string($resourcesPathRaw) ? $resourcesPathRaw : '';
        $relFormContent = File::get($resourcesPath.'/Blog/Comment/Pages/CommentFormPage.php');
        $this->assertStringContainsString("Text::make('Author Name', 'author_name')", $relFormContent);
        $this->assertStringContainsString('->required()', $relFormContent);
    }

    public function test_generates_relation_resource_for_has_one_with_crud_only_on_form(): void
    {
        $generator = new ResourceGenerator;

        $result = $generator->generate([
            'service' => 'blog',
            'name' => 'post',
            'endpoint' => '/posts',
            'fields' => [
                ['column' => 'id', 'type' => 'id'],
                [
                    'column' => 'author',
                    'type' => 'has_one',
                    'endpoint' => '/posts/{parent_id}/author',
                    'fields' => [
                        ['column' => 'name', 'type' => 'text'],
                    ],
                ],
            ],
        ]);

        $this->assertArrayHasKey('author', $result['relationResources']);

        $formContent = File::get($result['pages']['form']);
        $this->assertStringContainsString('RestHasOne', $formContent);
        $this->assertStringContainsString('resource: AuthorResource::class', $formContent);
        $this->assertStringContainsString('->creatable()', $formContent);
        $this->assertStringContainsString('->editable()', $formContent);
        $this->assertStringContainsString('->removable()', $formContent);

        $detailContent = File::get($result['pages']['detail']);
        $this->assertStringContainsString('RestHasOne', $detailContent);
        $this->assertStringNotContainsString('->creatable()', $detailContent);
        $this->assertStringNotContainsString('->editable()', $detailContent);
        $this->assertStringNotContainsString('->removable()', $detailContent);
    }

    /**
     * Regression: update_resource on a relation-only resource (e.g. Author with
     * endpoint /posts/{parent_id}/author) used to re-emit the main-resource
     * stub — panel + getGroup + MultiPanelRestResource — which caused a
     * 500 when the admin opened /blog-admin/resource/author-resource/*.
     * The generator must now detect the kind from the endpoint and pick
     * relation-resource.stub.
     */
    public function test_generator_uses_relation_stub_when_endpoint_has_parent_placeholder(): void
    {
        $generator = new ResourceGenerator;

        $result = $generator->generate([
            'service' => 'blog',
            'name' => 'author',
            'endpoint' => '/posts/{parent_id}/author',
            'fields' => [
                ['column' => 'id', 'type' => 'id'],
                ['column' => 'name', 'type' => 'text'],
            ],
        ]);

        $content = File::get($result['file']);

        $this->assertStringContainsString('extends AbstractRestResource', $content);
        $this->assertStringNotContainsString('extends MultiPanelRestResource', $content);
        $this->assertStringNotContainsString('protected string $panel', $content);
        $this->assertStringNotContainsString('getGroup()', $content);
    }

    public function test_generator_uses_main_stub_when_kind_is_main(): void
    {
        $generator = new ResourceGenerator;

        $result = $generator->generate([
            'service' => 'blog',
            'name' => 'post',
            'endpoint' => '/posts',
            'fields' => [['column' => 'id', 'type' => 'id']],
        ]);

        $content = File::get($result['file']);

        $this->assertStringContainsString('extends MultiPanelRestResource', $content);
        $this->assertStringContainsString('protected string $panel', $content);
    }

    public function test_generator_kind_override_wins_over_endpoint_heuristic(): void
    {
        $generator = new ResourceGenerator;

        $result = $generator->generate([
            'service' => 'blog',
            'name' => 'custom',
            'endpoint' => '/custom/{strange}/endpoint',
            'kind' => 'main',
            'fields' => [['column' => 'id', 'type' => 'id']],
        ]);

        $content = File::get($result['file']);

        $this->assertStringContainsString('extends MultiPanelRestResource', $content);
    }

    /**
     * Developer may want an entity (e.g. Author) both as standalone `main`
     * resource AND as a `has_one` target inside a parent (Post). When the
     * parent is generated, the relation resource already exists — we must
     * reuse it, not overwrite with the slim relation-only shape.
     */
    public function test_generator_reuses_existing_resource_for_has_one(): void
    {
        $generator = new ResourceGenerator;

        // 1. Create Author as a standalone main resource first.
        $authorResult = $generator->generate([
            'service' => 'blog',
            'name' => 'author',
            'endpoint' => '/authors',
            'fields' => [
                ['column' => 'id', 'type' => 'id'],
                ['column' => 'name', 'type' => 'text'],
                ['column' => 'email', 'type' => 'email'],
            ],
        ]);

        $authorBefore = File::get($authorResult['file']);
        $this->assertStringContainsString('extends MultiPanelRestResource', $authorBefore);

        // 2. Now generate Post with has_one: author + sub-fields.
        $postResult = $generator->generate([
            'service' => 'blog',
            'name' => 'post',
            'endpoint' => '/posts',
            'fields' => [
                ['column' => 'id', 'type' => 'id'],
                ['column' => 'title', 'type' => 'text'],
                [
                    'column' => 'author',
                    'type' => 'has_one',
                    'endpoint' => '/posts/{parent_id}/author',
                    'fields' => [
                        ['column' => 'name', 'type' => 'text'],
                    ],
                ],
            ],
        ]);

        // AuthorResource must still be the main resource — not overwritten.
        $authorAfter = File::get($authorResult['file']);
        $this->assertSame($authorBefore, $authorAfter, 'Existing Author resource must be left intact when referenced by a has_one relation');

        // Parent (Post) still references AuthorResource::class in its form page.
        $formContent = File::get($postResult['pages']['form']);
        $this->assertStringContainsString('resource: AuthorResource::class', $formContent);
        $this->assertArrayHasKey('author', $postResult['relationResources']);
    }

    public function test_select_field_emits_options(): void
    {
        $generator = new ResourceGenerator;

        $result = $generator->generate([
            'service' => 'blog',
            'name' => 'post',
            'endpoint' => '/posts',
            'fields' => [
                ['column' => 'id', 'type' => 'id'],
                [
                    'column' => 'status',
                    'type' => 'select',
                    'options' => [
                        'draft' => 'Draft',
                        'published' => 'Published',
                    ],
                ],
            ],
        ]);

        $formContent = File::get($result['pages']['form']);
        $this->assertStringContainsString('use MoonShine\UI\Fields\Select;', $formContent);
        $this->assertStringContainsString(
            "Select::make('Status', 'status')->options(['draft' => 'Draft', 'published' => 'Published'])",
            $formContent,
        );
    }

    public function test_select_options_ignored_for_non_select_types(): void
    {
        $generator = new ResourceGenerator;

        $result = $generator->generate([
            'service' => 'blog',
            'name' => 'post',
            'endpoint' => '/posts',
            'fields' => [
                ['column' => 'id', 'type' => 'id'],
                [
                    'column' => 'title',
                    'type' => 'text',
                    'options' => ['ignored' => 'value'],
                ],
            ],
        ]);

        $formContent = File::get($result['pages']['form']);
        $this->assertStringNotContainsString('->options(', $formContent);
    }

    public function test_generates_resource_with_methods_on_pages(): void
    {
        $generator = new ResourceGenerator;

        $result = $generator->generate([
            'service' => 'shop',
            'name' => 'product',
            'endpoint' => '/products',
            'fields' => [
                ['column' => 'id', 'type' => 'id', 'methods' => ['sortable']],
                ['column' => 'name', 'type' => 'text', 'methods' => ['sortable', 'required']],
            ],
            'search' => ['name', 'sku'],
        ]);

        // Search lives on the resource
        $resourceContent = File::get($result['file']);
        $this->assertStringContainsString("'name'", $resourceContent);
        $this->assertStringContainsString("'sku'", $resourceContent);

        // Methods live on the fields (which are on pages)
        $indexContent = File::get($result['pages']['index']);
        $this->assertStringContainsString('->sortable()', $indexContent);
        $this->assertStringContainsString('->required()', $indexContent);
    }
}
