<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class TrustedProxyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/proxy-check', static fn (Request $request): array => [
            'ip' => $request->ip(),
            'host' => $request->getHost(),
            'scheme' => $request->getScheme(),
        ])->middleware('throttle:api-admin-mcp');
    }

    protected function tearDown(): void
    {
        TrustProxies::flushState();
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);

        parent::tearDown();
    }

    public function test_trusted_ingress_preserves_independent_client_limits(): void
    {
        config(['trustedproxy.proxies' => '172.18.0.1']);
        $this->withServerVariables(['REMOTE_ADDR' => '172.18.0.1']);
        $this->withHeaders([
            'X-Forwarded-For' => '203.0.113.10',
            'X-Forwarded-Host' => 'attacker.example',
            'X-Forwarded-Proto' => 'https',
        ]);

        for ($i = 0; $i < 60; $i++) {
            $this->get('/proxy-check')->assertOk()
                ->assertJson(['ip' => '203.0.113.10', 'host' => 'localhost', 'scheme' => 'http']);
        }
        $this->get('/proxy-check')->assertStatus(429);
        $this->withHeader('X-Forwarded-For', '203.0.113.20')->get('/proxy-check')
            ->assertOk()->assertJsonPath('ip', '203.0.113.20');
    }

    public function test_untrusted_clients_cannot_spoof_their_address_to_evade_limits(): void
    {
        config(['trustedproxy.proxies' => '172.18.0.1']);
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.30']);

        for ($i = 0; $i < 60; $i++) {
            $this->withHeader('X-Forwarded-For', '198.51.100.'.($i + 1))->get('/proxy-check')
                ->assertOk()->assertJsonPath('ip', '203.0.113.30');
        }
        $this->withHeader('X-Forwarded-For', '198.51.100.100')->get('/proxy-check')->assertStatus(429);
    }
}
