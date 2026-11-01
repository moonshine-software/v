<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\ApiAdmin\MCP\McpServer;
use JsonException;
use PHPUnit\Framework\TestCase;

final class ProjectVersionTest extends TestCase
{
    /**
     * @throws JsonException
     */
    public function test_package_files_and_mcp_report_the_same_version(): void
    {
        $root = dirname(__DIR__, 2);
        $package = json_decode((string) file_get_contents($root.'/package.json'), true, flags: JSON_THROW_ON_ERROR);
        $lock = json_decode((string) file_get_contents($root.'/package-lock.json'), true, flags: JSON_THROW_ON_ERROR);

        $this->assertIsArray($package);
        $this->assertIsArray($lock);
        $this->assertSame(McpServer::VERSION, $package['version']);
        $this->assertSame(McpServer::VERSION, $lock['version']);
        $this->assertSame(McpServer::VERSION, data_get($lock, 'packages..version'));
    }
}
