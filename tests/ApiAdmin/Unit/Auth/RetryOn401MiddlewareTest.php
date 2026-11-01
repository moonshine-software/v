<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Unit\Auth;

use App\ApiAdmin\Auth\AuthRetryHandler;
use App\ApiAdmin\Auth\RetryOn401Middleware;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;
use Psr\Http\Message\RequestInterface;

class RetryOn401MiddlewareTest extends TestCase
{
    public function test_no_retry_when_handler_null(): void
    {
        Http::fake([
            'https://api.test/me' => Http::response(['ok' => false], 401),
        ]);

        $client = RetryOn401Middleware::install(Http::baseUrl('https://api.test'), null);

        $response = $client->get('/me');

        $this->assertSame(401, $response->status());
        Http::assertSentCount(1);
    }

    public function test_no_retry_when_status_not_401_or_419(): void
    {
        Http::fake([
            'https://api.test/me' => Http::response(['err' => 'gone'], 410),
        ]);

        $handler = $this->makeHandler(refreshOk: true, replacementHeader: 'Bearer fresh');

        $client = RetryOn401Middleware::install(Http::baseUrl('https://api.test'), $handler);

        $response = $client->get('/me');

        $this->assertSame(410, $response->status());
        Http::assertSentCount(1);
    }

    public function test_single_retry_on_401_when_handler_returns_true(): void
    {
        Http::fake([
            'https://api.test/me' => Http::sequence()
                ->push(['err' => 'expired'], 401)
                ->push(['ok' => true], 200),
        ]);

        $handler = $this->makeHandler(refreshOk: true, replacementHeader: 'Bearer fresh-1');

        $client = RetryOn401Middleware::install(Http::baseUrl('https://api.test'), $handler);

        $response = $client->get('/me');

        $this->assertSame(200, $response->status());
        Http::assertSentCount(2);
    }

    public function test_no_retry_loop_on_repeated_401(): void
    {
        Http::fake([
            'https://api.test/me' => Http::response(['err' => 'expired'], 401),
        ]);

        $handler = $this->makeHandler(refreshOk: true, replacementHeader: 'Bearer hopeless');

        $client = RetryOn401Middleware::install(Http::baseUrl('https://api.test'), $handler);

        $response = $client->get('/me');

        $this->assertSame(401, $response->status());
        // Original + ONE retry = 2 requests, never 3+ even though both returned 401.
        Http::assertSentCount(2);
    }

    public function test_handler_apply_to_replaces_authorization_header_before_retry(): void
    {
        Http::fake([
            'https://api.test/me' => Http::sequence()
                ->push(['err' => 'expired'], 401)
                ->push(['ok' => true], 200),
        ]);

        $handler = $this->makeHandler(refreshOk: true, replacementHeader: 'Bearer NEW');

        $client = RetryOn401Middleware::install(
            Http::baseUrl('https://api.test')->withHeaders(['Authorization' => 'Bearer OLD']),
            $handler,
        );

        $response = $client->get('/me');

        $this->assertSame(200, $response->status());

        $captured = [];
        Http::recorded(function (Request $request) use (&$captured): bool {
            $captured[] = $request->header('Authorization')[0] ?? null;

            return false;
        });

        $this->assertSame(['Bearer OLD', 'Bearer NEW'], $captured);
    }

    public function test_no_retry_when_handler_refresh_returns_false(): void
    {
        Http::fake([
            'https://api.test/me' => Http::response(['err' => 'expired'], 401),
        ]);

        $handler = $this->makeHandler(refreshOk: false, replacementHeader: 'Bearer never-applied');

        $client = RetryOn401Middleware::install(Http::baseUrl('https://api.test'), $handler);

        $response = $client->get('/me');

        $this->assertSame(401, $response->status());
        Http::assertSentCount(1);
    }

    public function test_419_csrf_status_also_triggers_retry(): void
    {
        Http::fake([
            'https://api.test/me' => Http::sequence()
                ->push(['err' => 'csrf'], 419)
                ->push(['ok' => true], 200),
        ]);

        $handler = $this->makeHandler(refreshOk: true, replacementHeader: 'cookie-fresh');

        $client = RetryOn401Middleware::install(Http::baseUrl('https://api.test'), $handler);

        $response = $client->get('/me');

        $this->assertSame(200, $response->status());
        Http::assertSentCount(2);
    }

    private function makeHandler(bool $refreshOk, string $replacementHeader): AuthRetryHandler
    {
        return new readonly class($refreshOk, $replacementHeader) implements AuthRetryHandler
        {
            public function __construct(
                private bool $refreshOk,
                private string $replacementHeader,
            ) {}

            public function refresh(Response $failed, ?RequestInterface $request = null): bool
            {
                return $this->refreshOk;
            }

            public function rebuildRequest(RequestInterface $request): RequestInterface
            {
                return $request->withHeader('Authorization', $this->replacementHeader);
            }

            public function service(): string
            {
                return 'test-service';
            }
        };
    }
}
