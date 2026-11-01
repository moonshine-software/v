<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Integration;

use App\ApiAdmin\MCP\Tools\ProbeEndpointTool;
use App\ApiAdmin\ServiceConfig\ResponseMapping;
use App\ApiAdmin\ServiceConfig\ServiceConfig;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;

class ProbeEndpointAuthHintsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app()->forgetInstance('api-admin.service.upstream');
        app()->instance(
            'api-admin.service.upstream',
            new ServiceConfig(
                name: 'upstream',
                baseUrl: 'https://api.upstream.test',
                apiKey: 'tok',
                responseMapping: new ResponseMapping,
                authType: 'bearer',
                authBehavior: 'none', // skip retry middleware so 401 surfaces directly
            ),
        );
    }

    public function test_401_with_session_cookie_emits_session_hint(): void
    {
        Http::fake([
            'https://api.upstream.test/me' => Http::response(['err' => 'unauth'], 401, [
                'Set-Cookie' => ['XSRF-TOKEN=abc; Path=/', 'laravel_session=ses; Path=/; HttpOnly'],
            ]),
        ]);

        $tool = new ProbeEndpointTool;
        $result = $tool->execute([
            'service' => 'upstream',
            'path' => '/me',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(401, $result['status']);

        /** @var array<int, string> $hints */
        $hints = $result['hints'];
        $combined = implode("\n", $hints);

        $this->assertStringContainsString("auth_type='session'", $combined);
        $this->assertStringContainsString('XSRF-TOKEN', $combined);
    }

    public function test_401_with_bearer_challenge_emits_oauth_grant_hint(): void
    {
        Http::fake([
            'https://api.upstream.test/me' => Http::response(['err' => 'expired'], 401, [
                'WWW-Authenticate' => ['Bearer realm="api"'],
            ]),
        ]);

        $tool = new ProbeEndpointTool;
        $result = $tool->execute([
            'service' => 'upstream',
            'path' => '/me',
        ]);

        $this->assertTrue($result['success']);

        /** @var array<int, string> $hints */
        $hints = $result['hints'];
        $combined = implode("\n", $hints);

        $this->assertStringContainsString('Bearer challenge', $combined);
        $this->assertStringContainsString('oauth2_password', $combined);
    }

    public function test_419_emits_csrf_hint(): void
    {
        Http::fake([
            'https://api.upstream.test/data' => Http::response(['err' => 'csrf'], 419),
        ]);

        $tool = new ProbeEndpointTool;
        $result = $tool->execute([
            'service' => 'upstream',
            'path' => '/data',
        ]);

        $this->assertTrue($result['success']);

        /** @var array<int, string> $hints */
        $hints = $result['hints'];
        $combined = implode("\n", $hints);

        $this->assertStringContainsString('419', $combined);
        $this->assertStringContainsString('auth_type="session"', $combined);
    }

    public function test_2xx_does_not_emit_auth_hints(): void
    {
        Http::fake([
            'https://api.upstream.test/me' => Http::response(['ok' => true], 200, [
                'Set-Cookie' => ['XSRF-TOKEN=abc; Path=/'],
            ]),
        ]);

        $tool = new ProbeEndpointTool;
        $result = $tool->execute([
            'service' => 'upstream',
            'path' => '/me',
        ]);

        /** @var array<int, string> $hints */
        $hints = $result['hints'];
        $combined = implode("\n", $hints);

        // Session cookie inspection only fires on 401/419, not 2xx.
        $this->assertStringNotContainsString("auth_type='session'", $combined);
    }
}
