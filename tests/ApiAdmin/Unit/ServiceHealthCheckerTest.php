<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Unit;

use App\ApiAdmin\ServiceConfig\ResponseMapping;
use App\ApiAdmin\ServiceConfig\ServiceConfig;
use App\ApiAdmin\ServiceConfig\ServiceHealthChecker;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;

class ServiceHealthCheckerTest extends TestCase
{
    public function test_reports_ok_on_successful_response(): void
    {
        Http::fake([
            'https://api.blog.test/v1/health' => Http::response(['status' => 'ok'], 200),
        ]);

        $checker = $this->makeChecker();

        $result = $checker->check($this->makeService(healthPath: '/health'));

        $this->assertSame(ServiceHealthChecker::STATUS_OK, $result['status']);
        $this->assertSame(200, $result['code']);
        $this->assertSame('https://api.blog.test/v1/health', $result['url']);
    }

    public function test_absolute_health_path_is_used_verbatim(): void
    {
        // Covers the split-deployment pattern where base_url is "/api" but
        // the health endpoint lives at the host root (e.g. Laravel's /up).
        // Before this support, agents had to hack "/../up" relative paths.
        Http::fake([
            'http://127.0.0.1:8080/up' => Http::response(['ok' => true], 200),
        ]);

        $checker = $this->makeChecker();

        $result = $checker->check($this->makeService(
            healthPath: 'http://127.0.0.1:8080/up',
            baseUrl: 'http://127.0.0.1:8080/api',
        ));

        $this->assertSame(ServiceHealthChecker::STATUS_OK, $result['status']);
        $this->assertSame('http://127.0.0.1:8080/up', $result['url']);
    }

    public function test_reports_degraded_on_client_error(): void
    {
        Http::fake([
            'https://api.blog.test/v1' => Http::response('not found', 404),
        ]);

        $checker = $this->makeChecker();

        $result = $checker->check($this->makeService());

        $this->assertSame(ServiceHealthChecker::STATUS_DEGRADED, $result['status']);
        $this->assertSame(404, $result['code']);
    }

    public function test_reports_down_on_server_error(): void
    {
        Http::fake([
            'https://api.blog.test/v1' => Http::response('boom', 503),
        ]);

        $checker = $this->makeChecker();

        $result = $checker->check($this->makeService());

        $this->assertSame(ServiceHealthChecker::STATUS_DOWN, $result['status']);
        $this->assertSame(503, $result['code']);
    }

    public function test_reports_down_on_connection_exception(): void
    {
        Http::fake(function (): void {
            throw new ConnectionException('unreachable');
        });

        $checker = $this->makeChecker();

        $result = $checker->check($this->makeService());

        $this->assertSame(ServiceHealthChecker::STATUS_DOWN, $result['status']);
        $this->assertNull($result['code']);
    }

    public function test_sends_auth_headers_on_probe(): void
    {
        Http::fake([
            'https://api.blog.test/v1/health' => Http::response('', 200),
        ]);

        $checker = $this->makeChecker();

        $checker->check($this->makeService(apiKey: 'secret-token', healthPath: '/health'));

        Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'https://api.blog.test/v1/health'
            && $request->header('Authorization') === ['Bearer secret-token']);
    }

    public function test_panel_login_service_is_probed_without_attempting_upstream_login(): void
    {
        Http::fake([
            'https://api.blog.test/v1/health' => Http::response('', 200),
        ]);

        $result = $this->makeChecker()->check(new ServiceConfig(
            name: 'spa',
            baseUrl: 'https://api.blog.test/v1',
            apiKey: '',
            responseMapping: new ResponseMapping,
            authType: 'oauth2_password',
            healthPath: '/health',
            adminLogin: true,
        ));

        $this->assertSame(ServiceHealthChecker::STATUS_OK, $result['status']);
        Http::assertSentCount(1);
        Http::assertSent(fn (ClientRequest $request): bool => ! $request->hasHeader('Authorization'));
    }

    public function test_caches_result_within_ttl(): void
    {
        Http::fake([
            'https://api.blog.test/v1' => Http::sequence()
                ->push('', 200)
                ->push('', 500),
        ]);

        $checker = $this->makeChecker();
        $service = $this->makeService();

        $first = $checker->check($service);
        $second = $checker->check($service);

        $this->assertSame(ServiceHealthChecker::STATUS_OK, $first['status']);
        $this->assertSame(ServiceHealthChecker::STATUS_OK, $second['status']);
        Http::assertSentCount(1);
    }

    private function makeChecker(): ServiceHealthChecker
    {
        return new ServiceHealthChecker(
            cache: new Repository(new ArrayStore),
            timeout: 1.0,
            ttlSeconds: 30,
        );
    }

    private function makeService(
        string $apiKey = '',
        string $healthPath = '',
        string $baseUrl = 'https://api.blog.test/v1',
    ): ServiceConfig {
        return new ServiceConfig(
            name: 'blog',
            baseUrl: $baseUrl,
            apiKey: $apiKey,
            responseMapping: new ResponseMapping,
            panel: 'blog-admin',
            authType: 'bearer',
            healthPath: $healthPath,
        );
    }
}
