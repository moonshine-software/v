<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Unit;

use App\ApiAdmin\MCP\Tools\AggregateResourceTool;
use App\ApiAdmin\Providers\ApiAdminServiceProvider;
use MoonShine\Laravel\Providers\MoonShineServiceProvider;
use MoonShine\MultiPanels\Providers\MultiPanelServiceProvider;
use Orchestra\Testbench\TestCase;

final class AggregateResourceToolTest extends TestCase
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
        $schema = AggregateResourceTool::schema();

        $this->assertSame('aggregate_resource', $schema['name']);
        $inputSchema = $schema['inputSchema'];
        $this->assertIsArray($inputSchema);
        $this->assertSame(['service', 'resource', 'operation'], $inputSchema['required']);
        $props = $inputSchema['properties'];
        $this->assertIsArray($props);
        $this->assertArrayHasKey('field', $props);
        $this->assertArrayHasKey('group_by', $props);
        $this->assertArrayHasKey('limit', $props);
        $this->assertArrayHasKey('time_window', $props);
    }

    public function test_missing_operation_returns_validation_error(): void
    {
        $result = (new AggregateResourceTool)->execute([
            'service' => 'blog',
            'resource' => 'post',
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('VALIDATION', $result['code']);
    }
}
