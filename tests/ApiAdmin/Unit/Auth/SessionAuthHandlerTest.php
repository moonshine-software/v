<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Unit\Auth;

use App\ApiAdmin\Auth\SessionAuthHandler;
use App\ApiAdmin\Tenancy\TenantKeyBuilder;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Auth\User as AuthenticatableUser;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;
use RuntimeException;

class SessionAuthHandlerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Auth::forgetGuards();
    }

    public function test_login_captures_session_cookie_and_csrf_from_cookie_jar(): void
    {
        Http::fake([
            'https://app.test/login' => Http::response(['ok' => true], 200, [
                'Set-Cookie' => [
                    'XSRF-TOKEN=urlencoded%2Fvalue; Path=/',
                    'laravel_session=abc123; Path=/; HttpOnly',
                ],
            ]),
        ]);

        $handler = new SessionAuthHandler(
            serviceName: 'svc',
            baseUrl: 'https://app.test',
            loginEndpoint: '/login',
            username: 'u@example.com',
            password: 'pw',
            csrfTokenSource: SessionAuthHandler::CSRF_SOURCE_COOKIE,
            csrfTokenKey: 'XSRF-TOKEN',
            csrfHeaderName: 'X-XSRF-TOKEN',
        );

        $headers = $handler->currentHeaders();

        $this->assertArrayHasKey('Cookie', $headers);
        $this->assertStringContainsString('XSRF-TOKEN=urlencoded%2Fvalue', $headers['Cookie']);
        $this->assertStringContainsString('laravel_session=abc123', $headers['Cookie']);
        $this->assertSame('urlencoded/value', $headers['X-XSRF-TOKEN']);
    }

    public function test_login_captures_csrf_from_response_header(): void
    {
        Http::fake([
            'https://app.test/login' => Http::response(['ok' => true], 200, [
                'X-CSRFToken' => 'header-csrf-42',
                'Set-Cookie' => ['sessionid=xyz; Path=/'],
            ]),
        ]);

        $handler = new SessionAuthHandler(
            serviceName: 'svc',
            baseUrl: 'https://app.test',
            loginEndpoint: '/login',
            username: 'u',
            password: 'p',
            csrfTokenSource: SessionAuthHandler::CSRF_SOURCE_HEADER,
            csrfTokenKey: 'X-CSRFToken',
            csrfHeaderName: 'X-CSRFToken',
        );

        $headers = $handler->currentHeaders();

        $this->assertSame('header-csrf-42', $headers['X-CSRFToken']);
    }

    public function test_login_captures_csrf_from_response_body(): void
    {
        Http::fake([
            'https://app.test/users/sign_in' => Http::response([
                'authenticity_token' => 'rails-token-123',
            ], 200, [
                'Set-Cookie' => ['_session_id=zzz; Path=/'],
            ]),
        ]);

        $handler = new SessionAuthHandler(
            serviceName: 'svc',
            baseUrl: 'https://app.test',
            loginEndpoint: '/users/sign_in',
            username: 'u',
            password: 'p',
            csrfTokenSource: SessionAuthHandler::CSRF_SOURCE_BODY,
            csrfTokenKey: 'authenticity_token',
            csrfHeaderName: 'X-CSRF-Token',
        );

        $headers = $handler->currentHeaders();

        $this->assertSame('rails-token-123', $headers['X-CSRF-Token']);
    }

    public function test_csrf_bootstrap_path_is_invoked_first_when_set(): void
    {
        Http::fake([
            'https://app.test/sanctum/csrf-cookie' => Http::response('', 204, [
                'Set-Cookie' => ['XSRF-TOKEN=bootstrap-tok; Path=/'],
            ]),
            'https://app.test/login' => Http::response(['ok' => true], 200, [
                'Set-Cookie' => ['laravel_session=zzz; Path=/'],
            ]),
        ]);

        $handler = new SessionAuthHandler(
            serviceName: 'svc',
            baseUrl: 'https://app.test',
            loginEndpoint: '/login',
            username: 'u',
            password: 'p',
            csrfBootstrapPath: '/sanctum/csrf-cookie',
        );

        $headers = $handler->currentHeaders();

        $this->assertStringContainsString('XSRF-TOKEN=bootstrap-tok', $headers['Cookie']);
        $this->assertStringContainsString('laravel_session=zzz', $headers['Cookie']);

        Http::assertSentCount(2);
    }

    public function test_rebuild_request_attaches_cookie_and_csrf_header(): void
    {
        Http::fake([
            'https://app.test/login' => Http::response(['ok' => true], 200, [
                'Set-Cookie' => ['XSRF-TOKEN=tok; Path=/', 'laravel_session=ses; Path=/'],
            ]),
        ]);

        $handler = new SessionAuthHandler(
            serviceName: 'svc',
            baseUrl: 'https://app.test',
            loginEndpoint: '/login',
            username: 'u',
            password: 'p',
        );

        $request = new Request('GET', 'https://app.test/me');

        $rebuilt = $handler->rebuildRequest($request);

        $this->assertSame('XSRF-TOKEN=tok; laravel_session=ses', $rebuilt->getHeaderLine('Cookie'));
        $this->assertSame('tok', $rebuilt->getHeaderLine('X-XSRF-TOKEN'));
    }

    public function test_refresh_drops_cache_and_re_logs_in(): void
    {
        Http::fake([
            'https://app.test/login' => Http::sequence()
                ->push(['ok' => true], 200, ['Set-Cookie' => ['laravel_session=first; Path=/']])
                ->push(['ok' => true], 200, ['Set-Cookie' => ['laravel_session=second; Path=/']]),
        ]);

        $handler = new SessionAuthHandler(
            serviceName: 'svc',
            baseUrl: 'https://app.test',
            loginEndpoint: '/login',
            username: 'u',
            password: 'p',
        );

        $first = $handler->currentHeaders();
        $this->assertStringContainsString('laravel_session=first', $first['Cookie']);

        $failed = new \Illuminate\Http\Client\Response(
            new Response(401),
        );

        $this->assertTrue($handler->refresh($failed));

        $second = $handler->currentHeaders();
        $this->assertStringContainsString('laravel_session=second', $second['Cookie']);
    }

    public function test_login_failure_throws_with_status(): void
    {
        Http::fake([
            'https://app.test/login' => Http::response(['error' => 'bad creds'], 422),
        ]);

        $handler = new SessionAuthHandler(
            serviceName: 'svc',
            baseUrl: 'https://app.test',
            loginEndpoint: '/login',
            username: 'u',
            password: 'wrong',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/login failed with HTTP 422/');

        $handler->currentHeaders();
    }

    public function test_refresh_returns_false_when_login_fails(): void
    {
        Http::fake([
            'https://app.test/login' => Http::response(['error' => 'bad'], 500),
        ]);

        $handler = new SessionAuthHandler(
            serviceName: 'svc',
            baseUrl: 'https://app.test',
            loginEndpoint: '/login',
            username: 'u',
            password: 'p',
        );

        $failed = new \Illuminate\Http\Client\Response(new Response(401));

        $this->assertFalse($handler->refresh($failed));
    }

    public function test_login_caches_state_as_plain_array_for_strict_serializable_classes(): void
    {
        // Default Laravel cache config sets `serializable_classes => false`,
        // which makes DatabaseStore unserialize cached values with
        // `allowed_classes => false` — any cached object resurfaces as
        // __PHP_Incomplete_Class. To survive that policy without weakening
        // the global cache hardening, the handler must cache the session
        // as a plain array. This guards against a regression to
        // `Cache::put($key, $stateObject, ttl)`.
        Http::fake([
            'https://app.test/login' => Http::response(['ok' => true], 200, [
                'Set-Cookie' => ['XSRF-TOKEN=tok; Path=/', 'laravel_session=ses; Path=/'],
            ]),
        ]);

        $handler = new SessionAuthHandler(
            serviceName: 'svc',
            baseUrl: 'https://app.test',
            loginEndpoint: '/login',
            username: 'u',
            password: 'p',
        );

        $handler->currentHeaders();

        $cached = Cache::get('api-admin:session:svc');

        $this->assertIsArray($cached);
        $this->assertArrayHasKey('cookies', $cached);
        $this->assertArrayHasKey('csrfToken', $cached);
        $this->assertArrayHasKey('expiresAt', $cached);
        $this->assertSame('tok', $cached['csrfToken']);
        $this->assertIsInt($cached['expiresAt']);
    }

    public function test_hosted_tenant_sessions_do_not_share_cache_keys(): void
    {
        Http::fake([
            'https://app.test/login' => Http::sequence()
                ->push(['ok' => true], 200, ['Set-Cookie' => ['laravel_session=tenant-a; Path=/', 'XSRF-TOKEN=a; Path=/']])
                ->push(['ok' => true], 200, ['Set-Cookie' => ['laravel_session=tenant-b; Path=/', 'XSRF-TOKEN=b; Path=/']]),
        ]);

        $tenantA = new SessionAuthHandler(
            serviceName: 'svc',
            baseUrl: 'https://app.test',
            loginEndpoint: '/login',
            username: 'u',
            password: 'p',
            tenantId: 'tenant-a',
        );
        $tenantB = new SessionAuthHandler(
            serviceName: 'svc',
            baseUrl: 'https://app.test',
            loginEndpoint: '/login',
            username: 'u',
            password: 'p',
            tenantId: 'tenant-b',
        );

        $this->assertStringContainsString('laravel_session=tenant-a', $tenantA->currentHeaders()['Cookie']);
        $this->assertStringContainsString('laravel_session=tenant-b', $tenantB->currentHeaders()['Cookie']);
        $this->assertStringContainsString('laravel_session=tenant-a', $tenantA->currentHeaders()['Cookie']);

        Http::assertSentCount(2);
        $this->assertTrue(Cache::has(TenantKeyBuilder::cacheKey('tenant-a', 'session', 'svc')));
        $this->assertTrue(Cache::has(TenantKeyBuilder::cacheKey('tenant-b', 'session', 'svc')));
    }

    public function test_authenticated_admins_get_independent_session_cache_slots(): void
    {
        Http::fake([
            'https://app.test/login' => Http::sequence()
                ->push(['ok' => true], 200, ['Set-Cookie' => ['laravel_session=user-101; Path=/', 'XSRF-TOKEN=csrf-101; Path=/']])
                ->push(['ok' => true], 200, ['Set-Cookie' => ['laravel_session=user-202; Path=/', 'XSRF-TOKEN=csrf-202; Path=/']])
                ->push(['ok' => true], 200, ['Set-Cookie' => ['laravel_session=user-101-refreshed; Path=/', 'XSRF-TOKEN=csrf-101b; Path=/']]),
        ]);

        $handler = new SessionAuthHandler(
            serviceName: 'svc',
            baseUrl: 'https://app.test',
            loginEndpoint: '/login',
            username: 'u',
            password: 'p',
        );

        $this->actingAsMoonshineUser(101);
        $this->assertStringContainsString('laravel_session=user-101', $handler->currentHeaders()['Cookie']);

        $this->actingAsMoonshineUser(202);
        $this->assertStringContainsString('laravel_session=user-202', $handler->currentHeaders()['Cookie']);

        $this->actingAsMoonshineUser(101);
        $this->assertTrue($handler->refresh(new \Illuminate\Http\Client\Response(new Response(419))));
        $this->assertStringContainsString('laravel_session=user-101-refreshed', $handler->currentHeaders()['Cookie']);

        $this->actingAsMoonshineUser(202);
        $this->assertStringContainsString('laravel_session=user-202', $handler->currentHeaders()['Cookie']);

        Http::assertSentCount(3);
        $this->assertTrue(Cache::has(TenantKeyBuilder::cacheKey('local', 'session', 'svc', 'user', '101')));
        $this->assertTrue(Cache::has(TenantKeyBuilder::cacheKey('local', 'session', 'svc', 'user', '202')));
    }

    public function test_cached_array_state_is_reused_without_relogin(): void
    {
        // After the cache-shape fix, a pre-seeded array cache entry must
        // be honored by the handler so subsequent requests reuse the
        // session instead of forcing a fresh login flow on every call.
        Cache::put('api-admin:session:svc', [
            'cookies' => ['laravel_session' => 'cached', 'XSRF-TOKEN' => 'cached-tok'],
            'csrfToken' => 'cached-tok',
            'expiresAt' => time() + 3600,
        ], 3600);

        Http::fake([
            'https://app.test/login' => Http::response(['SHOULD_NOT_HIT' => true], 500),
        ]);

        $handler = new SessionAuthHandler(
            serviceName: 'svc',
            baseUrl: 'https://app.test',
            loginEndpoint: '/login',
            username: 'u',
            password: 'p',
        );

        $headers = $handler->currentHeaders();

        $this->assertStringContainsString('laravel_session=cached', $headers['Cookie']);
        $this->assertSame('cached-tok', $headers['X-XSRF-TOKEN']);
        Http::assertNothingSent();
    }

    public function test_cached_state_with_stale_object_shape_falls_through_to_login(): void
    {
        // Pre-fix deploys cached SessionState as an object. After upgrade
        // the cache may still hold those serialized objects which now
        // resurface as __PHP_Incomplete_Class (or arbitrary stdClass).
        // The handler must treat them as cache misses and do a fresh
        // login, NOT crash on a property access against the wrong type.
        Cache::put('api-admin:session:svc', (object) [
            'cookies' => ['x' => 'y'],
            'csrfToken' => 'stale',
            'expiresAt' => time() + 3600,
        ], 3600);

        Http::fake([
            'https://app.test/login' => Http::response(['ok' => true], 200, [
                'Set-Cookie' => ['laravel_session=fresh; Path=/', 'XSRF-TOKEN=fresh-tok; Path=/'],
            ]),
        ]);

        $handler = new SessionAuthHandler(
            serviceName: 'svc',
            baseUrl: 'https://app.test',
            loginEndpoint: '/login',
            username: 'u',
            password: 'p',
        );

        $headers = $handler->currentHeaders();

        $this->assertStringContainsString('laravel_session=fresh', $headers['Cookie']);
        $this->assertSame('fresh-tok', $headers['X-XSRF-TOKEN']);
    }

    public function test_required_login_params_throw_runtime_exception(): void
    {
        $handler = new SessionAuthHandler(
            serviceName: 'svc',
            baseUrl: 'https://app.test',
            loginEndpoint: '', // missing
            username: '',
            password: '',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/login_endpoint, username, password are required/');

        $handler->currentHeaders();
    }

    private function actingAsMoonshineUser(int $id): void
    {
        config()->set('auth.guards.moonshine', [
            'driver' => 'session',
            'provider' => 'users',
        ]);
        config()->set('auth.providers.users', [
            'driver' => 'eloquent',
            'model' => AuthenticatableUser::class,
        ]);

        $user = new AuthenticatableUser;
        $user->setAttribute('id', $id);

        Auth::guard('moonshine')->setUser($user);
    }
}
