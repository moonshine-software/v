<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Unit;

use App\ApiAdmin\MCP\Generator\ResourceGenerator;
use App\ApiAdmin\MCP\Tools\GetResourceTool;
use App\ApiAdmin\Providers\ApiAdminServiceProvider;
use Illuminate\Support\Facades\File;
use MoonShine\Laravel\Providers\MoonShineServiceProvider;
use MoonShine\MultiPanels\Providers\MultiPanelServiceProvider;
use Orchestra\Testbench\TestCase;

/**
 * `get_resource` reads the spec sidecar that `create_resource` /
 * `update_resource` write on every call. The point of the tool is to
 * let agents do partial updates without losing unrelated fields that
 * the full-replace `update_resource` would blow away.
 *
 * These tests use uniquely-named services so the sidecar paths can't
 * collide with real `.ai-factory/specs/*` entries in the working copy.
 * Each test cleans up its own sidecar in tearDown to avoid leaking
 * state into the VCS tree.
 */
class GetResourceToolTest extends TestCase
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
        $this->resourcesRoot = sys_get_temp_dir().'/moonshine-get-resource-resources-'.uniqid('', true);
        $app['config']->set('api-admin.resources_path', $this->resourcesRoot);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Uniquely-named test service so sidecar path is guaranteed
        // to not collide with any real spec in .ai-factory/specs.
        $this->serviceSlug = 'testsvc'.substr(str_replace('.', '', uniqid('', true)), 0, 10);
    }

    protected function tearDown(): void
    {
        // Wipe sidecars produced by this test — prevents leaking state
        // into the working copy's .ai-factory/specs tree.
        $sidecarDir = dirname(ResourceGenerator::specSidecarPath($this->serviceSlug, 'x'));
        if (File::isDirectory($sidecarDir)) {
            File::deleteDirectory($sidecarDir);
        }

        if (File::isDirectory($this->resourcesRoot)) {
            File::deleteDirectory($this->resourcesRoot);
        }

        parent::tearDown();
    }

    public function test_returns_spec_missing_when_no_sidecar_exists(): void
    {
        $tool = new GetResourceTool;

        $result = $tool->execute(['service' => $this->serviceSlug, 'name' => 'post']);

        $this->assertFalse($result['success']);
        $this->assertSame('SPEC_MISSING', $result['code']);
        $this->assertArrayHasKey('hint', $result);
    }

    public function test_returns_spec_written_by_generator(): void
    {
        // Generator writes the sidecar alongside the PHP files; the tool
        // reads it back verbatim minus identity keys (service, name).
        $generator = new ResourceGenerator;
        $_ = $generator->generate([
            'service' => $this->serviceSlug,
            'name' => 'post',
            'endpoint' => '/posts',
            'fields' => [
                ['column' => 'id', 'type' => 'id'],
                ['column' => 'title', 'type' => 'text', 'methods' => ['required', 'sortable']],
            ],
            'rules' => ['title' => 'required|string'],
            'icon' => 'document-text',
        ]);

        $tool = new GetResourceTool;
        $result = $tool->execute(['service' => $this->serviceSlug, 'name' => 'post']);

        $this->assertTrue($result['success']);
        $this->assertSame($this->serviceSlug, $result['service']);
        $this->assertSame('post', $result['name']);
        $spec = $result['spec'];
        $this->assertIsArray($spec);
        $this->assertSame('/posts', $spec['endpoint']);
        $this->assertIsArray($spec['fields']);
        $this->assertCount(2, $spec['fields']);
        $this->assertIsArray($spec['rules']);
        $this->assertSame('required|string', $spec['rules']['title']);
        $this->assertSame('document-text', $spec['icon']);
        // Identity keys stripped — sidecar path encodes them.
        $this->assertArrayNotHasKey('service', $spec);
        $this->assertArrayNotHasKey('name', $spec);
    }

    public function test_rejects_missing_required_params(): void
    {
        $tool = new GetResourceTool;

        $this->assertSame('VALIDATION', $tool->execute([])['code']);
        $this->assertSame('VALIDATION', $tool->execute(['service' => 'blog'])['code']);
    }

    public function test_returns_spec_corrupt_when_sidecar_is_not_valid_json(): void
    {
        $path = ResourceGenerator::specSidecarPath($this->serviceSlug, 'post');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, 'not-json');

        $tool = new GetResourceTool;
        $result = $tool->execute(['service' => $this->serviceSlug, 'name' => 'post']);

        $this->assertFalse($result['success']);
        $this->assertSame('SPEC_CORRUPT', $result['code']);
    }
}
