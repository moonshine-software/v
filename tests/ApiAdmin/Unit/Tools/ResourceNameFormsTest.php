<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Unit\Tools;

use App\ApiAdmin\MCP\Generator\ResourceGenerator;
use App\ApiAdmin\MCP\ResourceActionResolver;
use App\ApiAdmin\MCP\ResourceLocator;
use App\ApiAdmin\MCP\ServiceRegistry;
use App\ApiAdmin\MCP\SpecStore\SpecStore;
use App\ApiAdmin\MCP\Tools\AddFieldTool;
use App\ApiAdmin\MCP\Tools\GetResourceTool;
use App\ApiAdmin\MCP\Tools\RemoveResourceTool;
use App\ApiAdmin\MCP\Tools\UpdateResourceTool;
use App\ApiAdmin\Providers\ApiAdminServiceProvider;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use MoonShine\Laravel\Providers\MoonShineServiceProvider;
use MoonShine\MultiPanels\Providers\MultiPanelServiceProvider;
use Orchestra\Testbench\TestCase;

/**
 * Agents use singular and plural resource names interchangeably. Every tool must
 * reach the one resource that exists, whichever form created it.
 */
final class ResourceNameFormsTest extends TestCase
{
    private string $service;

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
        $this->resourcesRoot = sys_get_temp_dir().'/moonshine-name-forms-'.uniqid('', true);
        $app['config']->set('api-admin.resources_path', $this->resourcesRoot);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = 'namesvc'.substr(str_replace('.', '', uniqid('', true)), 0, 8);

        config(["api-admin.services.{$this->service}" => [
            'config' => "App\\MoonShine\\ApiAdmin\\Configs\\{$this->service}Config",
            'panel' => $this->service.'-admin',
            'enabled' => true,
        ]]);
    }

    protected function tearDown(): void
    {
        $sidecarDirectory = dirname(SpecStore::path($this->service, 'x'));
        if (File::isDirectory($sidecarDirectory)) {
            File::deleteDirectory($sidecarDirectory);
        }

        if (File::isDirectory($this->resourcesRoot)) {
            File::deleteDirectory($this->resourcesRoot);
        }

        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array{file: string, class: string}
     */
    private function generate(string $name, array $extra = []): array
    {
        $result = (new ResourceGenerator)->generate([
            'service' => $this->service,
            'name' => $name,
            'endpoint' => '/items',
            'panel' => $this->service.'-admin',
            'fields' => [
                ['column' => 'id', 'type' => 'id'],
                ['column' => 'title', 'type' => 'text'],
            ],
            ...$extra,
        ]);

        return ['file' => $result['file'], 'class' => $result['class']];
    }

    private function resourceDirectory(string $baseName): string
    {
        return $this->resourcesRoot.'/'.Str::studly($this->service).'/'.$baseName;
    }

    public function test_every_form_of_a_plural_name_resolves_to_the_generated_resource(): void
    {
        $generated = $this->generate('categories');
        $registry = new ServiceRegistry;

        foreach (['categories', 'category', 'Categories', 'Category'] as $form) {
            $this->assertSame('Categories', $registry->resourceBaseName($this->service, $form), $form);
        }

        require_once $generated['file'];

        // The data tools failed here: they looked for Category/CategoryResource.
        $this->assertSame($generated['class'], (new ResourceLocator)->resolveClass($this->service, 'categories'));
        $this->assertSame($generated['class'], (new ResourceLocator)->resolveClass($this->service, 'category'));
    }

    public function test_exact_form_wins_when_both_directories_exist(): void
    {
        $this->generate('news_item');
        File::ensureDirectoryExists($this->resourceDirectory('NewsItems'));

        $registry = new ServiceRegistry;

        $this->assertSame('NewsItem', $registry->resourceBaseName($this->service, 'news_item'));
        $this->assertSame('NewsItems', $registry->resourceBaseName($this->service, 'news_items'));
    }

    public function test_new_resource_keeps_the_given_name(): void
    {
        $this->assertSame('Tags', (new ServiceRegistry)->resourceBaseName($this->service, 'tags'));
        $this->assertSame('Tag', (new ServiceRegistry)->resourceBaseName($this->service, 'tag'));
    }

    public function test_remove_resource_deletes_a_plural_resource(): void
    {
        $this->generate('categories');

        // Used to answer success with removed_paths: [] and leave the files.
        $result = (new RemoveResourceTool)->execute([
            'service' => $this->service,
            'name' => 'categories',
            'confirm' => true,
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame([$this->resourceDirectory('Categories')], $result['removed_paths']);
        $this->assertDirectoryDoesNotExist($this->resourceDirectory('Categories'));
    }

    public function test_remove_resource_is_still_idempotent_for_an_unknown_name(): void
    {
        $result = (new RemoveResourceTool)->execute([
            'service' => $this->service,
            'name' => 'missing',
            'confirm' => true,
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame([], $result['removed_paths']);
    }

    public function test_update_with_the_plural_form_rewrites_the_existing_singular_resource(): void
    {
        $this->generate('post');

        $result = (new UpdateResourceTool)->execute([
            'service' => $this->service,
            'name' => 'posts',
            'fields' => [
                ['column' => 'id', 'type' => 'id'],
                ['column' => 'headline', 'type' => 'text'],
            ],
        ]);

        $this->assertArrayHasKey('resource', $result);
        $this->assertSame('PostResource', $result['resource']);
        $this->assertDirectoryExists($this->resourceDirectory('Post'));
        $this->assertDirectoryDoesNotExist($this->resourceDirectory('Posts'));
        $this->assertStringContainsString('headline', File::get($this->resourceDirectory('Post').'/Pages/PostFormPage.php'));

        // One sidecar, so the next read sees the update.
        $spec = SpecStore::read($this->service, 'post');
        $this->assertIsArray($spec);
        $this->assertNotNull(SpecStore::fieldByColumn($spec, 'headline'));
        $this->assertFileDoesNotExist(dirname(SpecStore::path($this->service, 'post')).'/posts.json');
    }

    public function test_spec_tools_read_the_sidecar_through_either_form(): void
    {
        $this->generate('post');

        $spec = (new GetResourceTool)->execute(['service' => $this->service, 'name' => 'posts']);

        $this->assertTrue($spec['success']);
        $this->assertSame(SpecStore::path($this->service, 'post'), $spec['sidecar_path']);
    }

    public function test_delta_tools_find_a_plural_resource_through_the_singular_form(): void
    {
        $this->generate('categories');

        $result = (new AddFieldTool)->execute([
            'service' => $this->service,
            'resource' => 'category',
            'field' => ['column' => 'slug', 'type' => 'text'],
        ]);

        // Generated classes are not autoloadable from the temporary path, so the
        // validation step reports that; the resource itself must have been found.
        $this->assertArrayNotHasKey('code', $result);
        $this->assertStringContainsString('slug', File::get($this->resourceDirectory('Categories').'/Pages/CategoriesFormPage.php'));
    }

    public function test_action_allowlist_cannot_be_escaped_through_the_other_form(): void
    {
        $this->generate('post', ['actions' => ['view']]);

        $this->assertSame(['view'], ResourceActionResolver::allowed($this->service, 'post'));
        $this->assertSame(['view'], ResourceActionResolver::allowed($this->service, 'posts'));
    }
}
