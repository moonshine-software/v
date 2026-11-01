<?php

declare(strict_types=1);

namespace App\ApiAdmin\Auth;

/**
 * Cached server-issued session credentials for one service.
 *
 * Stored in the application Cache by SessionAuthHandler under
 * `api-admin:session:<service>`. Re-issued on 401/419 retry.
 *
 * Why a DTO and not a free-form array: cache deserialization stays type-safe
 * (PHPStan can reason about the shape) and rebuilding a request from cached
 * state has one canonical method (`toAuthHeaders`) instead of scattered
 * `array_key_exists` checks at every call site.
 *
 * @phpstan-type CookieMap array<string, string>
 */
final readonly class SessionState
{
    /**
     * @param array<string, string> $cookies Cookie name → value pairs harvested
     *                                       from `Set-Cookie` headers across the
     *                                       login flow. Sent as a single
     *                                       `Cookie: name=value; ...` header.
     * @param string $csrfToken CSRF token to send back to the
     *                          upstream on each request via the
     *                          configured CSRF header (e.g.
     *                          `X-XSRF-TOKEN`). Empty when the
     *                          upstream does not expose a CSRF
     *                          token under the configured key.
     * @param int $expiresAt Unix timestamp at which the cached
     *                       state should be considered stale and
     *                       re-logged in. Independent from the
     *                       Cache TTL — a separate guard so we
     *                       can pre-emptively rotate before the
     *                       cache itself evicts.
     */
    public function __construct(
        public array $cookies,
        public string $csrfToken,
        public int $expiresAt,
    ) {}

    public function isExpired(): bool
    {
        return $this->expiresAt > 0 && time() >= $this->expiresAt;
    }

    /**
     * @return array{cookies: array<string, string>, csrfToken: string, expiresAt: int}
     */
    public function toArray(): array
    {
        return [
            'cookies' => $this->cookies,
            'csrfToken' => $this->csrfToken,
            'expiresAt' => $this->expiresAt,
        ];
    }

    /**
     * Rebuild a SessionState from the array shape produced by toArray().
     *
     * Stored as an array rather than a serialized object so the cache layer
     * can deserialize it under strict `serializable_classes` policies (the
     * default Laravel cache config disallows arbitrary classes — caching
     * the object directly resurfaces it as `__PHP_Incomplete_Class`).
     *
     * Returns null on shape mismatch — caller falls through to a fresh
     * login rather than blow up on a cache poisoned with stale state.
     *
     * @param mixed $value
     */
    public static function fromArray($value): ?self
    {
        if (! is_array($value)) {
            return null;
        }

        $cookies = $value['cookies'] ?? null;
        $csrfToken = $value['csrfToken'] ?? null;
        $expiresAt = $value['expiresAt'] ?? null;

        if (! is_array($cookies) || ! is_string($csrfToken) || ! is_int($expiresAt)) {
            return null;
        }

        $stringCookies = [];

        foreach ($cookies as $name => $cookieValue) {
            if (! is_string($name) || ! is_string($cookieValue)) {
                return null;
            }

            $stringCookies[$name] = $cookieValue;
        }

        return new self(
            cookies: $stringCookies,
            csrfToken: $csrfToken,
            expiresAt: $expiresAt,
        );
    }

    /**
     * Build the Cookie header value from the stored cookie jar.
     *
     * Returns empty string when no cookies are cached — caller should not
     * attach a Cookie header with an empty value (Guzzle may then strip it).
     */
    public function cookieHeader(): string
    {
        if ($this->cookies === []) {
            return '';
        }

        $parts = [];

        foreach ($this->cookies as $name => $value) {
            $parts[] = $name.'='.$value;
        }

        return implode('; ', $parts);
    }

    /**
     * Build the auth header map for ServiceConfig::authHeaders('session') and
     * SessionAuthHandler::rebuildRequest. Combined here so the two call sites
     * cannot drift in what they emit.
     *
     * @return array<string, string>
     */
    public function toAuthHeaders(string $csrfHeaderName): array
    {
        $headers = [];

        $cookieHeader = $this->cookieHeader();

        if ($cookieHeader !== '') {
            $headers['Cookie'] = $cookieHeader;
        }

        if ($csrfHeaderName !== '' && $this->csrfToken !== '') {
            $headers[$csrfHeaderName] = $this->csrfToken;
        }

        return $headers;
    }
}
