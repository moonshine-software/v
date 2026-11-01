<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Unit;

use App\ApiAdmin\MCP\Tools\FetchResourceItemsTool;
use App\ApiAdmin\Providers\ApiAdminServiceProvider;
use MoonShine\Laravel\Providers\MoonShineServiceProvider;
use MoonShine\MultiPanels\Providers\MultiPanelServiceProvider;
use Orchestra\Testbench\TestCase;

/**
 * Schema + error-branch coverage for fetch_resource_items.
 *
 * Integration-level happy path (paginator variants, upstream passthrough,
 * relation resources) lives in tests/ApiAdmin/Integration/FetchResourceItemsToolTest.
 */
final class FetchResourceItemsToolTest extends TestCase
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
        $schema = FetchResourceItemsTool::schema();

        $this->assertSame('fetch_resource_items', $schema['name']);
        $inputSchema = $schema['inputSchema'];
        $this->assertIsArray($inputSchema);
        $this->assertSame(['service', 'resource'], $inputSchema['required']);

        $props = $inputSchema['properties'];
        $this->assertIsArray($props);
        $this->assertArrayHasKey('parent_context', $props);
        $this->assertArrayHasKey('filter', $props);
        $this->assertArrayHasKey('sort', $props);
        $this->assertArrayHasKey('cursor', $props);
        $this->assertArrayHasKey('per_page', $props);
    }

    public function test_missing_required_returns_validation_error(): void
    {
        $tool = new FetchResourceItemsTool;

        $this->assertSame('VALIDATION', $tool->execute([])['code']);
        $this->assertSame('VALIDATION', $tool->execute(['service' => 'blog'])['code']);
        $this->assertSame('VALIDATION', $tool->execute(['resource' => 'post'])['code']);
    }

    public function test_unknown_resource_returns_resource_not_found_with_hint(): void
    {
        $tool = new FetchResourceItemsTool;

        $result = $tool->execute([
            'service' => 'blog',
            'resource' => 'nonexistent_entity',
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('RESOURCE_NOT_FOUND', $result['code']);
        $this->assertArrayHasKey('hint', $result);
        $this->assertIsArray($result['available_resources']);
    }
}
