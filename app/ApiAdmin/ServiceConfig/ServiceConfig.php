<?php

declare(strict_types=1);

namespace App\ApiAdmin\ServiceConfig;

use App\ApiAdmin\Auth\AuthRetryHandler;
use App\ApiAdmin\Auth\DelegatedAuthSession;
use App\ApiAdmin\Auth\DelegatedTokenAuthHandler;
use App\ApiAdmin\Auth\OAuth2AuthHandler;
use App\ApiAdmin\Auth\OAuth2TokenStore;
use App\ApiAdmin\Auth\RetryOn401Middleware;
use App\ApiAdmin\Auth\SessionAuthHandler;
use App\ApiAdmin\Auth\SessionState;
use App\ApiAdmin\Http\OutboundHttp;
use App\ApiAdmin\Tenancy\TenantContext;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Log;

/**
 * DTO holding configuration for a single microservice connection.
 *
 * Each registered microservice has its own ServiceConfig with base URL, auth credentials,
 * response mapping, and panel assignment. This DTO is a leaf — no dependencies on other modules.
 */
final readonly class ServiceConfig
{
    /**
     * @param string $name Unique service identifier (e.g. 'blog', 'shop')
     * @param string $baseUrl API base URL (e.g. 'https://api.blog.example.com/v1')
     * @param string $apiKey Credential payload. Interpretation depends on authType:
     *                       - bearer: raw token
     *                       - header / query: value sent under the configured header/query key
     *                       - basic: "user:password" (colon-delimited; we base64-encode at send time)
     *                       - oauth2_cc: unused (token obtained from tokenUrl via clientId/clientSecret)
     *                       - none: ignored
     * @param ResponseMapping $responseMapping Response format mapping
     * @param string $tenantId Tenant owner for hosted mode; `local` preserves legacy single-tenant installs.
     * @param string $panel Panel identifier for multi-panel routing (e.g. 'blog-admin')
     * @param string $authType Authentication type: 'bearer' (default), 'header', 'query', 'basic',
     *                         'oauth2_cc' (OAuth 2.0 client-credentials grant with auto-refresh),
     *                         or 'none' (no auth headers/query emitted).
     * @param string $authHeader Header name for auth (when authType is 'header')
     * @param string $healthPath Path appended to baseUrl for health checks (e.g. '/health'); empty pings the base URL itself
     * @param string $tokenUrl OAuth2 token endpoint URL. Only used when authType='oauth2_cc'.
     * @param string $oauthClientId OAuth2 client_id. Only used when authType='oauth2_cc'.
     * @param string $oauthClientSecret OAuth2 client_secret. Only used when authType='oauth2_cc'.
     * @param string $oauthScope Optional space-separated OAuth2 scopes (e.g. "read write").
     *                           Used by `oauth2_cc` and `oauth2_password`. Empty = no scope param.
     * @param string $authBehavior Retry-on-401 policy. 'auto' (default) installs
     *                             RetryOn401Middleware whenever authHandler() returns
     *                             a concrete handler. 'none' disables the middleware
     *                             unconditionally — useful in tests that want raw
     *                             upstream responses without the auto-refresh layer.
     * @param string $oauthUsername Resource-owner username. Used by `oauth2_password`.
     * @param string $oauthPassword Resource-owner password. Used by `oauth2_password`.
     * @param string $oauthRefreshToken Initial refresh token seed. Used by
     *                                  `oauth2_refresh_token` to bootstrap the
     *                                  rotation chain. Subsequent rotations are
     *                                  cached by OAuth2TokenStore.
     * @param string $sessionLoginEndpoint Path for the session login POST (e.g.
     *                                     `/api/auth/login`). Used by `session`.
     * @param string $sessionLoginMethod HTTP method for the login call. Default `POST`.
     * @param string $sessionUsernameField Form field name for the username/email.
     * @param string $sessionPasswordField Form field name for the password.
     * @param string $csrfTokenSource `cookie`, `header`, or `body` — where the
     *                                login response carries the CSRF token.
     * @param string $csrfTokenKey Cookie name / header name / body key under
     *                             which the CSRF token is found.
     * @param string $csrfHeaderName HTTP header name to send the CSRF token in
     *                               on subsequent authenticated requests.
     * @param string $csrfBootstrapPath Optional pre-flight GET to seed CSRF
     *                                  cookies (Sanctum-style `/sanctum/csrf-cookie`).
     * @param int $sessionTtl Session-state cache TTL in seconds.
     * @param string $sessionUsername Resource-owner username for the session
     *                                login POST (e.g. an email address).
     * @param string $sessionPassword Resource-owner password.
     * @param bool $adminLogin Legacy delegated login switch for the
     *                         oauth2_password/session flows.
     * @param AdminAuthConfig $adminAuth Per-panel administrator login mode.
     * @param RealtimeConfig $realtime Upstream webhook and page-refresh settings.
     */
    public function __construct(
        public string $name,
        public string $baseUrl,
        public string $apiKey,
        public ResponseMapping $responseMapping,
        public string $tenantId = TenantContext::LOCAL_ID,
        public string $panel = 'default',
        public string $authType = 'bearer',
        public string $authHeader = 'Authorization',
        public string $healthPath = '',
        public string $tokenUrl = '',
        public string $oauthClientId = '',
        public string $oauthClientSecret = '',
        public string $oauthScope = '',
        public string $authBehavior = 'auto',
        public string $oauthUsername = '',
        public string $oauthPassword = '',
        public string $oauthRefreshToken = '',
        public string $sessionLoginEndpoint = '',
        public string $sessionLoginMethod = 'POST',
        public string $sessionUsernameField = 'email',
        public string $sessionPasswordField = 'password',
        public string $csrfTokenSource = 'cookie',
        public string $csrfTokenKey = 'XSRF-TOKEN',
        public string $csrfHeaderName = 'X-XSRF-TOKEN',
        public string $csrfBootstrapPath = '',
        public int $sessionTtl = 7200,
        public string $sessionUsername = '',
        public string $sessionPassword = '',
        public bool $adminLogin = false,
        public AdminAuthConfig $adminAuth = new AdminAuthConfig,
        public RealtimeConfig $realtime = new RealtimeConfig,
    ) {}

    /**
     * Build authentication headers for HTTP requests.
     *
     * For oauth2_cc this triggers a token fetch (cached). All other types are inline.
     *
     * @return array<string, string>
     */
    public function authHeaders(): array
    {
        if ($this->adminAuth->mode === AdminAuthConfig::MODE_DELEGATED_TOKEN && request()->hasSession()) {
            return new DelegatedTokenAuthHandler(
                serviceName: $this->name,
                tokenStore: $this->oauthTokenStore(),
                request: request(),
            )->currentHeaders();
        }

        if ($this->adminAuth->mode === AdminAuthConfig::MODE_OAUTH2_AUTHORIZATION_CODE && request()->hasSession()) {
            return new DelegatedTokenAuthHandler(
                serviceName: $this->name,
                tokenStore: $this->oauthTokenStoreForAdminAuth(),
                request: request(),
                sessionType: 'oauth',
            )->currentHeaders();
        }

        if ($this->adminLogin && request()->hasSession()) {
            if ($this->authType === 'oauth2_password') {
                $state = DelegatedAuthSession::oauth(request(), $this->name);

                if ($state !== null) {
                    return ['Authorization' => 'Bearer '.$state['access_token']];
                }
            }

            if ($this->authType === 'session') {
                $state = DelegatedAuthSession::sessionState(request(), $this->name);

                if ($state instanceof SessionState) {
                    return $state->toAuthHeaders($this->csrfHeaderName);
                }
            }
        }

        return match ($this->authType) {
            'bearer' => ['Authorization' => 'Bearer '.$this->apiKey],
            'header' => [$this->authHeader => $this->apiKey],
            'basic' => ['Authorization' => 'Basic '.base64_encode($this->apiKey)],
            'oauth2_cc' => ['Authorization' => 'Bearer '.$this->oauthTokenStore()->getAccessToken(OAuth2TokenStore::GRANT_CLIENT_CREDENTIALS)],
            'oauth2_password' => ['Authorization' => 'Bearer '.$this->oauthTokenStore()->getAccessToken(OAuth2TokenStore::GRANT_PASSWORD)],
            'oauth2_refresh_token' => ['Authorization' => 'Bearer '.$this->oauthTokenStore()->getAccessToken(OAuth2TokenStore::GRANT_REFRESH_TOKEN)],
            'session' => $this->sessionAuthHandler()->currentHeaders(),
            default => [],
        };
    }

    /**
     * Build query parameters for authentication (when authType is 'query').
     *
     * @return array<string, string>
     */
    public function authQuery(): array
    {
        if ($this->authType === 'query') {
            return ['api_key' => $this->apiKey];
        }

        return [];
    }

    /**
     * Create a configured HTTP client with base URL, auth headers, and JSON defaults.
     *
     * Explicit connect/read timeouts: Guzzle defaults to 0 (no timeout) so a
     * slow or unreachable upstream would otherwise hang the caller for the
     * system socket limit (~60-120s). The MCP stdio loop can't answer pings
     * while a tool is waiting on HTTP, and Claude Code flips the server to
     * "failed" after a few missed pings — caps below keep the worst case
     * bounded without punishing legitimately slow admin pages.
     */
    public function httpClient(): PendingRequest
    {
        $client = OutboundHttp::client($this->baseUrl)
            ->connectTimeout(5)
            ->timeout(30)
            ->asJson()
            ->acceptJson()
            ->withHeaders($this->authHeaders())
            ->withQueryParameters($this->authQuery());

        if ($this->authBehavior === 'none') {
            return $client;
        }

        $handler = $this->authHandler();

        Log::debug('ServiceConfig::httpClient', [
            'service' => $this->name,
            'auth_type' => $this->authType,
            'handler' => $handler instanceof AuthRetryHandler ? $handler::class : null,
        ]);

        return RetryOn401Middleware::install($client, $handler);
    }

    /**
     * Build the AuthRetryHandler for this service, or null when the auth scheme
     * has no refresh semantics.
     *
     * Bearer/header/query/basic/none auth types are static — their credentials
     * cannot be "refreshed", so no handler is needed; a 401 means the credential
     * is wrong, not stale, and retrying would be pointless.
     */
    public function authHandler(): ?AuthRetryHandler
    {
        if ($this->adminAuth->mode === AdminAuthConfig::MODE_DELEGATED_TOKEN && request()->hasSession()) {
            return new DelegatedTokenAuthHandler(
                serviceName: $this->name,
                tokenStore: $this->oauthTokenStore(),
                request: request(),
            );
        }

        if ($this->adminAuth->mode === AdminAuthConfig::MODE_OAUTH2_AUTHORIZATION_CODE && request()->hasSession()) {
            return new DelegatedTokenAuthHandler(
                serviceName: $this->name,
                tokenStore: $this->oauthTokenStoreForAdminAuth(),
                request: request(),
                sessionType: 'oauth',
            );
        }

        $grant = match ($this->authType) {
            'oauth2_cc' => OAuth2TokenStore::GRANT_CLIENT_CREDENTIALS,
            'oauth2_password' => OAuth2TokenStore::GRANT_PASSWORD,
            'oauth2_refresh_token' => OAuth2TokenStore::GRANT_REFRESH_TOKEN,
            default => null,
        };

        if ($grant !== null) {
            return new OAuth2AuthHandler(
                tokenStore: $this->oauthTokenStore(),
                grant: $grant,
                serviceName: $this->name,
            );
        }

        if ($this->authType === 'session') {
            return $this->sessionAuthHandler();
        }

        return null;
    }

    /**
     * Build the SessionAuthHandler for this service.
     *
     * Decoupled from `authHandler()` so `authHeaders()` can also reach the
     * handler (it needs `currentHeaders()` for non-retry first requests).
     */
    public function sessionAuthHandler(): SessionAuthHandler
    {
        return $this->sessionAuthHandlerForCredentials($this->sessionUsername, $this->sessionPassword);
    }

    public function sessionAuthHandlerForCredentials(string $username, string $password): SessionAuthHandler
    {
        return new SessionAuthHandler(
            serviceName: $this->name,
            baseUrl: $this->baseUrl,
            loginEndpoint: $this->sessionLoginEndpoint,
            loginMethod: $this->sessionLoginMethod,
            usernameField: $this->sessionUsernameField,
            passwordField: $this->sessionPasswordField,
            username: $username,
            password: $password,
            csrfTokenSource: $this->csrfTokenSource,
            csrfTokenKey: $this->csrfTokenKey,
            csrfHeaderName: $this->csrfHeaderName,
            csrfBootstrapPath: $this->csrfBootstrapPath,
            ttl: $this->sessionTtl,
            tenantId: $this->tenantId,
        );
    }

    /**
     * Build the OAuth2 token store for this service.
     *
     * The store owns the token cache (per-grant access tokens + a shared
     * refresh-token slot). ServiceConfig is the readonly DTO; the store is
     * the cache adapter. Recreated per call because the underlying state
     * lives in Cache, not in either object.
     */
    public function oauthTokenStore(): OAuth2TokenStore
    {
        return new OAuth2TokenStore(
            serviceName: $this->name,
            tokenUrl: $this->tokenUrl,
            clientId: $this->oauthClientId,
            clientSecret: $this->oauthClientSecret,
            scope: $this->oauthScope,
            username: $this->oauthUsername,
            password: $this->oauthPassword,
            seedRefreshToken: $this->oauthRefreshToken,
            tenantId: $this->tenantId,
        );
    }

    public function oauthTokenStoreForAdminAuth(): OAuth2TokenStore
    {
        return new OAuth2TokenStore(
            serviceName: $this->name,
            tokenUrl: $this->adminAuth->tokenUrl !== '' ? $this->adminAuth->tokenUrl : $this->tokenUrl,
            clientId: $this->oauthClientId,
            clientSecret: $this->oauthClientSecret,
            scope: implode(' ', $this->adminAuth->scopes),
            tenantId: $this->tenantId,
        );
    }

    /**
     * Force a fresh token fetch on next call. No-op for non-oauth auth types.
     */
    public function invalidateOAuthToken(): void
    {
        if ($this->authType !== 'oauth2_cc' || $this->tokenUrl === '') {
            return;
        }

        $this->oauthTokenStore()->invalidate(OAuth2TokenStore::GRANT_CLIENT_CREDENTIALS);
    }

    /**
     * Create from config array (e.g. from config/api-admin.php).
     *
     * Accepts the raw dictionary straight from a PHP config file or a
     * JSON-RPC payload — each known key is re-typed defensively so the
     * resulting DTO is strictly typed regardless of how the input was
     * produced. Unknown keys are ignored.
     *
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        $responseMappingRaw = $config['response_mapping'] ?? null;
        if (is_array($responseMappingRaw)) {
            /** @var array<string, bool|string> $responseMapping */
            $responseMapping = [];
            foreach ($responseMappingRaw as $k => $v) {
                if (is_string($k) && (is_bool($v) || is_string($v))) {
                    $responseMapping[$k] = $v;
                }
            }
            $mapping = ResponseMapping::fromArray($responseMapping);
        } else {
            $mapping = new ResponseMapping;
        }

        $serviceName = is_string($config['name'] ?? null) ? $config['name'] : '';
        $realtime = RealtimeConfig::fromArray(self::stringKeyedArray($config['realtime'] ?? null), $serviceName);
        $adminAuthRaw = self::stringKeyedArray($config['admin_auth'] ?? null);
        if (! isset($adminAuthRaw['mode']) && is_string($config['admin_auth_mode'] ?? null)) {
            $adminAuthRaw['mode'] = $config['admin_auth_mode'];
        }
        $adminAuth = AdminAuthConfig::fromArray($adminAuthRaw, $serviceName);

        return new self(
            name: $serviceName,
            baseUrl: is_string($config['base_url'] ?? null) ? $config['base_url'] : '',
            apiKey: is_string($config['api_key'] ?? null) ? $config['api_key'] : '',
            responseMapping: $mapping,
            tenantId: is_string($config['tenant_id'] ?? null) && $config['tenant_id'] !== ''
                ? $config['tenant_id']
                : TenantContext::LOCAL_ID,
            panel: is_string($config['panel'] ?? null) ? $config['panel'] : 'default',
            authType: is_string($config['auth_type'] ?? null) ? $config['auth_type'] : 'bearer',
            authHeader: is_string($config['auth_header'] ?? null) ? $config['auth_header'] : 'Authorization',
            healthPath: is_string($config['health_path'] ?? null) ? $config['health_path'] : '',
            tokenUrl: is_string($config['token_url'] ?? null) ? $config['token_url'] : '',
            oauthClientId: is_string($config['oauth_client_id'] ?? null) ? $config['oauth_client_id'] : '',
            oauthClientSecret: is_string($config['oauth_client_secret'] ?? null) ? $config['oauth_client_secret'] : '',
            oauthScope: is_string($config['oauth_scope'] ?? null) ? $config['oauth_scope'] : '',
            authBehavior: is_string($config['auth_behavior'] ?? null) ? $config['auth_behavior'] : 'auto',
            oauthUsername: is_string($config['oauth_username'] ?? null) ? $config['oauth_username'] : '',
            oauthPassword: is_string($config['oauth_password'] ?? null) ? $config['oauth_password'] : '',
            oauthRefreshToken: is_string($config['oauth_refresh_token'] ?? null) ? $config['oauth_refresh_token'] : '',
            sessionLoginEndpoint: is_string($config['session_login_endpoint'] ?? null) ? $config['session_login_endpoint'] : '',
            sessionLoginMethod: is_string($config['session_login_method'] ?? null) ? $config['session_login_method'] : 'POST',
            sessionUsernameField: is_string($config['session_username_field'] ?? null) ? $config['session_username_field'] : 'email',
            sessionPasswordField: is_string($config['session_password_field'] ?? null) ? $config['session_password_field'] : 'password',
            csrfTokenSource: is_string($config['csrf_token_source'] ?? null) ? $config['csrf_token_source'] : 'cookie',
            csrfTokenKey: is_string($config['csrf_token_key'] ?? null) ? $config['csrf_token_key'] : 'XSRF-TOKEN',
            csrfHeaderName: is_string($config['csrf_header_name'] ?? null) ? $config['csrf_header_name'] : 'X-XSRF-TOKEN',
            csrfBootstrapPath: is_string($config['csrf_bootstrap_path'] ?? null) ? $config['csrf_bootstrap_path'] : '',
            sessionTtl: is_int($config['session_ttl'] ?? null) ? $config['session_ttl'] : (is_numeric($config['session_ttl'] ?? null) ? (int) $config['session_ttl'] : 7200),
            sessionUsername: is_string($config['session_username'] ?? null) ? $config['session_username'] : '',
            sessionPassword: is_string($config['session_password'] ?? null) ? $config['session_password'] : '',
            adminLogin: ($config['admin_login'] ?? false) === true,
            adminAuth: $adminAuth,
            realtime: $realtime,
        );
    }

    /**
     * @return array{
     *     name: string,
     *     tenant_id: string,
     *     base_url: string,
     *     api_key: string,
     *     response_mapping: array<string, bool|string>,
     *     panel: string,
     *     auth_type: string,
     *     auth_header: string,
     *     health_path: string,
     *     token_url: string,
     *     oauth_client_id: string,
     *     oauth_client_secret: string,
     *     oauth_scope: string,
     *     auth_behavior: string,
     *     oauth_username: string,
     *     oauth_password: string,
     *     oauth_refresh_token: string,
     *     session_login_endpoint: string,
     *     session_login_method: string,
     *     session_username_field: string,
     *     session_password_field: string,
     *     csrf_token_source: string,
     *     csrf_token_key: string,
     *     csrf_header_name: string,
     *     csrf_bootstrap_path: string,
     *     session_ttl: int,
     *     session_username: string,
     *     session_password: string,
     *     admin_login: bool,
     *     admin_auth: array{
     *         mode: string,
     *         validate_endpoint: string,
     *         token_url: string,
     *         authorize_url: string,
     *         scopes: list<string>,
     *         redirect_uri: string,
     *     },
     *     realtime: array{
     *         enabled: bool,
     *         webhook_secret: string,
     *         webhook_signature_header: string,
     *         webhook_signature_algo: string,
     *         webhook_event_key: string,
     *         webhook_resource_key: string,
     *         webhook_action_key: string,
     *         webhook_id_key: string,
     *         allowed_events: list<string>,
     *         refresh_debounce_ms: int,
     *         refresh_strategy: string,
     *         sensitive_payload_keys: list<string>,
     *     },
     * }
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'tenant_id' => $this->tenantId,
            'base_url' => $this->baseUrl,
            'api_key' => $this->apiKey,
            'response_mapping' => $this->responseMapping->toArray(),
            'panel' => $this->panel,
            'auth_type' => $this->authType,
            'auth_header' => $this->authHeader,
            'health_path' => $this->healthPath,
            'token_url' => $this->tokenUrl,
            'oauth_client_id' => $this->oauthClientId,
            'oauth_client_secret' => $this->oauthClientSecret,
            'oauth_scope' => $this->oauthScope,
            'auth_behavior' => $this->authBehavior,
            'oauth_username' => $this->oauthUsername,
            'oauth_password' => $this->oauthPassword,
            'oauth_refresh_token' => $this->oauthRefreshToken,
            'session_login_endpoint' => $this->sessionLoginEndpoint,
            'session_login_method' => $this->sessionLoginMethod,
            'session_username_field' => $this->sessionUsernameField,
            'session_password_field' => $this->sessionPasswordField,
            'csrf_token_source' => $this->csrfTokenSource,
            'csrf_token_key' => $this->csrfTokenKey,
            'csrf_header_name' => $this->csrfHeaderName,
            'csrf_bootstrap_path' => $this->csrfBootstrapPath,
            'session_ttl' => $this->sessionTtl,
            'session_username' => $this->sessionUsername,
            'session_password' => $this->sessionPassword,
            'admin_login' => $this->adminLogin,
            'admin_auth' => $this->adminAuth->toArray(),
            'realtime' => $this->realtime->toArray(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function stringKeyedArray(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $result[$key] = $item;
            }
        }

        return $result;
    }
}
