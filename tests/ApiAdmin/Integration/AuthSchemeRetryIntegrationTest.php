<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Integration;

use App\ApiAdmin\Auth\OAuth2AuthHandler;
use App\ApiAdmin\Auth\OAuth2TokenStore;
use App\ApiAdmin\Auth\SessionAuthHandler;
use App\ApiAdmin\ServiceConfig\ResponseMapping;
use App\ApiAdmin\ServiceConfig\ServiceConfig;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;

/**
 * End-to-end retry coverage: ServiceConfig::httpClient() wires the
 * RetryOn401 middleware with the right concrete handler for each new auth
 * type, and a real 401 response triggers the refresh + retry path.
 *
 * Unit tests cover the middleware mechanics in isolation; this file proves
 * the wiring (config → handler → middleware) holds together.
 */
class AuthSchemeRetryIntegrationTest extends TestCase
{
    protected function defineDatabaseMigrations(): void
    {
        Schema::create('api_admin_tenant_secrets', static function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id', 64);
            $table->string('service', 190);
            $table->string('name', 190);
            $table->text('value');
            $table->timestamps();
            $table->unique(['tenant_id', 'service', 'name']);
        });
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=']);
        Cache::flush();
    }

    public function test_oauth2_password_401_refreshes_token_and_retries_with_new_bearer(): void
    {
        Http::fake([
            // First call: 401 with stale bearer.
            // After refresh: 200 with new bearer.
            'https://api.test/posts' => Http::sequence()
                ->push(['err' => 'expired'], 401)
                ->push(['data' => [['id' => 1]]], 200),
            'https://idp.test/oauth/token' => Http::sequence()
                ->push(['access_token' => 'stale-1', 'expires_in' => 3600])
                ->push(['access_token' => 'fresh-2', 'expires_in' => 3600]),
        ]);

        $config = new ServiceConfig(
            name: 'svc',
            baseUrl: 'https://api.test',
            apiKey: '',
            responseMapping: new ResponseMapping,
            authType: 'oauth2_password',
            tokenUrl: 'https://idp.test/oauth/token',
            oauthClientId: 'cid',
            oauthClientSecret: 'csec',
            oauthUsername: 'u',
            oauthPassword: 'p',
        );

        $response = $config->httpClient()->get('/posts');

        $this->assertSame(200, $response->status());

        // Capture Authorization headers across both /posts attempts.
        $authHeaders = [];
        Http::recorded(function (Request $request) use (&$authHeaders): bool {
            if (str_contains($request->url(), '/posts')) {
                $authHeaders[] = $request->header('Authorization')[0] ?? null;
            }

            return false;
        });

        $this->assertSame(['Bearer stale-1', 'Bearer fresh-2'], $authHeaders);
    }

    public function test_session_419_re_logs_in_and_retries_with_new_cookie(): void
    {
        Http::fake([
            'https://app.test/login' => Http::sequence()
                ->push(['ok' => true], 200, ['Set-Cookie' => ['XSRF-TOKEN=t1; Path=/', 'laravel_session=s1; Path=/']])
                ->push(['ok' => true], 200, ['Set-Cookie' => ['XSRF-TOKEN=t2; Path=/', 'laravel_session=s2; Path=/']]),
            'https://app.test/data' => Http::sequence()
                ->push(['err' => 'csrf'], 419)
                ->push(['data' => 'fresh'], 200),
        ]);

        $config = new ServiceConfig(
            name: 'svc',
            baseUrl: 'https://app.test',
            apiKey: '',
            responseMapping: new ResponseMapping,
            authType: 'session',
            sessionLoginEndpoint: '/login',
            sessionUsername: 'u',
            sessionPassword: 'p',
        );

        $response = $config->httpClient()->get('/data');

        $this->assertSame(200, $response->status());

        $cookieHeaders = [];
        Http::recorded(function (Request $request) use (&$cookieHeaders): bool {
            if (str_contains($request->url(), '/data')) {
                $cookieHeaders[] = $request->header('Cookie')[0] ?? null;
            }

            return false;
        });

        $this->assertCount(2, $cookieHeaders);
        $this->assertIsString($cookieHeaders[0]);
        $this->assertIsString($cookieHeaders[1]);
        $this->assertStringContainsString('laravel_session=s1', $cookieHeaders[0]);
        $this->assertStringContainsString('laravel_session=s2', $cookieHeaders[1]);
    }

    public function test_auth_behavior_none_disables_retry_middleware(): void
    {
        Http::fake([
            'https://api.test/posts' => Http::response(['err' => 'expired'], 401),
            'https://idp.test/oauth/token' => Http::response(['access_token' => 't', 'expires_in' => 3600]),
        ]);

        $config = new ServiceConfig(
            name: 'svc',
            baseUrl: 'https://api.test',
            apiKey: '',
            responseMapping: new ResponseMapping,
            authType: 'oauth2_password',
            tokenUrl: 'https://idp.test/oauth/token',
            oauthClientId: 'cid',
            oauthClientSecret: 'csec',
            authBehavior: 'none',
            oauthUsername: 'u',
            oauthPassword: 'p',
        );

        $response = $config->httpClient()->get('/posts');

        $this->assertSame(401, $response->status());

        // 1 call to /posts (no retry), 1 token fetch for the initial header.
        // Total: 2.
        Http::assertSentCount(2);
    }

    public function test_oauth2_refresh_token_grant_through_service_config(): void
    {
        Cache::flush();

        Http::fake([
            'https://idp.test/oauth/token' => Http::response([
                'access_token' => 'rt-access',
                'expires_in' => 3600,
            ]),
            'https://api.test/me' => Http::response(['ok' => true], 200),
        ]);

        $config = new ServiceConfig(
            name: 'svc',
            baseUrl: 'https://api.test',
            apiKey: '',
            responseMapping: new ResponseMapping,
            authType: 'oauth2_refresh_token',
            tokenUrl: 'https://idp.test/oauth/token',
            oauthClientId: 'cid',
            oauthClientSecret: 'csec',
            oauthRefreshToken: 'seed-rt',
        );

        $response = $config->httpClient()->get('/me');

        $this->assertSame(200, $response->status());

        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), '/oauth/token')) {
                return false;
            }

            $body = (string) $request->body();

            return str_contains($body, 'grant_type=refresh_token')
                && str_contains($body, 'refresh_token=seed-rt');
        });
    }

    public function test_auth_handler_returns_oauth_handler_for_each_oauth_grant(): void
    {
        $svc = new ServiceConfig(
            name: 'x',
            baseUrl: 'https://api.test',
            apiKey: '',
            responseMapping: new ResponseMapping,
            authType: 'oauth2_password',
            tokenUrl: 'https://idp.test/token',
            oauthClientId: 'cid',
            oauthClientSecret: 'csec',
            oauthUsername: 'u',
            oauthPassword: 'p',
        );

        $handler = $svc->authHandler();

        $this->assertInstanceOf(OAuth2AuthHandler::class, $handler);

        // Refresh-token grant maps to the same handler class but a different grant constant.
        $svc2 = new ServiceConfig(
            name: 'y',
            baseUrl: 'https://api.test',
            apiKey: '',
            responseMapping: new ResponseMapping,
            authType: 'oauth2_refresh_token',
            tokenUrl: 'https://idp.test/token',
            oauthClientId: 'cid',
            oauthClientSecret: 'csec',
            oauthRefreshToken: 'seed',
        );

        $this->assertInstanceOf(OAuth2AuthHandler::class, $svc2->authHandler());
    }

    public function test_auth_handler_returns_session_handler_for_session_type(): void
    {
        $svc = new ServiceConfig(
            name: 'web',
            baseUrl: 'https://app.test',
            apiKey: '',
            responseMapping: new ResponseMapping,
            authType: 'session',
            sessionLoginEndpoint: '/login',
            sessionUsername: 'u',
            sessionPassword: 'p',
        );

        $handler = $svc->authHandler();

        $this->assertInstanceOf(SessionAuthHandler::class, $handler);
    }

    public function test_auth_handler_returns_null_for_static_token_types(): void
    {
        foreach (['bearer', 'header', 'query', 'basic', 'none'] as $type) {
            $svc = new ServiceConfig(
                name: 't',
                baseUrl: 'https://api.test',
                apiKey: 'tok',
                responseMapping: new ResponseMapping,
                authType: $type,
            );

            $this->assertNull($svc->authHandler(), "auth_type={$type} must not have a retry handler");
        }
    }

    public function test_oauth_token_store_grant_constants_match_auth_type_strings(): void
    {
        // Sanity: ServiceConfig::authHeaders maps the auth_type string to the
        // store's grant constant. If these drift, oauth2_* requests would
        // silently fall into the default '[]' arm and emit no Authorization
        // header at all.
        $this->assertSame('client_credentials', OAuth2TokenStore::GRANT_CLIENT_CREDENTIALS);
        $this->assertSame('password', OAuth2TokenStore::GRANT_PASSWORD);
        $this->assertSame('refresh_token', OAuth2TokenStore::GRANT_REFRESH_TOKEN);
    }
}
