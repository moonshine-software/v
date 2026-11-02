<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\ApiAdmin\MCP\McpServer;
use App\ApiAdmin\ServiceConfig\ServiceConfig;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class UpstreamErrorLoggingTest extends TestCase
{
    /** @return iterable<string, array{bool}> */
    public static function failures(): iterable
    {
        yield 'HTTP error body' => [false];
        yield 'connection error URL' => [true];
    }

    #[DataProvider('failures')]
    public function test_mcp_preserves_upstream_errors_without_logging_secrets(bool $network): void
    {
        config()->set([
            'api-admin.resources_path' => base_path('tests/ApiAdmin/Fixtures/Resources'),
            'api-admin.resources_namespace' => 'Tests\\ApiAdmin\\Fixtures\\Resources',
            'api-admin.specs_path' => base_path('tests/ApiAdmin/Fixtures/Specs'),
        ]);
        app()->instance('api-admin.tenant.local.service.blog', ServiceConfig::fromArray([
            'name' => 'blog', 'base_url' => 'https://api.blog.test/v1', 'auth_type' => 'none',
        ]));
        $body = ['password' => 'response-password-secret', 'profile' => ['token' => 'nested-token-secret']];
        $message = 'Connection failed for https://api.blog.test/v1/posts?api_key=query-credential-secret';
        Http::preventStrayRequests();
        Http::fake(static fn () => $network
            ? throw new ConnectionException($message)
            : Http::response($body, 500));
        $logs = [];
        Log::listen(static function (MessageLogged $event) use (&$logs): void {
            $logs[] = [$event->message, $event->context];
        });

        $result = new McpServer()->executeTool('create_resource_item', [
            'service' => 'blog', 'resource' => 'post', 'payload' => ['title' => 'Test'],
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame($network ? 'NETWORK_ERROR' : 'UPSTREAM_ERROR', $result['code']);
        if ($network) {
            $this->assertSame($message, $result['error']);
        } else {
            $this->assertSame(500, $result['status']);
            $this->assertSame($body, $result['error_body']);
            Http::assertSentCount(1);
        }
        $recorded = json_encode($logs, JSON_THROW_ON_ERROR);
        foreach (['response-password-secret', 'nested-token-secret', 'query-credential-secret'] as $secret) {
            $this->assertStringNotContainsString($secret, $recorded);
        }
        $this->assertContains([
            '[FIX:upstream-error] UpstreamErrorResponder::fromThrowable',
            ['exception' => $network ? ConnectionException::class : RequestException::class, 'status' => $network ? null : 500],
        ], $logs);
    }
}
