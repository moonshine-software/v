<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Integration;

use App\ApiAdmin\MCP\Generator\ResourceGenerator;
use App\ApiAdmin\MCP\Tools\UpdateResourceItemTool;
use App\ApiAdmin\Providers\ApiAdminServiceProvider;
use App\ApiAdmin\ServiceConfig\ResponseMapping;
use App\ApiAdmin\ServiceConfig\ServiceConfig;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use MoonShine\Laravel\Providers\MoonShineServiceProvider;
use MoonShine\MultiPanels\Providers\MultiPanelServiceProvider;
use Orchestra\Testbench\TestCase;

final class UpdateResourceItemToolTest extends TestCase
{
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
        $app['config']->set('api-admin.resources_path', __DIR__.'/../Fixtures/Resources');
        $app['config']->set('api-admin.resources_namespace', 'Tests\\ApiAdmin\\Fixtures\\Resources');
    }

    protected function setUp(): void
    {
        parent::setUp();

        app()->forgetInstance('api-admin.service.blog');
        app()->instance(
            'api-admin.service.blog',
            new ServiceConfig(
                name: 'blog',
                baseUrl: 'https://api.blog.test/v1',
                apiKey: '',
                responseMapping: new ResponseMapping(primaryKey: 'id'),
                authType: 'bearer',
            ),
        );
    }

    public function test_update_puts_payload_and_returns_refreshed_item(): void
    {
        Http::fake([
            'https://api.blog.test/v1/posts/1' => Http::sequence()
                ->push(['id' => 1, 'title' => 'Old'], 200) // findItem pre-check
                ->push(['id' => 1, 'title' => 'New'], 200), // PUT response
        ]);

        $result = (new UpdateResourceItemTool)->execute([
            'service' => 'blog',
            'resource' => 'post',
            'id' => 1,
            'payload' => ['title' => 'New'],
        ]);

        $this->assertTrue($result['success'], (string) json_encode($result));
        $this->assertSame(1, $result['primary_key_value']);
        $item = $result['item'];
        $this->assertIsArray($item);
        $this->assertSame('New', $item['title']);

        Http::assertSent(fn (Request $r): bool => $r->method() === 'PUT'
            && str_contains($r->url(), '/posts/1'));
    }

    public function test_404_on_precheck_surfaces_as_not_found_code(): void
    {
        Http::fake([
            'https://api.blog.test/v1/posts/999' => Http::response(null, 404),
        ]);

        $result = (new UpdateResourceItemTool)->execute([
            'service' => 'blog',
            'resource' => 'post',
            'id' => 999,
            'payload' => ['title' => 'x'],
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('NOT_FOUND', $result['code']);
    }

    public function test_refuses_when_spec_actions_exclude_update(): void
    {
        $path = ResourceGenerator::specSidecarPath('blog', 'post');
        File::ensureDirectoryExists(dirname($path));
        $existing = File::exists($path) ? File::get($path) : null;

        try {
            $encoded = json_encode(['actions' => ['view']]);
            File::put($path, $encoded === false ? '{}' : $encoded);

            $result = (new UpdateResourceItemTool)->execute([
                'service' => 'blog',
                'resource' => 'post',
                'id' => 1,
                'payload' => ['title' => 'x'],
            ]);

            $this->assertFalse($result['success']);
            $this->assertSame('ACTION_NOT_ALLOWED', $result['code']);
        } finally {
            if ($existing !== null) {
                File::put($path, $existing);
            } else {
                File::delete($path);
            }
        }
    }

    public function test_validation_fails_without_payload(): void
    {
        $result = (new UpdateResourceItemTool)->execute([
            'service' => 'blog',
            'resource' => 'post',
            'id' => 1,
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('VALIDATION', $result['code']);
    }
}
