<?php

declare(strict_types=1);

namespace App\ApiAdmin\Auth;

use App\ApiAdmin\Http\OutboundHttp;
use App\ApiAdmin\Tenancy\TenantContext;
use App\ApiAdmin\Tenancy\TenantKeyBuilder;
use App\ApiAdmin\Tenancy\TenantSecretStore;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Encapsulates OAuth 2.0 token acquisition + cache for one service.
 *
 * Supports three grants:
 *   - client_credentials (machine-to-machine, no user)
 *   - password (Resource Owner Password Credentials)
 *   - refresh_token (rotate a long-lived refresh token to mint new access tokens)
 *   - authorization_code (OAuth login callback exchange with PKCE)
 *
 * Each grant has its own cached access token (separate cache key per grant), so
 * a service that switches between grants in tests does not get a stale value
 * from another grant. When a MoonShine/upstream admin is authenticated, cache
 * keys gain a user segment so one admin's refresh cycle cannot affect another.
 * The refresh_token is persisted under a grant-independent key because it is
 * intrinsically tied to the user identity, not to the grant that minted it
 * (rotated tokens issued by the password grant are valid input for the
 * refresh_token grant on subsequent calls).
 *
 * The store is the single source of truth for OAuth tokens; ServiceConfig keeps
 * thin proxies (fetchOAuthAccessToken / invalidateOAuthToken) that delegate
 * here, so existing call sites do not need to know about grants.
 */
final readonly class OAuth2TokenStore
{
    public const string GRANT_CLIENT_CREDENTIALS = 'client_credentials';

    public const string GRANT_PASSWORD = 'password';

    public const string GRANT_REFRESH_TOKEN = 'refresh_token';

    public const string GRANT_AUTHORIZATION_CODE = 'authorization_code';

    /**
     * Floor on access-token TTL — never cache for less than 60 seconds even if
     * the IdP returns expires_in < 90. Below 60s the refresh churn becomes its
     * own latency problem.
     */
    private const int ACCESS_TOKEN_MIN_TTL_SECONDS = 60;

    /**
     * Margin shaved off `expires_in` so we never hand out a token that's about
     * to expire mid-request. 30s covers typical request latency + clock skew.
     */
    private const int ACCESS_TOKEN_EXPIRY_MARGIN_SECONDS = 30;

    public function __construct(
        public string $serviceName,
        public string $tokenUrl,
        public string $clientId,
        public string $clientSecret,
        public string $scope = '',
        public string $username = '',
        public string $password = '',
        public string $seedRefreshToken = '',
        public string $tenantId = TenantContext::LOCAL_ID,
        private string $authorizationCode = '',
        private string $codeVerifier = '',
        private string $redirectUri = '',
    ) {}

    /**
     * Get a cached access token for the given grant, fetching one if absent.
     */
    public function getAccessToken(string $grant): string
    {
        /** @var string|null $cached */
        $cached = Cache::get($this->accessKey($grant));

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        return $this->fetch($grant);
    }

    /**
     * Force a fresh fetch ignoring any cached access token. Used by
     * OAuth2AuthHandler on 401 — the cached token may be valid by TTL but the
     * upstream considers it dead, so we drop it and ask for a new one.
     *
     * For the refresh_token grant, this also rotates the refresh token if the
     * IdP's response contains a new one.
     */
    public function refresh(string $grant, ?string $rejectedToken = null): string
    {
        return $this->fetch($grant, $rejectedToken, force: true);
    }

    /**
     * Validate credentials supplied by a human admin login and return the
     * resulting token state without storing the user's password.
     *
     * @return array{access_token: string, refresh_token: string, expires_at: int}
     */
    public function fetchPasswordGrantForCredentials(string $username, string $password): array
    {
        $store = new self(
            serviceName: $this->serviceName,
            tokenUrl: $this->tokenUrl,
            clientId: $this->clientId,
            clientSecret: $this->clientSecret,
            scope: $this->scope,
            username: $username,
            password: $password,
            seedRefreshToken: '',
            tenantId: $this->tenantId,
        );

        return $store->fetchState(self::GRANT_PASSWORD, cache: false);
    }

    /**
     * Exchange a human admin's delegated refresh token without storing it in
     * the service-wide cache. The returned state is written to the Laravel
     * session by DelegatedTokenAuthHandler.
     *
     * @return array{access_token: string, refresh_token: string, expires_at: int}
     */
    public function fetchRefreshGrantForToken(string $refreshToken): array
    {
        $store = new self(
            serviceName: $this->serviceName,
            tokenUrl: $this->tokenUrl,
            clientId: $this->clientId,
            clientSecret: $this->clientSecret,
            scope: $this->scope,
            username: '',
            password: '',
            seedRefreshToken: $refreshToken,
            tenantId: $this->tenantId,
        );

        return $store->fetchState(self::GRANT_REFRESH_TOKEN, cache: false);
    }

    /**
     * Exchange an OAuth Authorization Code + PKCE verifier for delegated
     * administrator token state. This is deliberately not cached service-wide:
     * panel login owns persistence in its session and upstream identity.
     *
     * @return array{access_token: string, refresh_token: string, expires_at: int}
     */
    public function fetchAuthorizationCodeGrant(string $code, string $codeVerifier, string $redirectUri): array
    {
        $store = new self(
            serviceName: $this->serviceName,
            tokenUrl: $this->tokenUrl,
            clientId: $this->clientId,
            clientSecret: $this->clientSecret,
            scope: $this->scope,
            username: '',
            password: '',
            seedRefreshToken: '',
            tenantId: $this->tenantId,
            authorizationCode: $code,
            codeVerifier: $codeVerifier,
            redirectUri: $redirectUri,
        );

        return $store->fetchState(self::GRANT_AUTHORIZATION_CODE, cache: false);
    }

    /**
     * Drop the cached access token for the given grant. The refresh token
     * cache is preserved — invalidation only fires on access-token failure,
     * which does not necessarily invalidate the refresh-token chain.
     */
    public function invalidate(string $grant): void
    {
        Cache::forget($this->accessKey($grant));
    }

    public function forgetLegacyRefreshToken(): void
    {
        Cache::forget($this->refreshTokenKey());
    }

    /**
     * Drop both cached access AND refresh tokens. Used when the user's
     * credentials change (re-registered service) or a destructive action
     * needs a clean slate.
     */
    public function invalidateAll(): void
    {
        Cache::forget($this->accessKey(self::GRANT_CLIENT_CREDENTIALS));
        Cache::forget($this->accessKey(self::GRANT_PASSWORD));
        Cache::forget($this->accessKey(self::GRANT_REFRESH_TOKEN));
        Cache::forget($this->refreshTokenKey());
        new TenantSecretStore()->putMany($this->tenantId, $this->serviceName, [$this->refreshSecretName() => '']);
    }

    private function fetch(string $grant, ?string $rejectedToken = null, bool $force = false): string
    {
        $token = Cache::lock($this->refreshTokenKey().':lock', 30)->block(20, function () use ($grant, $rejectedToken, $force): string {
            $cached = Cache::get($this->accessKey($grant));
            if (is_string($cached) && $cached !== ''
                && (! $force || ($rejectedToken !== null && $cached !== $rejectedToken))) {
                return $cached;
            }
            if ($force) {
                Cache::forget($this->accessKey($grant));
            }

            return $this->fetchState($grant, cache: true)['access_token'];
        });
        if (! is_string($token)) {
            throw new RuntimeException('OAuth access token must be a string.');
        }

        return $token;
    }

    /**
     * @return array{access_token: string, refresh_token: string, expires_at: int}
     */
    private function fetchState(string $grant, bool $cache): array
    {
        $this->validateGrantParams($grant);

        $payload = $this->buildPayload($grant, $cache);

        Log::debug('[OAuth2TokenStore.fetch]', [
            'service' => $this->serviceName,
            'grant' => $grant,
            'token_url' => $this->tokenUrl,
            'cache_scope' => $this->cacheScope(),
        ]);

        $response = OutboundHttp::client($this->tokenUrl)->asForm()
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(15)
            ->post($this->tokenUrl, $payload);

        if (! $response->successful()) {
            Log::error('[OAuth2TokenStore.fetch] failed', [
                'service' => $this->serviceName,
                'grant' => $grant,
                'status' => $response->status(),
            ]);

            throw new RuntimeException(
                "OAuth2TokenStore[{$this->serviceName}]: {$grant} grant failed with HTTP {$response->status()}.",
            );
        }

        return $this->tokenStateFromResponse($grant, $response, $cache);
    }

    /**
     * @return array<string, string>
     */
    private function buildPayload(string $grant, bool $cache): array
    {
        $payload = match ($grant) {
            self::GRANT_CLIENT_CREDENTIALS => [
                'grant_type' => self::GRANT_CLIENT_CREDENTIALS,
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
            ],
            self::GRANT_PASSWORD => [
                'grant_type' => self::GRANT_PASSWORD,
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'username' => $this->username,
                'password' => $this->password,
            ],
            self::GRANT_REFRESH_TOKEN => [
                'grant_type' => self::GRANT_REFRESH_TOKEN,
                'client_id' => $this->clientId,
                'refresh_token' => $cache ? $this->resolveRefreshToken() : $this->seedRefreshToken,
            ],
            self::GRANT_AUTHORIZATION_CODE => [
                'grant_type' => self::GRANT_AUTHORIZATION_CODE,
                'client_id' => $this->clientId,
                'code' => $this->authorizationCode,
                'code_verifier' => $this->codeVerifier,
                'redirect_uri' => $this->redirectUri,
            ],
            default => throw new RuntimeException(
                "OAuth2TokenStore[{$this->serviceName}]: unknown grant '{$grant}'.",
            ),
        };

        // Scope is meaningful for client_credentials and password. RFC 6749
        // §6 says refresh_token MAY include scope but it must not exceed the
        // original — safer to omit and let the IdP echo whatever it minted.
        if ($this->scope !== '' && in_array($grant, [self::GRANT_CLIENT_CREDENTIALS, self::GRANT_PASSWORD], true)) {
            $payload['scope'] = $this->scope;
        }

        if ($this->clientSecret !== '') {
            $payload['client_secret'] = $this->clientSecret;
        }

        return $payload;
    }

    /**
     * @return array{access_token: string, refresh_token: string, expires_at: int}
     */
    private function tokenStateFromResponse(string $grant, Response $response, bool $cache): array
    {
        $json = $response->json();

        if (! is_array($json) || ! isset($json['access_token']) || ! is_string($json['access_token']) || $json['access_token'] === '') {
            throw new RuntimeException(
                "OAuth2TokenStore[{$this->serviceName}]: {$grant} response missing 'access_token'.",
            );
        }

        $token = $json['access_token'];
        $expiresInRaw = $json['expires_in'] ?? 3600;
        $expiresIn = is_int($expiresInRaw) ? $expiresInRaw : (is_numeric($expiresInRaw) ? (int) $expiresInRaw : 3600);
        $ttl = max(self::ACCESS_TOKEN_MIN_TTL_SECONDS, $expiresIn - self::ACCESS_TOKEN_EXPIRY_MARGIN_SECONDS);

        Log::info('[OAuth2TokenStore.fetch] cached access_token', [
            'service' => $this->serviceName,
            'grant' => $grant,
            'ttl_seconds' => $ttl,
            'cache_scope' => $this->cacheScope(),
        ]);

        $refreshToken = '';

        if (isset($json['refresh_token']) && is_string($json['refresh_token']) && $json['refresh_token'] !== '') {
            $refreshToken = $json['refresh_token'];

            if ($cache) {
                new TenantSecretStore()->putMany($this->tenantId, $this->serviceName, [
                    $this->refreshSecretName() => $refreshToken,
                ]);
                Cache::forget($this->refreshTokenKey());
                Log::debug('Persisted refresh token', ['service' => $this->serviceName]);
            }

            Log::info('[OAuth2TokenStore.fetch] rotated refresh_token', [
                'service' => $this->serviceName,
                'grant' => $grant,
                'cache_scope' => $this->cacheScope(),
            ]);
        }

        if ($cache) {
            Cache::put($this->accessKey($grant), $token, $ttl);
        }

        return [
            'access_token' => $token,
            'refresh_token' => $refreshToken,
            'expires_at' => time() + $ttl,
        ];
    }

    /**
     * Prefer durable rotation state, migrating the old cache before using the seed.
     */
    private function resolveRefreshToken(): string
    {
        $secrets = new TenantSecretStore;
        $persisted = $secrets->get($this->tenantId, $this->serviceName, $this->refreshSecretName());
        if ($persisted !== '') {
            return $persisted;
        }

        /** @var string|null $cached */
        $cached = Cache::get($this->refreshTokenKey());

        if (is_string($cached) && $cached !== '') {
            $secrets->putMany($this->tenantId, $this->serviceName, [$this->refreshSecretName() => $cached]);
            Cache::forget($this->refreshTokenKey());

            return $cached;
        }

        if ($this->seedRefreshToken !== '') {
            return $this->seedRefreshToken;
        }

        throw new RuntimeException(
            "OAuth2TokenStore[{$this->serviceName}]: refresh_token grant requires either a cached or seeded refresh_token.",
        );
    }

    private function validateGrantParams(string $grant): void
    {
        if ($this->tokenUrl === '' || $this->clientId === '') {
            throw new RuntimeException(
                "OAuth2TokenStore[{$this->serviceName}]: token_url and client_id are required.",
            );
        }

        if (in_array($grant, [self::GRANT_CLIENT_CREDENTIALS, self::GRANT_PASSWORD], true) && $this->clientSecret === '') {
            throw new RuntimeException(
                "OAuth2TokenStore[{$this->serviceName}]: {$grant} grant requires client_secret.",
            );
        }

        if ($grant === self::GRANT_PASSWORD && ($this->username === '' || $this->password === '')) {
            throw new RuntimeException(
                "OAuth2TokenStore[{$this->serviceName}]: password grant requires username + password.",
            );
        }

        if ($grant === self::GRANT_AUTHORIZATION_CODE && ($this->authorizationCode === '' || $this->codeVerifier === '' || $this->redirectUri === '')) {
            throw new RuntimeException(
                "OAuth2TokenStore[{$this->serviceName}]: authorization_code grant requires code, code_verifier and redirect_uri.",
            );
        }
    }

    private function accessKey(string $grant): string
    {
        $parts = [
            ...$this->userKeyParts(),
            'access',
            $grant,
            md5($this->clientId.'|'.$this->scope.'|'.$this->tokenUrl),
            ...($this->seedRefreshToken === '' ? [] : [hash('sha256', $this->seedRefreshToken)]),
        ];

        return TenantKeyBuilder::cacheKey(
            $this->tenantId,
            'oauth2',
            $this->serviceName,
            ...$parts,
        );
    }

    private function refreshTokenKey(): string
    {
        $parts = [
            ...$this->userKeyParts(),
            'refresh',
            md5($this->clientId.'|'.$this->tokenUrl),
        ];

        return TenantKeyBuilder::cacheKey(
            $this->tenantId,
            'oauth2',
            $this->serviceName,
            ...$parts,
        );
    }

    private function refreshSecretName(): string
    {
        return 'oauth_refresh_'.hash('sha256', $this->refreshTokenKey().'|'.$this->seedRefreshToken);
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
