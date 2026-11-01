<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class RealtimeRefreshClientTest extends TestCase
{
    public function test_realtime_refresh_component_renders_metadata_for_browser_client(): void
    {
        $html = view('api-admin::components.realtime-refresh', [
            'metadata' => [
                'enabled' => true,
                'service' => 'blog',
                'resource' => 'posts',
                'page' => 'index',
                'channels' => ['api-admin.blog.posts'],
                'eventName' => 'api-admin.upstream-resource-mutated',
                'refreshStrategy' => 'auto',
                'debounceMs' => 750,
                'debug' => true,
            ],
        ])->render();

        $this->assertStringContainsString('data-api-admin-realtime-refresh', $html);
        $this->assertStringContainsString('"service":"blog"', $html);
        $this->assertStringContainsString('"resource":"posts"', $html);
        $this->assertStringContainsString('"channels":["api-admin.blog.posts"]', $html);
        $this->assertStringContainsString('ApiAdminRealtimeRefresh.register', $html);
    }

    public function test_realtime_refresh_module_is_loaded_by_app_entrypoint(): void
    {
        $entrypoint = 'js/app.js'
            |> resource_path(...)
            |> file_get_contents(...);

        $this->assertIsString($entrypoint);
        $this->assertStringContainsString("import './api-admin/realtime';", $entrypoint);
    }
}
