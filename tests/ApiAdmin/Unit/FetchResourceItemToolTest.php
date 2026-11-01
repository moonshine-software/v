<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Unit;

use App\ApiAdmin\MCP\Tools\FetchResourceItemTool;
use App\ApiAdmin\Providers\ApiAdminServiceProvider;
use MoonShine\Laravel\Providers\MoonShineServiceProvider;
use MoonShine\MultiPanels\Providers\MultiPanelServiceProvider;
use Orchestra\Testbench\TestCase;

final class FetchResourceItemToolTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            MoonShineServiceProvider::class,
            MultiPanelServiceProvider::class,
            ApiAdminServiceProvider::class,
        ];
    }

    public function test_schema_requires_service_resource_id(): void
    {
        $schema = FetchResourceItemTool::schema();

        $this->assertSame('fetch_resource_item', $schema['name']);
        $inputSchema = $schema['inputSchema'];
        $this->assertIsArray($inputSchema);
        $this->assertSame(['service', 'resource', 'id'], $inputSchema['required']);
    }

    public function test_missing_required_returns_validation_error(): void
    {
        $tool = new FetchResourceItemTool;

        $this->assertSame('VALIDATION', $tool->execute([])['code']);
        $this->assertSame('VALIDATION', $tool->execute(['service' => 'blog'])['code']);
        $this->assertSame('VALIDATION', $tool->execute(['service' => 'blog', 'resource' => 'post'])['code']);
        $this->assertSame('VALIDATION', $tool->execute(['service' => 'blog', 'resource' => 'post', 'id' => ''])['code']);
    }

    public function test_unknown_resource_returns_resource_not_found(): void
    {
        $tool = new FetchResourceItemTool;

        $result = $tool->execute([
            'service' => 'blog',
            'resource' => 'nonexistent',
            'id' => 1,
        ]);

        $this->assertSame('RESOURCE_NOT_FOUND', $result['code']);
        $this->assertArrayHasKey('hint', $result);
    }
}
