<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Unit;

use App\ApiAdmin\MCP\Tools\CountResourceTool;
use App\ApiAdmin\Providers\ApiAdminServiceProvider;
use MoonShine\Laravel\Providers\MoonShineServiceProvider;
use MoonShine\MultiPanels\Providers\MultiPanelServiceProvider;
use Orchestra\Testbench\TestCase;

final class CountResourceToolTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            MoonShineServiceProvider::class,
            MultiPanelServiceProvider::class,
            ApiAdminServiceProvider::class,
        ];
    }

    public function test_schema_shape_is_stable(): void
    {
        $schema = CountResourceTool::schema();

        $this->assertSame('count_resource', $schema['name']);
        $inputSchema = $schema['inputSchema'];
        $this->assertIsArray($inputSchema);
        $this->assertSame(['service', 'resource'], $inputSchema['required']);
        $props = $inputSchema['properties'];
        $this->assertIsArray($props);
        $this->assertArrayHasKey('filter', $props);
        $this->assertArrayHasKey('search', $props);
        $this->assertArrayHasKey('time_window', $props);
        $this->assertArrayHasKey('parent_context', $props);
    }

    public function test_missing_required_returns_validation_error(): void
    {
        $result = (new CountResourceTool)->execute([]);

        $this->assertFalse($result['success']);
        $this->assertSame('VALIDATION', $result['code']);
    }

    public function test_invalid_time_window_returns_validation_error(): void
    {
        $result = (new CountResourceTool)->execute([
            'service' => 'blog',
            'resource' => 'post',
            'time_window' => ['from' => '2026-01-01'],
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('VALIDATION', $result['code']);
        $error = $result['error'];
        $this->assertIsString($error);
        $this->assertStringContainsString('time_window.field', $error);
    }
}
