<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Integration;

use App\ApiAdmin\MCP\Generator\ResourceGenerator;
use App\ApiAdmin\MCP\Tools\CreateResourceItemTool;
use App\ApiAdmin\Providers\ApiAdminServiceProvider;
use App\ApiAdmin\ServiceConfig\ResponseMapping;
use App\ApiAdmin\ServiceConfig\ServiceConfig;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use MoonShine\Laravel\Providers\MoonShineServiceProvider;
use MoonShine\MultiPanels\Providers\MultiPanelServiceProvider;
use Orchestra\Testbench\TestCase;

final class CreateResourceItemToolTest extends TestCase
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

    protected function tearDown(): void
    {
        // Scrub any stub sidecars we wrote under specs/blog-test.
        $dir = base_path('.ai-factory/specs/blog-test');
        if (File::isDirectory($dir)) {
            File::deleteDirectory($dir);
        }

        parent::tearDown();
    }

    public function test_schema_requires_service_resource_payload(): void
    {
        $schema = CreateResourceItemTool::schema();

        $this->assertSame('create_resource_item', $schema['name']);
        $inputSchema = $schema['inputSchema'];
        $this->assertIsArray($inputSchema);
        $this->assertSame(['service', 'resource', 'payload'], $inputSchema['required']);
    }

    public function test_missing_payload_returns_validation_error(): void
    {
        $result = (new CreateResourceItemTool)->execute([
            'service' => 'blog',
            'resource' => 'post',
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('VALIDATION', $result['code']);
    }

    public function test_creates_post_and_returns_primary_key(): void
    {
        // Scope the Http fake to POST-only for /posts — BlogApiService::fake()
        // registers `/posts*` ahead of `/posts`, so its list-response intercepts
        // this POST first. Narrow fake here keeps the assertion signal clean.
        Http::fake([
            'https://api.blog.test/v1/posts' => function (Request $r) {
                $data = $r->data();

                return Http::response(
                    [
                        'id' => 99,
                        'title' => is_string($data['title'] ?? null) ? $data['title'] : '',
                        'body' => is_string($data['body'] ?? null) ? $data['body'] : '',
                    ],
                    201,
                );
            },
        ]);

        $result = (new CreateResourceItemTool)->execute([
            'service' => 'blog',
            'resource' => 'post',
            'payload' => [
                'title' => 'Hello MCP',
                'body' => 'Sent via create_resource_item.',
            ],
        ]);

        $this->assertTrue($result['success'], (string) json_encode($result));
        $this->assertSame(99, $result['primary_key_value']);
        $item = $result['item'];
        $this->assertIsArray($item);
        $this->assertSame(99, $item['id']);
        $this->assertSame('Hello MCP', $item['title']);

        Http::assertSent(fn (Request $r): bool => $r->method() === 'POST'
            && str_contains($r->url(), '/posts')
            && ! str_contains($r->url(), '/posts/'));
    }

    public function test_refuses_when_spec_actions_exclude_create(): void
    {
        // Write a spec sidecar that locks the Post resource to view-only.
        // ResourceLocator reads from the configured resources_path
        // (overridden in defineEnvironment), but ResourceActionResolver
        // reads from .ai-factory/specs/<service>/<resource>.json. Writing
        // a blog/post.json here shadows the real spec for this test.
        $path = ResourceGenerator::specSidecarPath('blog', 'post');
        File::ensureDirectoryExists(dirname($path));
        $existing = File::exists($path) ? File::get($path) : null;

        try {
            $encoded = json_encode(['actions' => ['view']]);
            File::put($path, $encoded === false ? '{}' : $encoded);

            $result = (new CreateResourceItemTool)->execute([
                'service' => 'blog',
                'resource' => 'post',
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

    public function test_relation_resource_without_parent_context_returns_error(): void
    {
        $result = (new CreateResourceItemTool)->execute([
            'service' => 'blog',
            'resource' => 'comment',
            'payload' => ['body' => 'reply'],
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('RELATION_RESOURCE_REQUIRES_PARENT', $result['code']);
        // Comment endpoint is `/posts/{parent_id}/comments` — the generic
        // `{parent_id}` placeholder is what the binder reports as missing.
        $placeholders = $result['placeholders'];
        $this->assertIsArray($placeholders);
        $this->assertContains('parent_id', $placeholders);
    }

    public function test_unknown_resource_returns_resource_not_found(): void
    {
        $result = (new CreateResourceItemTool)->execute([
            'service' => 'blog',
            'resource' => 'nonexistent_entity',
            'payload' => ['x' => 1],
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('RESOURCE_NOT_FOUND', $result['code']);
    }

    public function test_upstream_422_maps_to_upstream_error_payload(): void
    {
        Http::fake([
            'https://api.blog.test/v1/posts' => Http::response([
                'message' => 'The title field is required.',
                'errors' => ['title' => ['required']],
            ], 422),
        ]);

        $result = (new CreateResourceItemTool)->execute([
            'service' => 'blog',
            'resource' => 'post',
            'payload' => ['body' => 'no title'],
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('UPSTREAM_ERROR', $result['code']);
        $this->assertSame(422, $result['status']);
        $this->assertIsArray($result['error_body']);
    }
}
