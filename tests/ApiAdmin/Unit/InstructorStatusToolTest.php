<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Unit;

use App\ApiAdmin\Instructor\InstructorSessionRepository;
use App\ApiAdmin\MCP\Tools\InstructorStatusTool;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Orchestra\Testbench\TestCase;

final class InstructorStatusToolTest extends TestCase
{
    public function test_returns_inactive_without_session_id(): void
    {
        $result = (new InstructorStatusTool)->execute([]);

        $this->assertTrue($result['success']);
        $this->assertFalse($result['active']);
    }

    public function test_returns_sanitized_active_session_summary(): void
    {
        $store = new Repository(new ArrayStore);
        Cache::shouldReceive('store')->andReturn($store);

        $repository = new InstructorSessionRepository($store);
        $session = $repository->start('browser-session')->withProposal([
            'service' => ['name' => 'blog', 'api_key' => 'secret'],
        ]);
        $repository->save($session);

        $result = (new InstructorStatusTool)->execute(['session_id' => 'browser-session']);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['active']);
        $session = $result['session'];
        $this->assertIsArray($session);
        $proposal = $session['proposal'];
        $this->assertIsArray($proposal);
        $service = $proposal['service'];
        $this->assertIsArray($service);
        $this->assertSame('[redacted]', $service['api_key']);
    }
}
