<?php

declare(strict_types=1);

namespace App\ApiAdmin\Auth;

use App\ApiAdmin\Http\OutboundHttp;
use App\ApiAdmin\Tenancy\TenantContext;
use App\ApiAdmin\Tenancy\TenantKeyBuilder;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Throwable;

/**
 * Cookie + CSRF auto-login handler.
 *
 * Covers the three real-world session flows the milestone targets:
 *
 *   - Laravel Sanctum SPA: GET /sanctum/csrf-cookie → POST /login → captured
 *     XSRF-TOKEN cookie sent back in `X-XSRF-TOKEN` header.
 *   - Django session: POST /api/auth/login → captured `sessionid` cookie + CSRF
 *     token from response body or `X-CSRFToken` header.
 *   - Rails-style: POST /users/sign_in → captured `_session_id` cookie +
 *     `authenticity_token` from response body.
 *
 * Login state is cached for `ttl` seconds. When a MoonShine/upstream admin is
 * authenticated, the cache key includes that user id so per-user sessions do
 * not overwrite each other. On 401/419 the handler drops the cached state,
 * performs a fresh login, and replays the original request with the new cookie
 * + CSRF header.
 *
 * What this class does NOT do:
 *   - Concurrent-login locking. Two simultaneous 401 retries may both refresh
 *     and one will overwrite the other's cache write — accepted as a
 *     non-issue (rare, and Cache::put is last-write-wins).
 */
final readonly class SessionAuthHandler implements AuthRetryHandler
{
    public const string CSRF_SOURCE_COOKIE = 'cookie';

    public const string CSRF_SOURCE_HEADER = 'header';

    public const string CSRF_SOURCE_BODY = 'body';

    public function __construct(
        private string $serviceName,
        private string $baseUrl,
        private string $loginEndpoint,
        private string $loginMethod = 'POST',
        private string $usernameField = 'email',
        private string $passwordField = 'password',
        private string $username = '',
        private string $password = '',
        private string $csrfTokenSource = self::CSRF_SOURCE_COOKIE,
        private string $csrfTokenKey = 'XSRF-TOKEN',
        private string $csrfHeaderName = 'X-XSRF-TOKEN',
        private string $csrfBootstrapPath = '',
        private int $ttl = 7200,
        private string $tenantId = TenantContext::LOCAL_ID,
    ) {}

    /**
     * Returns the auth headers to attach to outgoing requests built from a
     * fresh `httpClient()`. Triggers a login when nothing is cached yet — the
     * login endpoint is hit synchronously here, on the call path of the very
     * first request after cache eviction.
     *
     * @return array<string, string>
     */
    public function currentHeaders(): array
    {
        return $this->ensureSessionState()->toAuthHeaders($this->csrfHeaderName);
    }

    public function refresh(Response $failed, ?RequestInterface $request = null): bool
    {
        Log::info('[SessionAuthHandler.refresh] dropping cached session', [
            'service' => $this->serviceName,
            'status' => $failed->status(),
            'cache_scope' => $this->cacheScope(),
        ]);

        Cache::forget($this->cacheKey());

        try {
            $this->ensureSessionState();

            return true;
        } catch (Throwable $e) {
            Log::error('[SessionAuthHandler.refresh] re-login failed', [
                'service' => $this->serviceName,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function rebuildRequest(RequestInterface $request): RequestInterface
    {
        $headers = $this->ensureSessionState()->toAuthHeaders($this->csrfHeaderName);

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $request;
    }

    public function service(): string
    {
        return $this->serviceName;
    }

    /**
     * Force a re-login next time `currentHeaders()` is called. Test seam.
     */
    public function invalidate(): void
    {
        Cache::forget($this->cacheKey());
    }

    /**
     * Validate credentials supplied by a human admin login and return a fresh
     * cookie/CSRF state without writing it to the service-wide cache.
     */
    public function loginFresh(): SessionState
    {
        return $this->login(cache: false);
    }

    private function ensureSessionState(): SessionState
    {
        $cached = SessionState::fromArray(Cache::get($this->cacheKey()));

        if ($cached instanceof SessionState && ! $cached->isExpired()) {
            Log::debug('[SessionAuthHandler] using cached session', [
                'service' => $this->serviceName,
                'expires_in' => max(0, $cached->expiresAt - time()),
                'cache_scope' => $this->cacheScope(),
            ]);

            return $cached;
        }

        return $this->login(cache: true);
    }

    private function login(bool $cache): SessionState
    {
        Log::info('[SessionAuthHandler.login] establishing session', [
            'service' => $this->serviceName,
            'login_endpoint' => $this->loginEndpoint,
            'cache_scope' => $this->cacheScope(),
        ]);

        if ($this->loginEndpoint === '' || $this->username === '' || $this->password === '') {
            throw new RuntimeException(
                "SessionAuthHandler[{$this->serviceName}]: login_endpoint, username, password are required.",
            );
        }

        $cookies = [];

        if ($this->csrfBootstrapPath !== '') {
            $cookies = $this->bootstrapCsrfCookies();
        }

        $loginCookieHeader = $this->cookieHeaderValue($cookies);
        $bootstrapCsrf = $this->csrfTokenSource === self::CSRF_SOURCE_COOKIE
            ? ($cookies[$this->csrfTokenKey] ?? '')
            : '';

        $request = OutboundHttp::client($this->baseUrl)
            ->connectTimeout(5)
            ->timeout(15)
            ->acceptJson();

        if ($loginCookieHeader !== '') {
            $request = $request->withHeaders(['Cookie' => $loginCookieHeader]);
        }

        if ($bootstrapCsrf !== '') {
            $request = $request->withHeaders([$this->csrfHeaderName => urldecode($bootstrapCsrf)]);
        }

        $payload = [
            $this->usernameField => $this->username,
            $this->passwordField => $this->password,
        ];

        $response = match (strtoupper($this->loginMethod)) {
            'POST' => $request->post($this->loginEndpoint, $payload),
            'PUT' => $request->put($this->loginEndpoint, $payload),
            default => throw new RuntimeException(
                "SessionAuthHandler[{$this->serviceName}]: unsupported login_method '{$this->loginMethod}'.",
            ),
        };

        if (! $response->successful()) {
            Log::error('[SessionAuthHandler.login] failed', [
                'service' => $this->serviceName,
                'status' => $response->status(),
            ]);

            throw new RuntimeException(
                "SessionAuthHandler[{$this->serviceName}]: login failed with HTTP {$response->status()}.",
            );
        }

        $cookies = array_merge($cookies, $this->parseSetCookieHeaders($response));

        $csrf = $this->extractCsrfToken($response, $cookies);

        $state = new SessionState(
            cookies: $cookies,
            csrfToken: $csrf,
            expiresAt: time() + max(60, $this->ttl),
        );

        if ($cache) {
            Cache::put($this->cacheKey(), $state->toArray(), max(60, $this->ttl));
        }

        Log::debug('[SessionAuthHandler.login] csrf captured', [
            'service' => $this->serviceName,
            'source' => $this->csrfTokenSource,
            'key' => $this->csrfTokenKey,
            'present' => $csrf !== '',
            'cookie_names' => array_keys($cookies),
            'cache_scope' => $this->cacheScope(),
        ]);

        return $state;
    }

    /**
     * @return array<string, string>
     */
    private function bootstrapCsrfCookies(): array
    {
        $response = OutboundHttp::client($this->baseUrl)
            ->connectTimeout(5)
            ->timeout(15)
            ->acceptJson()
            ->get($this->csrfBootstrapPath);

        if (! $response->successful()) {
            throw new RuntimeException(
                "SessionAuthHandler[{$this->serviceName}]: csrf bootstrap failed with HTTP {$response->status()}.",
            );
        }

        return $this->parseSetCookieHeaders($response);
    }

    /**
     * @param array<string, string> $cookies
     */
    private function cookieHeaderValue(array $cookies): string
    {
        if ($cookies === []) {
            return '';
        }

        $parts = [];

        foreach ($cookies as $name => $value) {
            $parts[] = $name.'='.$value;
        }

        return implode('; ', $parts);
    }

    /**
     * Parse `Set-Cookie` headers from a Laravel HTTP Response.
     *
     * Returns name → value pairs only — attribute parsing (Domain, Path,
     * Secure, HttpOnly, SameSite) is intentionally skipped. The handler
     * does not enforce cookie scope; the upstream's own cookie attributes
     * are honored by Guzzle when the cookie is sent back unchanged.
     *
     * @return array<string, string>
     */
    private function parseSetCookieHeaders(Response $response): array
    {
        // Laravel Response::header() joins multi-value headers with ', '
        // which breaks Set-Cookie (commas appear inside Expires=). Read the
        // raw multi-value header from the wrapped PSR-7 response instead.
        $rawCookies = $response->toPsrResponse()->getHeader('Set-Cookie');

        $cookies = [];

        foreach ($rawCookies as $line) {
            $first = trim(explode(';', $line, 2)[0]);

            if ($first === '') {
                continue;
            }

            $eq = strpos($first, '=');

            if ($eq === false) {
                continue;
            }

            $name = substr($first, 0, $eq);
            $value = substr($first, $eq + 1);

            if ($name === '') {
                continue;
            }

            $cookies[$name] = $value;
        }

        return $cookies;
    }

    /**
     * @param array<string, string> $cookies
     */
    private function extractCsrfToken(Response $response, array $cookies): string
    {
        return match ($this->csrfTokenSource) {
            self::CSRF_SOURCE_COOKIE => urldecode($cookies[$this->csrfTokenKey] ?? ''),
            self::CSRF_SOURCE_HEADER => $response->header($this->csrfTokenKey),
            self::CSRF_SOURCE_BODY => $this->extractCsrfFromBody($response),
            default => '',
        };
    }

    private function extractCsrfFromBody(Response $response): string
    {
        $json = $response->json();

        if (! is_array($json)) {
            return '';
        }

        $value = $json[$this->csrfTokenKey] ?? '';

        return is_string($value) ? $value : '';
    }

    private function cacheKey(): string
    {
        return TenantKeyBuilder::cacheKey(
            $this->tenantId,
            'session',
            $this->serviceName,
            ...$this->userKeyParts(),
        );
    }

    /**
     * @return list<string>
     */
    private function userKeyParts(): array
    {
        $userId = CurrentAdminUser::id();

        if ($userId === null) {
            return [];
        }

        return ['user', $userId];
    }

    private function cacheScope(): string
    {
        return CurrentAdminUser::id() === null ? 'service' : 'user';
    }
}
