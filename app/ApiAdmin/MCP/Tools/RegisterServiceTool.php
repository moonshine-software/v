<?php

declare(strict_types=1);

namespace App\ApiAdmin\MCP\Tools;

use App\ApiAdmin\MCP\AtomicFileWriter;
use App\ApiAdmin\MCP\ServiceRegistry;
use App\ApiAdmin\Providers\ApiAdminServiceProvider;
use App\ApiAdmin\ServiceConfig\AdminAuthConfig;
use App\ApiAdmin\Tenancy\TenantContext;
use App\ApiAdmin\Tenancy\TenantResolver;
use App\ApiAdmin\Tenancy\TenantRoutes;
use App\ApiAdmin\Tenancy\TenantSecretStore;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * MCP Tool: register_service
 *
 * Registers a new microservice in the admin panel:
 * 1. Creates a ServiceConfig file in the configs directory
 * 2. Creates a panel Layout class
 * 3. Updates the api-admin.php config with the new service
 * 4. Triggers hot-reload
 */
final class RegisterServiceTool implements ToolContract
{
    private const array AUTH_TYPES = ['bearer', 'header', 'query', 'basic', 'oauth2_cc', 'oauth2_password', 'oauth2_refresh_token', 'session', 'none'];

    /**
     * Preset mappings for common API response shapes. The AI agent passes
     * `response_format: "laravel" | "django" | "flat" | "custom"` instead of
     * hand-rolling 6 individual mapping keys. `custom` still honours whatever
     * the caller puts in `response_mapping` (empty defaults).
     *
     * @var array<string, array<string, string>>
     */
    public const array RESPONSE_FORMAT_PRESETS = [
        'laravel' => [
            'data_key' => 'data',
            'total_key' => 'total',
            'per_page_key' => 'per_page',
            'current_page_key' => 'current_page',
            'last_page_key' => 'last_page',
            'primary_key' => 'id',
        ],
        'django' => [
            'data_key' => 'results',
            'total_key' => 'count',
            'per_page_key' => 'page_size',
            'current_page_key' => 'current_page',
            'last_page_key' => 'last_page',
            'primary_key' => 'id',
        ],
        'flat' => [
            // Non-paginated list: response body is a JSON array of items.
            // We leave data_key empty so the caster reads items directly from the root.
            'data_key' => '',
            'total_key' => 'total',
            'per_page_key' => 'per_page',
            'current_page_key' => 'current_page',
            'last_page_key' => 'last_page',
            'primary_key' => 'id',
        ],
        'github' => [
            // RFC 5988 Link header pagination — body is a JSON array of items,
            // navigation lives in `Link:` header, total in `X-Total-Count`.
            // Matches GitHub, GitLab (X-Total), Atlassian shapes; tweak total_header
            // if the upstream uses a different name.
            'data_key' => '',
            'primary_key' => 'id',
            'pagination_type' => 'link_header',
            'total_header' => 'X-Total-Count',
        ],
    ];

    /**
     * MCP tool schema definition.
     *
     * @return array{
     *     name: string,
     *     description: string,
     *     inputSchema: array{
     *         type: string,
     *         properties: array<string, mixed>,
     *         required: list<string>,
     *     },
     * }
     */
    public static function schema(): array
    {
        return [
            'name' => 'register_service',
            'description' => 'Generation changes are serialized and rolled back on failure. Register a new microservice in the admin panel. Creates ServiceConfig, Layout, and panel configuration.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'name' => [
                        'type' => 'string',
                        'description' => 'Unique service identifier (e.g. "blog", "shop")',
                    ],
                    'base_url' => [
                        'type' => 'string',
                        'description' => 'API base URL (e.g. "https://api.blog.example.com/v1")',
                    ],
                    'api_key' => [
                        'type' => 'string',
                        'description' => 'Auth credential that goes into the outgoing request. For auth_type="bearer", this is the token sent as Authorization: Bearer <api_key> (works for Laravel Sanctum PATs, OAuth access tokens, GitHub PATs). For "header" and "query" types, this is the header/query value. Pass empty string (explicitly) when auth_type="none" to clear any previously-stored token. OMITTING the param when re-registering an existing service PRESERVES the currently-stored api_key — this lets the agent re-register just to tweak response_mapping / panel / filter_param_name without having to restate the secret every time. The tool also writes this token to the moonshine-v .env so subsequent runs/containers pick it up without hard-coding secrets in the generated config file.',
                    ],
                    'panel' => [
                        'type' => 'string',
                        'description' => 'Panel identifier for multi-panel routing (e.g. "blog-admin")',
                    ],
                    'title' => [
                        'type' => 'string',
                        'description' => 'Panel display title (e.g. "Blog Admin")',
                    ],
                    'auth_type' => [
                        'type' => 'string',
                        'enum' => self::AUTH_TYPES,
                        'description' => 'How the moonshine-v server authenticates when calling the upstream API. This is server-to-upstream auth only. Do not put panel login modes here: use admin_auth_mode="delegated_token" or "oauth2_authorization_code" for human admin login. "bearer" (default) sends `Authorization: Bearer <api_key>`. "header" sends `<auth_header>: <api_key>` for custom header names. "query" appends `?api_key=<api_key>`. "basic" sends `Authorization: Basic <base64(user:pass)>` — pass the raw "user:pass" as api_key. "oauth2_cc" performs OAuth 2.0 client-credentials grant: fetches + caches an access token from token_url using oauth_client_id / oauth_client_secret (+ optional oauth_scope), sends it as Bearer. "oauth2_password" performs Resource Owner Password Credentials grant: requires oauth_username + oauth_password in addition to client credentials; access tokens are cached, returned refresh tokens are rotated. "oauth2_refresh_token" exchanges a long-lived refresh token (oauth_refresh_token seed) for access tokens, rotating the refresh token whenever the IdP issues a new one. "session" performs cookie + CSRF login against session_login_endpoint with session_username / session_password — captures the session cookie and CSRF token (csrf_token_source = cookie / header / body, key via csrf_token_key) and sends them on every subsequent request through csrf_header_name. All three refresh-capable schemes (oauth2_*, session) auto-retry once on 401/419 with fresh credentials. "none" for open APIs or network-authenticated backends.',
                    ],
                    'auth_header' => [
                        'type' => 'string',
                        'description' => 'Custom auth header name (when auth_type is "header")',
                    ],
                    'admin_login' => [
                        'type' => 'boolean',
                        'description' => 'When true, this service panel uses delegated admin login: the MoonShine login form sends the entered username/password to the upstream auth flow and stores only the resulting token/cookie state in the Laravel session. For auth_type="oauth2_password", register token_url + oauth_client_id + oauth_client_secret but omit oauth_username/oauth_password; the human supplies them at /<panel>/login. For auth_type="session", register session_login_endpoint + csrf_* settings but omit session_username/session_password. The default false keeps the legacy static service credential mode for MCP/background calls.',
                    ],
                    'admin_auth_mode' => [
                        'type' => 'string',
                        'enum' => AdminAuthConfig::MODES,
                        'description' => 'How human admins authenticate to this generated panel. This is admin-to-panel login and is separate from auth_type. "moonshine" (default) keeps the standard MoonShine login. "delegated_token" makes the panel accept a per-user upstream token/PAT and validate it against admin_auth.validate_endpoint. "oauth2_authorization_code" enables per-user OAuth Authorization Code + PKCE login using admin_auth.authorize_url, admin_auth.token_url, optional admin_auth.scopes, and optional admin_auth.redirect_uri.',
                    ],
                    'admin_auth' => [
                        'type' => 'object',
                        'description' => 'Per-panel administrator login metadata. Stored under service config key admin_auth and used only when admin_auth_mode is not "moonshine". Use validate_endpoint for delegated_token; use authorize_url, token_url, scopes, and redirect_uri for oauth2_authorization_code.',
                        'properties' => [
                            'validate_endpoint' => [
                                'type' => 'string',
                                'description' => 'Upstream endpoint used to validate a per-user token/PAT for admin_auth_mode="delegated_token". Example: "/me".',
                            ],
                            'token_url' => [
                                'type' => 'string',
                                'description' => 'OAuth token endpoint used by admin_auth_mode="oauth2_authorization_code" for code exchange and refresh.',
                            ],
                            'authorize_url' => [
                                'type' => 'string',
                                'description' => 'OAuth authorization endpoint used by admin_auth_mode="oauth2_authorization_code".',
                            ],
                            'scopes' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                                'description' => 'OAuth scopes requested for admin_auth_mode="oauth2_authorization_code".',
                            ],
                            'redirect_uri' => [
                                'type' => 'string',
                                'description' => 'OAuth redirect URI for the generated panel callback route.',
                            ],
                        ],
                    ],
                    'token_url' => [
                        'type' => 'string',
                        'description' => 'OAuth 2.0 token endpoint URL (required when auth_type="oauth2_cc"). Example: "https://auth.example.com/oauth/token".',
                    ],
                    'oauth_client_id' => [
                        'type' => 'string',
                        'description' => 'OAuth 2.0 client_id (required when auth_type="oauth2_cc"). Works with confidential client apps registered with the IdP.',
                    ],
                    'oauth_client_secret' => [
                        'type' => 'string',
                        'description' => 'OAuth 2.0 client_secret (required when auth_type="oauth2_cc"). Omit when re-registering to preserve the stored value; pass empty string to clear explicitly.',
                    ],
                    'oauth_scope' => [
                        'type' => 'string',
                        'description' => 'Optional space-separated OAuth 2.0 scopes (e.g. "read write"). Used by oauth2_cc and oauth2_password. Omit when the IdP defaults are sufficient.',
                    ],
                    'oauth_username' => [
                        'type' => 'string',
                        'description' => 'Resource-owner username. Required when auth_type="oauth2_password". Stored in moonshine-v .env as <PREFIX>_OAUTH_USERNAME.',
                    ],
                    'oauth_password' => [
                        'type' => 'string',
                        'description' => 'Resource-owner password. Required when auth_type="oauth2_password". Stored in moonshine-v .env as <PREFIX>_OAUTH_PASSWORD. Omit on re-register to preserve the stored value.',
                    ],
                    'oauth_refresh_token' => [
                        'type' => 'string',
                        'description' => 'Initial refresh token seed for auth_type="oauth2_refresh_token". Required on first registration; subsequent rotations are persisted by OAuth2TokenStore so re-registers can omit this. Stored in .env as <PREFIX>_OAUTH_REFRESH_TOKEN.',
                    ],
                    'session_login_endpoint' => [
                        'type' => 'string',
                        'description' => 'Path (relative to base_url) for the session login POST. Required when auth_type="session". Examples: "/login" (Sanctum SPA), "/api/auth/login", "/users/sign_in".',
                    ],
                    'session_login_method' => [
                        'type' => 'string',
                        'enum' => ['POST', 'PUT'],
                        'description' => 'HTTP method for the session login call. Defaults to POST.',
                    ],
                    'session_username_field' => [
                        'type' => 'string',
                        'description' => 'Form field name for the username/email in the login POST body. Default "email" (Laravel/Sanctum). Use "login" / "user" / etc. when the upstream differs.',
                    ],
                    'session_password_field' => [
                        'type' => 'string',
                        'description' => 'Form field name for the password in the login POST body. Default "password".',
                    ],
                    'session_username' => [
                        'type' => 'string',
                        'description' => 'Resource-owner username for session login. Required when auth_type="session".',
                    ],
                    'session_password' => [
                        'type' => 'string',
                        'description' => 'Resource-owner password for session login. Required when auth_type="session". Stored in moonshine-v .env as <PREFIX>_SESSION_PASSWORD. Omit on re-register to preserve the stored value.',
                    ],
                    'csrf_token_source' => [
                        'type' => 'string',
                        'enum' => ['cookie', 'header', 'body'],
                        'description' => 'Where to harvest the CSRF token from the login response. "cookie" (default, Sanctum SPA — XSRF-TOKEN cookie). "header" (Django-style X-CSRFToken). "body" (Rails-style authenticity_token in JSON body).',
                    ],
                    'csrf_token_key' => [
                        'type' => 'string',
                        'description' => 'Cookie name / header name / body key under which the CSRF token is found in the login response. Default "XSRF-TOKEN" (Sanctum). For Django use "csrftoken"; for Rails set csrf_token_source="body" and csrf_token_key="authenticity_token".',
                    ],
                    'csrf_header_name' => [
                        'type' => 'string',
                        'description' => 'HTTP header used to send the captured CSRF token on subsequent authenticated requests. Default "X-XSRF-TOKEN" (Sanctum/axios convention).',
                    ],
                    'csrf_bootstrap_path' => [
                        'type' => 'string',
                        'description' => 'Optional pre-flight GET that seeds CSRF cookies before the login POST. Sanctum SPA pattern: "/sanctum/csrf-cookie". Leave empty when the upstream embeds the CSRF token directly in the login response.',
                    ],
                    'session_ttl' => [
                        'type' => 'integer',
                        'description' => 'Session-state cache TTL in seconds. Defaults to 7200 (2 h). The cache is dropped immediately on 401/419 regardless of TTL — this only bounds how long a healthy session is reused without re-logging in.',
                    ],
                    'health_path' => [
                        'type' => 'string',
                        'description' => 'Health check target. Accepts three forms: (1) "/health" — appended to base_url; (2) absolute URL like "http://127.0.0.1:8080/up" — used as-is, handy when the health endpoint lives OUTSIDE the API base_url (e.g. base_url is "/api" but Laravel exposes /up at the host root); (3) empty — pings base_url itself.',
                    ],
                    'response_format' => [
                        'type' => 'string',
                        'enum' => ['laravel', 'django', 'flat', 'github', 'custom'],
                        'description' => 'Preset for response envelope. laravel: {data,total,per_page,...}. django: {results,count,page_size}. flat: bare JSON array (no pagination). github: RFC 5988 Link-header pagination with X-Total-Count (GitHub/GitLab/Atlassian; override total_header for GitLab "X-Total"). custom: use response_mapping. Defaults to custom if omitted.',
                    ],
                    'response_mapping' => [
                        'type' => 'object',
                        'description' => 'API response key mapping (used when response_format=custom, or to override specific keys from a preset)',
                        'properties' => [
                            'data_key' => ['type' => 'string'],
                            'total_key' => ['type' => 'string'],
                            'per_page_key' => ['type' => 'string'],
                            'current_page_key' => ['type' => 'string'],
                            'last_page_key' => ['type' => 'string'],
                            'primary_key' => ['type' => 'string'],
                            'filter_format' => [
                                'type' => 'string',
                                'enum' => ['nested', 'flat'],
                                'description' => 'List filter query encoding. "nested" sends ?filter[status]=todo (Laravel / JSON:API, default). "flat" sends ?status=todo (top-level query params — used by CQRS APIs that read filters directly off r.URL.Query()).',
                            ],
                            'supports_sorting' => [
                                'type' => 'boolean',
                                'description' => 'Set false when the upstream list endpoint ignores `sort=...` — the resource then skips emitting the sort query param instead of silently no-op\'ing (default: true).',
                            ],
                            'pagination_type' => [
                                'type' => 'string',
                                'enum' => ['length_aware', 'cursor', 'link_header', 'none'],
                                'description' => 'Paginator shape the upstream uses. "length_aware" (default, Laravel/Django) expects total/per_page/current_page/last_page. "cursor" expects {data:[...], cursor:{next,...}} — the resource emits ?cursor=<token>&per_page=M and builds a next-only paginator. "link_header" expects bare item array + RFC 5988 Link header with rel=first/prev/next/last (GitHub/GitLab/Atlassian); total comes from a header (configure via total_header). "none" disables pagination.',
                            ],
                            'next_cursor_key' => [
                                'type' => 'string',
                                'description' => 'Dot-path in the response body to the cursor token for the next page. Only used when pagination_type="cursor" (default "cursor.next"). A null / missing value signals "end of list".',
                            ],
                            'cursor_query_param' => [
                                'type' => 'string',
                                'description' => 'Query-string param name the upstream expects to receive the cursor (default "cursor"). Only used when pagination_type="cursor".',
                            ],
                            'total_header' => [
                                'type' => 'string',
                                'description' => 'HTTP response header name that carries the total row count when pagination_type="link_header". Default "X-Total-Count" (GitHub). GitLab uses "X-Total". Leave empty to skip total extraction — paginator falls back to simple mode (next/prev only, no page count).',
                            ],
                            'detail_strategy' => [
                                'type' => 'string',
                                'enum' => ['endpoint', 'list'],
                                'description' => 'How the resource fetches a single item for the Detail / edit-Form pages. "endpoint" (default) — GET /resource/{id}. "list" — GET /resource?<key>={id} and take the matching row; use this when the upstream exposes ONLY a list + write endpoints (no per-id read), common for tag/status catalogues edited in-line from the list.',
                            ],
                            'detail_list_key_param' => [
                                'type' => 'string',
                                'description' => 'Query param name the list endpoint reads to narrow down to a single item when detail_strategy="list". Defaults to primary_key. Use when the upstream names the filter differently (e.g. primary_key="id" but list filter is ?id_eq=7).',
                            ],
                            'filter_param_name' => [
                                'type' => 'string',
                                'description' => 'Top-level query key under which list filters are grouped (default "filter"). Use "filters" for Laravel projects that ship with Spatie Query Builder default config (`?filters[status]=todo`). Ignored when filter_format="flat" (flat spreads filter keys to top-level query). If filters from the UI silently yield an unfiltered list, this is the most common cause — run probe_endpoint with detect_filter_for to confirm the shape.',
                            ],
                            'sort_param_name' => [
                                'type' => 'string',
                                'description' => 'Top-level query key for the sort directive (default "sort"). Override for APIs that expect "order_by" / "ordering" / similar — otherwise sort controls silently no-op upstream.',
                            ],
                            'search_param_name' => [
                                'type' => 'string',
                                'description' => 'Top-level query key for the free-text search box (default "search"). Override for APIs that expect "q" (common) or "query" — otherwise the search box silently no-ops upstream.',
                            ],
                            'detail_data_key' => [
                                'type' => 'string',
                                'description' => 'Dot-path inside the SINGLE-ITEM response envelope where the item body lives. Leave empty when list and detail share the same wrapper (`GET /posts/1` → `{"data": {...}}`). Set when detail is deeper / differs from the list — e.g. Laravel JsonResource wrapping the individual record under a custom key like `{"resource": {"data": {...}}}` → `detail_data_key: "resource.data"`. Without this, double-nested envelopes confuse the caster and the form page renders empty (primary_key unresolved). The key supports dot-path for multi-level wrappers.',
                            ],
                        ],
                    ],
                    'realtime' => [
                        'type' => 'object',
                        'description' => 'Optional upstream webhook + browser refresh settings. Configure Laravel broadcasting and Echo separately. Hosted refresh uses private channels authorized by the service panel user and tenant membership. webhook_secret is required for enabled hosted realtime, stored in TenantSecretStore there and <SERVICE>_WEBHOOK_SECRET in .env locally.',
                        'properties' => [
                            'enabled' => [
                                'type' => 'boolean',
                                'description' => 'Enable POST /api-admin/webhooks/{service} ingestion and MoonShine page refresh metadata for this service.',
                            ],
                            'webhook_secret' => [
                                'type' => 'string',
                                'description' => 'HMAC secret for upstream webhook signatures. Required for enabled hosted realtime, optional locally. Omit on re-register to preserve the stored secret; disable hosted realtime before passing an empty string to clear it.',
                            ],
                            'webhook_signature_header' => [
                                'type' => 'string',
                                'description' => 'HTTP header containing the upstream HMAC signature. Default X-ApiAdmin-Signature.',
                            ],
                            'webhook_signature_algo' => [
                                'type' => 'string',
                                'description' => 'hash_hmac algorithm for webhook signatures. Default sha256.',
                            ],
                            'webhook_event_key' => ['type' => 'string', 'description' => 'Payload key for the upstream event/action alias. Default event.'],
                            'webhook_resource_key' => ['type' => 'string', 'description' => 'Payload key for the resource name. Default resource.'],
                            'webhook_action_key' => ['type' => 'string', 'description' => 'Payload key for the mutation action. Default action.'],
                            'webhook_id_key' => ['type' => 'string', 'description' => 'Payload key for the changed item id. Default id.'],
                            'allowed_events' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                                'description' => 'Allowed mutation actions/events. Default created, updated, deleted, upserted.',
                            ],
                            'refresh_debounce_ms' => [
                                'type' => 'integer',
                                'description' => 'Browser refresh debounce in milliseconds. Default 750.',
                            ],
                            'refresh_strategy' => [
                                'type' => 'string',
                                'enum' => ['auto', 'notice', 'disabled'],
                                'description' => 'Browser behavior on matching mutations: auto reload list/detail pages, notice only, or disabled.',
                            ],
                            'sensitive_payload_keys' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                                'description' => 'Payload metadata keys to redact from webhook logs. Default password, token, secret, api_key, authorization.',
                            ],
                        ],
                    ],
                ],
                'required' => ['name', 'base_url'],
            ],
        ];
    }

    /**
     * Execute the register_service tool.
     *
     * @param  array{
     *     name: string,
     *     base_url: string,
     *     api_key?: string,
     *     panel?: string,
     *     title?: string,
     *     auth_type?: string,
     *     auth_header?: string,
     *     admin_login?: bool,
     *     admin_auth_mode?: string,
     *     admin_auth?: array<string, mixed>,
     *     health_path?: string,
     *     token_url?: string,
     *     oauth_client_id?: string,
     *     oauth_client_secret?: string,
     *     oauth_scope?: string,
     *     oauth_username?: string,
     *     oauth_password?: string,
     *     oauth_refresh_token?: string,
     *     session_login_endpoint?: string,
     *     session_login_method?: string,
     *     session_username_field?: string,
     *     session_password_field?: string,
     *     session_username?: string,
     *     session_password?: string,
     *     csrf_token_source?: string,
     *     csrf_token_key?: string,
     *     csrf_header_name?: string,
     *     csrf_bootstrap_path?: string,
     *     session_ttl?: int,
     *     response_format?: string,
     *     response_mapping?: array<string, mixed>,
     *     realtime?: array<string, mixed>,
     * }  $params
     *
     * @return array<string, mixed>
     */
    public function execute(array $params): array
    {
        $name = $params['name'];
        $baseUrl = $params['base_url'];
        foreach (['name' => $name, 'panel' => $params['panel'] ?? $name.'-admin'] as $key => $identifier) {
            if (preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,79}$/D', $identifier) !== 1) {
                return ['success' => false, 'code' => 'VALIDATION', 'error' => "Invalid {$key} identifier."];
            }
        }

        // Preserve the previously-stored api_key when the caller omits it.
        // Re-registering an existing service to tweak response_mapping or
        // panel should NOT blow away the token — that silently broke every
        // downstream HTTP call with 401 until the user re-registered with
        // the key. Agents commonly pass just the fields they intend to
        // change; treating a missing `api_key` as "clear it" surprised the
        // caller. We only fall back when the param is literally absent —
        // an explicit empty string still clears, for auth_type="none".
        $apiKeyProvided = array_key_exists('api_key', $params);
        $apiKey = $apiKeyProvided
            ? $params['api_key']
            : $this->readExistingApiKey($name);
        $panel = $params['panel'] ?? $name.'-admin';
        $title = $params['title'] ?? Str::title($name).' Admin';
        $authType = $params['auth_type'] ?? 'bearer';
        $authHeader = $params['auth_header'] ?? 'Authorization';
        $adminLogin = ($params['admin_login'] ?? false) === true;
        $adminAuthMode = is_string($params['admin_auth_mode'] ?? null)
            ? trim($params['admin_auth_mode'])
            : AdminAuthConfig::MODE_MOONSHINE;
        if (! in_array($adminAuthMode, AdminAuthConfig::MODES, true)) {
            Log::warning('RegisterServiceTool: invalid admin auth mode', [
                'name' => $name,
                'admin_auth_mode' => $adminAuthMode,
            ]);

            return [
                'success' => false,
                'error' => 'admin_auth_mode must be one of: '.implode(', ', AdminAuthConfig::MODES),
                'code' => 'VALIDATION',
                'hint' => 'Use admin_auth_mode="moonshine" for the default panel login, "delegated_token" for per-user token/PAT login metadata, or "oauth2_authorization_code" for Authorization Code + PKCE metadata.',
            ];
        }

        $axisValidation = $this->validateAuthAxes((string) $authType, $adminAuthMode);
        if ($axisValidation !== null) {
            Log::warning('RegisterServiceTool: auth axis validation failed', [
                'name' => $name,
                'auth_type' => $authType,
                'admin_auth_mode' => $adminAuthMode,
                'reason' => $axisValidation['code'],
            ]);

            return $axisValidation;
        }

        $adminAuth = $this->resolveAdminAuthConfig($adminAuthMode, $params['admin_auth'] ?? null);
        $adminAuthValidation = $this->validateAdminAuthParams($adminAuth);
        if ($adminAuthValidation !== null) {
            Log::warning('RegisterServiceTool: admin auth validation failed', [
                'name' => $name,
                'admin_auth_mode' => $adminAuthMode,
                'missing' => $adminAuthValidation,
            ]);

            return [
                'success' => false,
                'error' => 'admin_auth_mode='.$adminAuthMode.' requires: '.implode(', ', $adminAuthValidation),
                'code' => 'VALIDATION',
                'hint' => 'Put panel-login endpoints under admin_auth: delegated_token requires admin_auth.validate_endpoint; oauth2_authorization_code requires admin_auth.authorize_url and admin_auth.token_url. Keep auth_type for server-to-upstream API auth only.',
            ];
        }
        $healthPath = $params['health_path'] ?? '';
        $tokenUrl = is_string($params['token_url'] ?? null) ? $params['token_url'] : '';
        $oauthClientId = is_string($params['oauth_client_id'] ?? null) ? $params['oauth_client_id'] : '';
        // Re-registering without passing oauth_client_secret preserves the stored value (same pattern as api_key).
        $oauthClientSecret = array_key_exists('oauth_client_secret', $params)
            ? $params['oauth_client_secret']
            : $this->readExistingServiceField($name, 'oauth_client_secret');
        $oauthScope = is_string($params['oauth_scope'] ?? null) ? $params['oauth_scope'] : '';

        // OAuth password / refresh-token grant inputs.
        $oauthUsername = $params['oauth_username'] ?? '';
        $oauthPassword = array_key_exists('oauth_password', $params)
            ? $params['oauth_password']
            : $this->readExistingEnvSecret($name, '_OAUTH_PASSWORD', 'oauth_password');
        $tenant = $this->currentTenantContext();
        $previousOAuthRefreshToken = $tenant->isLocal
            ? $this->readExistingEnvSecret($name, '_OAUTH_REFRESH_TOKEN', 'oauth_refresh_token')
            : new TenantSecretStore()->get($tenant->id, $name, 'oauth_refresh_token');
        $previousOAuthStore = is_array(config("api-admin.services.{$name}"))
            ? ApiAdminServiceProvider::resolveService($name)->oauthTokenStore()
            : null;
        $oauthRefreshToken = array_key_exists('oauth_refresh_token', $params)
            ? $params['oauth_refresh_token']
            : $previousOAuthRefreshToken;

        // Session inputs.
        $sessionLoginEndpoint = $params['session_login_endpoint'] ?? '';
        $sessionLoginMethod = $params['session_login_method'] ?? 'POST';
        $sessionUsernameField = $params['session_username_field'] ?? 'email';
        $sessionPasswordField = $params['session_password_field'] ?? 'password';
        $sessionUsername = $params['session_username'] ?? '';
        $sessionPassword = array_key_exists('session_password', $params)
            ? $params['session_password']
            : $this->readExistingEnvSecret($name, '_SESSION_PASSWORD', 'session_password');
        $csrfTokenSource = $params['csrf_token_source'] ?? 'cookie';
        $csrfTokenKey = $params['csrf_token_key'] ?? 'XSRF-TOKEN';
        $csrfHeaderName = $params['csrf_header_name'] ?? 'X-XSRF-TOKEN';
        $csrfBootstrapPath = $params['csrf_bootstrap_path'] ?? '';
        $sessionTtl = $params['session_ttl'] ?? 7200;

        if ($adminLogin) {
            $oauthUsername = '';
            $oauthPassword = '';
            $sessionUsername = '';
            $sessionPassword = '';
        }

        $validation = $this->validateAuthParams(
            $authType,
            tokenUrl: $tokenUrl,
            oauthClientId: $oauthClientId,
            oauthClientSecret: $oauthClientSecret,
            oauthUsername: $oauthUsername,
            oauthPassword: $oauthPassword,
            oauthRefreshToken: $oauthRefreshToken,
            sessionLoginEndpoint: $sessionLoginEndpoint,
            sessionUsername: $sessionUsername,
            sessionPassword: $sessionPassword,
            adminLogin: $adminLogin,
        );

        if ($validation !== null) {
            Log::warning('RegisterServiceTool: validation failed', [
                'name' => $name,
                'auth_type' => $authType,
                'missing' => $validation,
            ]);

            return [
                'success' => false,
                'error' => 'auth_type='.$authType.' requires: '.implode(', ', $validation),
                'code' => 'VALIDATION',
                'hint' => 'Pass the missing field(s) on this register_service call. Secrets stored on a previous registration are preserved when omitted; explicit empty string clears them.',
            ];
        }

        $responseFormat = $params['response_format'] ?? 'custom';
        $responseMapping = $this->resolveResponseMapping(
            $responseFormat,
            $params['response_mapping'] ?? [],
        );
        $realtime = $this->resolveRealtimeConfig(
            $name,
            $params['realtime'] ?? null,
        );

        if (! $this->currentTenantContext()->isLocal && $realtime['enabled'] && $realtime['webhook_secret'] === '') {
            Log::warning('Registration rejected without webhook secret', ['service' => $name]);

            return [
                'success' => false,
                'code' => 'VALIDATION',
                'error' => 'Hosted realtime requires a non-empty webhook_secret.',
                'hint' => 'Pass realtime.webhook_secret or disable realtime. Existing secrets are preserved when omitted.',
            ];
        }

        Log::info('RegisterServiceTool: executing', [
            'name' => $name,
            'kind' => ServiceRegistry::SERVICE_KIND_REST,
            'base_url' => $baseUrl,
            'panel' => $panel,
            'api_key_set' => $apiKey !== '',
            'auth_type' => $authType,
            'admin_auth_mode' => $adminAuth['mode'],
            'realtime_enabled' => $realtime['enabled'],
        ]);

        $files = [];

        // Step 1: Create service config file (baked literals as env fallbacks)
        $configFile = $this->createServiceConfig($name, $baseUrl, $panel, $authType, $authHeader, $healthPath, $responseMapping, $realtime, $apiKey, $tokenUrl, $oauthClientId, $oauthClientSecret, $oauthScope, $adminLogin, $adminAuth);
        $files[] = $configFile;

        // Step 2: Create layout class
        $layoutFile = $this->createLayout($name);
        $files[] = $layoutFile;

        // Step 3: Update api-admin.php config (also persists session + new oauth params).
        $this->updateConfig(
            $name,
            $baseUrl,
            $panel,
            $authType,
            $authHeader,
            $healthPath,
            $responseMapping,
            $tokenUrl,
            $oauthClientId,
            $oauthClientSecret,
            $oauthScope,
            oauthUsername: $oauthUsername,
            oauthRefreshToken: $oauthRefreshToken,
            sessionLoginEndpoint: $sessionLoginEndpoint,
            sessionLoginMethod: $sessionLoginMethod,
            sessionUsernameField: $sessionUsernameField,
            sessionPasswordField: $sessionPasswordField,
            sessionUsername: $sessionUsername,
            csrfTokenSource: $csrfTokenSource,
            csrfTokenKey: $csrfTokenKey,
            csrfHeaderName: $csrfHeaderName,
            csrfBootstrapPath: $csrfBootstrapPath,
            sessionTtl: $sessionTtl,
            adminLogin: $adminLogin,
            adminAuth: $adminAuth,
            realtime: $realtime,
        );

        // Step 4: Register panel in moonshine.php for multi-panel routing
        $this->registerPanel($name, $panel, $title);

        // Step 5: Write/update .env so env() reads fresh values even when
        // someone set stale ones earlier. The agent can't touch .env from
        // outside moonshine-v (scope guard), so the tool does it here. We
        // update ONLY the keys owned by this service — never anything else.
        // Sensitive secrets (oauth password / refresh token, session password)
        // ride the same lane: they live in .env, not in the baked config file.
        $tenant = $this->currentTenantContext();
        $secretValues = array_filter([
            'api_key' => $apiKey,
            'oauth_client_secret' => $oauthClientSecret,
            'oauth_password' => $adminLogin ? null : $oauthPassword,
            'oauth_refresh_token' => $oauthRefreshToken,
            'session_password' => $adminLogin ? null : $sessionPassword,
            'webhook_secret' => $realtime['webhook_secret'],
        ], static fn (?string $value): bool => $value !== null && $value !== '');

        if ($tenant->isLocal) {
            $this->syncDotEnv(
                $name,
                $baseUrl,
                $apiKey,
                $healthPath,
                secrets: array_filter([
                    '_OAUTH_PASSWORD' => $adminLogin ? null : $oauthPassword,
                    '_OAUTH_REFRESH_TOKEN' => $oauthRefreshToken,
                    '_SESSION_PASSWORD' => $adminLogin ? null : $sessionPassword,
                    '_WEBHOOK_SECRET' => $realtime['webhook_secret'],
                ], static fn (?string $value): bool => $value !== null),
            );
        } else {
            if (is_array($params['realtime'] ?? null) && array_key_exists('webhook_secret', $params['realtime'])) {
                $secretValues['webhook_secret'] = $realtime['webhook_secret'];
            }
            (new TenantSecretStore)->putMany($tenant->id, $name, $secretValues);
        }

        // Step 6: Refresh the running process's view of env + rebind the
        // ServiceConfig singleton. Without this, a long-lived MCP worker
        // keeps the pre-register snapshot of env + the container binding
        // frozen at boot time — next httpClient() call still uses the
        // stale base_url / api_key and returns 401 / hits the wrong host.
        if ($tenant->isLocal) {
            $this->refreshInProcessEnv($name, $baseUrl, $apiKey, $healthPath, secrets: [
                ...($adminLogin ? [] : ['_OAUTH_PASSWORD' => $oauthPassword]),
                '_OAUTH_REFRESH_TOKEN' => $oauthRefreshToken,
                ...($adminLogin ? [] : ['_SESSION_PASSWORD' => $sessionPassword]),
                '_WEBHOOK_SECRET' => $realtime['webhook_secret'],
            ]);
        }

        ApiAdminServiceProvider::rebindService($name);

        if (array_key_exists('oauth_refresh_token', $params) && $oauthRefreshToken !== $previousOAuthRefreshToken) {
            ($previousOAuthStore ?? ApiAdminServiceProvider::resolveService($name)->oauthTokenStore())->forgetLegacyRefreshToken();
            Log::debug('Cleared legacy refresh token after seed replacement', ['service' => $name]);
        }

        Log::info('RegisterServiceTool: completed', [
            'name' => $name,
            'files_created' => $files,
        ]);

        return [
            'success' => true,
            'service' => $name,
            'panel' => $panel,
            'files' => $files,
            'message' => "Service '{$name}' registered. Panel: '{$panel}'. Hot-reload was applied by the tool — the new service is live.",
            'guardrails' => [
                'Do NOT run shell commands (artisan, composer, make) against moonshine-v — the tool handles autoload + cache refresh itself.',
                'Next step: probe_endpoint(service, path, detect_filter_for?) to discover the API shape before create_resource.',
            ],
        ];
    }

    /**
     * @return array{
     *     mode: string,
     *     validate_endpoint: string,
     *     token_url: string,
     *     authorize_url: string,
     *     scopes: list<string>,
     *     redirect_uri: string,
     * }
     */
    private function resolveAdminAuthConfig(string $mode, mixed $input): array
    {
        $provided = is_array($input) ? $input : [];
        $string = static fn (string $key): string => is_string($provided[$key] ?? null)
            ? trim($provided[$key])
            : '';

        $scopes = $this->stringList($provided['scopes'] ?? null, []);

        return [
            'mode' => $mode,
            'validate_endpoint' => $string('validate_endpoint'),
            'token_url' => $string('token_url'),
            'authorize_url' => $string('authorize_url'),
            'scopes' => $scopes,
            'redirect_uri' => $string('redirect_uri'),
        ];
    }

    /**
     * @return array{success: false, error: string, code: string, hint: string}|null
     */
    private function validateAuthAxes(string $authType, string $adminAuthMode): ?array
    {
        $looksLikeAdminMode = in_array($authType, ['delegated_token', 'oauth2_authorization_code'], true)
            || str_starts_with($authType, 'delegated_');

        if ($adminAuthMode === AdminAuthConfig::MODE_MOONSHINE && $looksLikeAdminMode) {
            return [
                'success' => false,
                'error' => 'auth_type='.$authType.' is an admin_auth_mode value, not an upstream API auth scheme.',
                'code' => 'VALIDATION',
                'hint' => 'Use auth_type for server-to-upstream API auth (bearer, oauth2_cc, session, none, etc.). Use admin_auth_mode="'.$authType.'" with admin_auth endpoints for panel login.',
            ];
        }

        if (! in_array($authType, self::AUTH_TYPES, true)) {
            return [
                'success' => false,
                'error' => 'auth_type must be one of: '.implode(', ', self::AUTH_TYPES),
                'code' => 'VALIDATION',
                'hint' => 'Use auth_type for server-to-upstream API auth. Use admin_auth_mode for per-panel human login.',
            ];
        }

        return null;
    }

    /**
     * @param array{
     *     mode: string,
     *     validate_endpoint: string,
     *     token_url: string,
     *     authorize_url: string,
     *     scopes: list<string>,
     *     redirect_uri: string,
     * } $adminAuth
     *
     * @return list<string>|null
     */
    private function validateAdminAuthParams(array $adminAuth): ?array
    {
        $missing = [];

        if ($adminAuth['mode'] === AdminAuthConfig::MODE_DELEGATED_TOKEN && $adminAuth['validate_endpoint'] === '') {
            $missing[] = 'admin_auth.validate_endpoint';
        }

        if ($adminAuth['mode'] === AdminAuthConfig::MODE_OAUTH2_AUTHORIZATION_CODE) {
            if ($adminAuth['authorize_url'] === '') {
                $missing[] = 'admin_auth.authorize_url';
            }

            if ($adminAuth['token_url'] === '') {
                $missing[] = 'admin_auth.token_url';
            }
        }

        return $missing === [] ? null : $missing;
    }

    /**
     * Merge response_format preset with explicit response_mapping overrides.
     * Unknown presets collapse to 'custom' silently. Explicit overrides always win.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function resolveResponseMapping(string $format, array $overrides): array
    {
        $preset = self::RESPONSE_FORMAT_PRESETS[$format] ?? [];

        return array_merge($preset, $overrides);
    }

    /**
     * @return array{
     *     enabled: bool,
     *     webhook_secret: string,
     *     webhook_signature_header: string,
     *     webhook_signature_algo: string,
     *     webhook_event_key: string,
     *     webhook_resource_key: string,
     *     webhook_action_key: string,
     *     webhook_id_key: string,
     *     allowed_events: list<string>,
     *     refresh_debounce_ms: int,
     *     refresh_strategy: string,
     *     sensitive_payload_keys: list<string>,
     * }
     */
    private function resolveRealtimeConfig(string $name, mixed $input): array
    {
        $existing = $input === null ? $this->readExistingRealtimeConfig($name) : [];
        $provided = is_array($input) ? $input : [];

        $value = static fn (string $key, mixed $default): mixed => array_key_exists($key, $provided)
            ? $provided[$key]
            : ($existing[$key] ?? $default);
        $string = static fn (string $key, string $default): string => is_string($value($key, $default))
            ? trim($value($key, $default))
            : $default;

        $tenant = $this->currentTenantContext();
        $webhookSecret = array_key_exists('webhook_secret', $provided)
            ? (is_string($provided['webhook_secret']) ? $provided['webhook_secret'] : '')
            : ($tenant->isLocal
                ? $this->readExistingEnvSecret($name, '_WEBHOOK_SECRET', 'realtime.webhook_secret')
                : app(TenantSecretStore::class)->get($tenant->id, $name, 'webhook_secret'));

        $strategy = $string('refresh_strategy', 'auto');
        if (! in_array($strategy, ['auto', 'notice', 'disabled'], true)) {
            $strategy = 'auto';
        }

        $algo = $string('webhook_signature_algo', 'sha256')
            |> strtolower(...);
        if (! in_array($algo, hash_hmac_algos(), true)) {
            $algo = 'sha256';
        }

        return [
            'enabled' => $value('enabled', false) === true,
            'webhook_secret' => $webhookSecret,
            'webhook_signature_header' => $string('webhook_signature_header', 'X-ApiAdmin-Signature') !== '' ? $string('webhook_signature_header', 'X-ApiAdmin-Signature') : 'X-ApiAdmin-Signature',
            'webhook_signature_algo' => $algo,
            'webhook_event_key' => $string('webhook_event_key', 'event') !== '' ? $string('webhook_event_key', 'event') : 'event',
            'webhook_resource_key' => $string('webhook_resource_key', 'resource') !== '' ? $string('webhook_resource_key', 'resource') : 'resource',
            'webhook_action_key' => $string('webhook_action_key', 'action') !== '' ? $string('webhook_action_key', 'action') : 'action',
            'webhook_id_key' => $string('webhook_id_key', 'id') !== '' ? $string('webhook_id_key', 'id') : 'id',
            'allowed_events' => $this->stringList($value('allowed_events', ['created', 'updated', 'deleted', 'upserted']), ['created', 'updated', 'deleted', 'upserted']),
            'refresh_debounce_ms' => $this->positiveInt($value('refresh_debounce_ms', 750), 750),
            'refresh_strategy' => $strategy,
            'sensitive_payload_keys' => $this->stringList($value('sensitive_payload_keys', ['password', 'token', 'secret', 'api_key', 'authorization']), ['password', 'token', 'secret', 'api_key', 'authorization']),
        ];
    }

    /**
     * Create a service config PHP file.
     *
     * @param array<string, mixed> $responseMapping
     * @param array<string, mixed> $realtime
     * @param array{}|array{
     *     mode: string,
     *     validate_endpoint: string,
     *     token_url: string,
     *     authorize_url: string,
     *     scopes: list<string>,
     *     redirect_uri: string,
     * } $adminAuth
     */
    private function createServiceConfig(
        string $name,
        string $baseUrl,
        string $panel,
        string $authType,
        string $authHeader,
        string $healthPath,
        array $responseMapping,
        array $realtime,
        string $apiKey = '',
        string $tokenUrl = '',
        string $oauthClientId = '',
        string $oauthClientSecret = '',
        string $oauthScope = '',
        bool $adminLogin = false,
        array $adminAuth = [],
    ): string {
        $configsPath = (new ServiceRegistry)->configsPath();
        $envPrefix = Str::upper(Str::snake(str_replace('-', '_', $name)));
        $hostedMode = ! $this->currentTenantContext()->isLocal;

        $stubPath = dirname(__DIR__, 4).'/stubs/api-admin/service-config.stub';
        $stub = File::get($stubPath);

        $mappingValue = static fn (string $key, string $default): string => is_string($responseMapping[$key] ?? null) ? $responseMapping[$key] : $default;

        $esc = static fn (string $s): string => addcslashes($s, "'\\");

        $content = strtr($stub, array_combine([
            '{{ serviceName }}',
            '{{ envPrefix }}',
            '{{ baseUrl }}',
            '{{ apiKey }}',
            '{{ panel }}',
            '{{ authType }}',
            '{{ authHeader }}',
            '{{ healthPath }}',
            '{{ tokenUrl }}',
            '{{ oauthClientId }}',
            '{{ oauthClientSecret }}',
            '{{ oauthScope }}',
            '{{ adminLogin }}',
            '{{ adminAuth }}',
            '{{ dataKey }}',
            '{{ totalKey }}',
            '{{ perPageKey }}',
            '{{ currentPageKey }}',
            '{{ lastPageKey }}',
            '{{ primaryKey }}',
            '{{ realtimeEnabled }}',
            '{{ webhookSignatureHeader }}',
            '{{ webhookSignatureAlgo }}',
            '{{ webhookEventKey }}',
            '{{ webhookResourceKey }}',
            '{{ webhookActionKey }}',
            '{{ webhookIdKey }}',
            '{{ allowedEvents }}',
            '{{ refreshDebounceMs }}',
            '{{ refreshStrategy }}',
            '{{ sensitivePayloadKeys }}',
        ], [
            $esc($name),
            $esc($envPrefix),
            $esc($baseUrl),
            // Escape single quotes in the stub literal so tokens with
            // apostrophes (unlikely but possible) don't break PHP syntax.
            $hostedMode ? '' : $esc($apiKey),
            $esc($panel),
            $esc($authType),
            $esc($authHeader),
            $esc($healthPath),
            $esc($tokenUrl),
            $esc($oauthClientId),
            $hostedMode ? '' : $esc($oauthClientSecret),
            $esc($oauthScope),
            $adminLogin ? 'true' : 'false',
            $this->exportPhpArray($adminAuth !== [] ? $adminAuth : (new AdminAuthConfig)->toArray(), 1),
            $esc($mappingValue('data_key', 'data')),
            $esc($mappingValue('total_key', 'total')),
            $esc($mappingValue('per_page_key', 'per_page')),
            $esc($mappingValue('current_page_key', 'current_page')),
            $esc($mappingValue('last_page_key', 'last_page')),
            $esc($mappingValue('primary_key', 'id')),
            ($realtime['enabled'] ?? false) === true ? 'true' : 'false',
            $esc(is_string($realtime['webhook_signature_header'] ?? null) ? $realtime['webhook_signature_header'] : 'X-ApiAdmin-Signature'),
            $esc(is_string($realtime['webhook_signature_algo'] ?? null) ? $realtime['webhook_signature_algo'] : 'sha256'),
            $esc(is_string($realtime['webhook_event_key'] ?? null) ? $realtime['webhook_event_key'] : 'event'),
            $esc(is_string($realtime['webhook_resource_key'] ?? null) ? $realtime['webhook_resource_key'] : 'resource'),
            $esc(is_string($realtime['webhook_action_key'] ?? null) ? $realtime['webhook_action_key'] : 'action'),
            $esc(is_string($realtime['webhook_id_key'] ?? null) ? $realtime['webhook_id_key'] : 'id'),
            $this->exportPhpArray(is_array($realtime['allowed_events'] ?? null) ? $realtime['allowed_events'] : ['created', 'updated', 'deleted', 'upserted'], 2),
            (string) (is_int($realtime['refresh_debounce_ms'] ?? null) ? $realtime['refresh_debounce_ms'] : 750),
            $esc(is_string($realtime['refresh_strategy'] ?? null) ? $realtime['refresh_strategy'] : 'auto'),
            $this->exportPhpArray(is_array($realtime['sensitive_payload_keys'] ?? null) ? $realtime['sensitive_payload_keys'] : ['password', 'token', 'secret', 'api_key', 'authorization'], 2),
        ]));

        $filePath = $configsPath.'/'.Str::studly($name).'Config.php';

        AtomicFileWriter::ensureDirectoryExists(dirname($filePath));
        AtomicFileWriter::put($filePath, $content);

        Log::debug('RegisterServiceTool: created service config', [
            'file' => $filePath,
        ]);

        return $filePath;
    }

    /**
     * Create a panel layout class.
     */
    private function createLayout(string $name): string
    {
        $layoutsPath = (new ServiceRegistry)->layoutsPath();
        $className = Str::studly($name).'Layout';
        $namespace = $this->layoutNamespace();

        $stubPath = dirname(__DIR__, 4).'/stubs/api-admin/layout.stub';
        $stub = File::get($stubPath);

        $content = str_replace([
            '{{ namespace }}',
            '{{ className }}',
            '{{ logo }}',
            '{{ logoSmall }}',
        ], [
            $namespace,
            $className,
            '/vendor/moonshine/logo.svg',
            '/vendor/moonshine/logo-small.svg',
        ], $stub);

        $filePath = $layoutsPath.'/'.$className.'.php';

        AtomicFileWriter::ensureDirectoryExists(dirname($filePath));
        AtomicFileWriter::put($filePath, $content);

        Log::debug('RegisterServiceTool: created layout', [
            'file' => $filePath,
            'class' => $namespace.'\\'.$className,
        ]);

        return $filePath;
    }

    /**
     * Update the api-admin.php config to include the new service.
     *
     * Sensitive secrets (oauth_password, oauth_refresh_token, session_password)
     * are NOT written here — they live in .env. The config file holds only
     * the non-secret descriptors plus empty stubs that env overrides fill in
     * at runtime via ApiAdminServiceProvider::applyEnvOverrides.
     *
     * @param array<string, mixed> $responseMapping
     * @param array<string, mixed> $realtime
     * @param array{}|array{
     *     mode: string,
     *     validate_endpoint: string,
     *     token_url: string,
     *     authorize_url: string,
     *     scopes: list<string>,
     *     redirect_uri: string,
     * } $adminAuth
     */
    private function updateConfig(
        string $name,
        string $baseUrl,
        string $panel,
        string $authType,
        string $authHeader,
        string $healthPath,
        array $responseMapping,
        string $tokenUrl = '',
        string $oauthClientId = '',
        string $oauthClientSecret = '',
        string $oauthScope = '',
        string $oauthUsername = '',
        string $oauthRefreshToken = '',
        string $sessionLoginEndpoint = '',
        string $sessionLoginMethod = 'POST',
        string $sessionUsernameField = 'email',
        string $sessionPasswordField = 'password',
        string $sessionUsername = '',
        string $csrfTokenSource = 'cookie',
        string $csrfTokenKey = 'XSRF-TOKEN',
        string $csrfHeaderName = 'X-XSRF-TOKEN',
        string $csrfBootstrapPath = '',
        int $sessionTtl = 7200,
        bool $adminLogin = false,
        array $adminAuth = [],
        array $realtime = [],
    ): void {
        $configPath = $this->generatedServicesPath();
        $tenant = $this->currentTenantContext();

        $loaded = File::exists($configPath) ? include $configPath : ['services' => []];
        /** @var array{services: array<string, mixed>} $config */
        $config = is_array($loaded) && isset($loaded['services']) && is_array($loaded['services'])
            ? $loaded
            : ['services' => []];

        $config['services'][$name] = [
            'name' => $name,
            'tenant_id' => $tenant->id,
            'kind' => ServiceRegistry::SERVICE_KIND_REST,
            'base_url' => $baseUrl,
            'api_key' => '', // Set via env variable
            'panel' => $panel,
            'auth_type' => $authType,
            'auth_header' => $authHeader,
            'health_path' => $healthPath,
            'token_url' => $tokenUrl,
            'oauth_client_id' => $oauthClientId,
            'oauth_client_secret' => $tenant->isLocal ? $oauthClientSecret : '',
            'oauth_scope' => $oauthScope,
            'oauth_username' => $oauthUsername,
            'oauth_password' => '', // sensitive — env-resolved at runtime
            'oauth_refresh_token' => $oauthRefreshToken !== '' ? '' : '', // sensitive — env-resolved at runtime
            'session_login_endpoint' => $sessionLoginEndpoint,
            'session_login_method' => $sessionLoginMethod,
            'session_username_field' => $sessionUsernameField,
            'session_password_field' => $sessionPasswordField,
            'session_username' => $sessionUsername,
            'session_password' => '', // sensitive — env-resolved at runtime
            'csrf_token_source' => $csrfTokenSource,
            'csrf_token_key' => $csrfTokenKey,
            'csrf_header_name' => $csrfHeaderName,
            'csrf_bootstrap_path' => $csrfBootstrapPath,
            'session_ttl' => $sessionTtl,
            'admin_login' => $adminLogin,
            'admin_auth' => $adminAuth !== [] ? $adminAuth : (new AdminAuthConfig)->toArray(),
            'realtime' => [
                'enabled' => ($realtime['enabled'] ?? false) === true,
                'webhook_secret' => '', // sensitive — env-resolved at runtime
                'webhook_signature_header' => is_string($realtime['webhook_signature_header'] ?? null) ? $realtime['webhook_signature_header'] : 'X-ApiAdmin-Signature',
                'webhook_signature_algo' => is_string($realtime['webhook_signature_algo'] ?? null) ? $realtime['webhook_signature_algo'] : 'sha256',
                'webhook_event_key' => is_string($realtime['webhook_event_key'] ?? null) ? $realtime['webhook_event_key'] : 'event',
                'webhook_resource_key' => is_string($realtime['webhook_resource_key'] ?? null) ? $realtime['webhook_resource_key'] : 'resource',
                'webhook_action_key' => is_string($realtime['webhook_action_key'] ?? null) ? $realtime['webhook_action_key'] : 'action',
                'webhook_id_key' => is_string($realtime['webhook_id_key'] ?? null) ? $realtime['webhook_id_key'] : 'id',
                'allowed_events' => is_array($realtime['allowed_events'] ?? null) ? $realtime['allowed_events'] : ['created', 'updated', 'deleted', 'upserted'],
                'refresh_debounce_ms' => is_int($realtime['refresh_debounce_ms'] ?? null) ? $realtime['refresh_debounce_ms'] : 750,
                'refresh_strategy' => is_string($realtime['refresh_strategy'] ?? null) ? $realtime['refresh_strategy'] : 'auto',
                'sensitive_payload_keys' => is_array($realtime['sensitive_payload_keys'] ?? null) ? $realtime['sensitive_payload_keys'] : ['password', 'token', 'secret', 'api_key', 'authorization'],
            ],
            'response_mapping' => array_merge([
                'data_key' => 'data',
                'total_key' => 'total',
                'per_page_key' => 'per_page',
                'current_page_key' => 'current_page',
                'last_page_key' => 'last_page',
                'primary_key' => 'id',
            ], $responseMapping),
        ];

        $content = "<?php\n\ndeclare(strict_types=1);\n\nreturn ".$this->exportPhpArray($config).";\n";

        AtomicFileWriter::ensureDirectoryExists(dirname($configPath));
        AtomicFileWriter::put($configPath, $content);
        config(['api-admin.services' => array_replace(
            is_array(config('api-admin.services', [])) ? config('api-admin.services', []) : [],
            $config['services'],
        )]);

        Log::debug('RegisterServiceTool: updated config', [
            'config_path' => $configPath,
            'service' => $name,
            'kind' => ServiceRegistry::SERVICE_KIND_REST,
            'tenant_id' => $this->currentTenantContext()->id,
        ]);
    }

    private function currentTenantContext(): TenantContext
    {
        if (! app()->bound(TenantResolver::class)) {
            return TenantContext::local();
        }

        $resolver = app()->get(TenantResolver::class);
        $tenant = $resolver->resolve();

        if (! $tenant instanceof TenantContext) {
            throw new RuntimeException('Missing tenant context in hosted mode.');
        }

        return $tenant;
    }

    private function generatedServicesPath(): string
    {
        $path = config('api-admin.generated_services_path', storage_path('api-admin/generated/services.php'));

        return is_string($path) && $path !== '' ? $path : storage_path('api-admin/generated/services.php');
    }

    private function generatedPanelsPath(): string
    {
        $path = config('api-admin.generated_panels_path', storage_path('api-admin/generated/panels.php'));

        return is_string($path) && $path !== '' ? $path : storage_path('api-admin/generated/panels.php');
    }

    /**
     * @param array<mixed> $value
     */
    private function exportPhpArray(array $value, int $indent = 0): string
    {
        if ($value === []) {
            return '[]';
        }

        $pad = str_repeat('    ', $indent);
        $childPad = str_repeat('    ', $indent + 1);
        $isList = array_is_list($value);
        $lines = ['['];

        foreach ($value as $key => $item) {
            $prefix = $isList ? '' : $this->exportPhpValue($key).' => ';
            $exported = is_array($item)
                ? $this->exportPhpArray($item, $indent + 1)
                : $this->exportPhpValue($item);

            $lines[] = $childPad.$prefix.$exported.',';
        }

        $lines[] = $pad.']';

        return implode("\n", $lines);
    }

    private function exportPhpValue(mixed $value): string
    {
        return var_export($value, true);
    }

    /**
     * Required-field combinations per auth_type. Returns null when valid,
     * else returns the list of missing-field names so the caller can
     * surface them in the VALIDATION error.
     *
     * @return list<string>|null
     */
    private function validateAuthParams(
        string $authType,
        string $tokenUrl,
        string $oauthClientId,
        string $oauthClientSecret,
        string $oauthUsername,
        string $oauthPassword,
        string $oauthRefreshToken,
        string $sessionLoginEndpoint,
        string $sessionUsername,
        string $sessionPassword,
        bool $adminLogin,
    ): ?array {
        $missing = [];

        if (in_array($authType, ['oauth2_cc', 'oauth2_password', 'oauth2_refresh_token'], true)) {
            if ($tokenUrl === '') {
                $missing[] = 'token_url';
            }

            if ($oauthClientId === '') {
                $missing[] = 'oauth_client_id';
            }

            if ($oauthClientSecret === '') {
                $missing[] = 'oauth_client_secret';
            }
        }

        if ($authType === 'oauth2_password' && ! $adminLogin) {
            if ($oauthUsername === '') {
                $missing[] = 'oauth_username';
            }

            if ($oauthPassword === '') {
                $missing[] = 'oauth_password';
            }
        }

        if ($authType === 'oauth2_refresh_token' && $oauthRefreshToken === '') {
            $missing[] = 'oauth_refresh_token';
        }

        if ($authType === 'session') {
            if ($sessionLoginEndpoint === '') {
                $missing[] = 'session_login_endpoint';
            }

            if (! $adminLogin && $sessionUsername === '') {
                $missing[] = 'session_username';
            }

            if (! $adminLogin && $sessionPassword === '') {
                $missing[] = 'session_password';
            }
        }

        return $missing === [] ? null : $missing;
    }

    /**
     * Read a previously-stored sensitive value, preferring .env over the
     * config file (config holds an empty stub for sensitive keys; env is
     * the runtime source of truth). Returns '' if neither is set.
     */
    private function readExistingEnvSecret(string $name, string $envSuffix, string $configKey): string
    {
        $envPrefix = Str::upper(Str::snake(str_replace('-', '_', $name)));
        $envKey = $envPrefix.$envSuffix;

        $envPath = base_path('.env');
        if (File::exists($envPath)) {
            $content = File::get($envPath);
            if (preg_match('/^'.preg_quote($envKey, '/').'=(.*)$/m', $content, $m) === 1) {
                $value = trim($m[1]);
                if (strlen($value) >= 2) {
                    $first = $value[0];
                    $last = $value[strlen($value) - 1];
                    if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                        $value = substr($value, 1, -1);
                    }
                }
                if ($value !== '') {
                    return $value;
                }
            }
        }

        // Config file fallback (legacy / tests). Sensitive keys are stubbed
        // out by updateConfig so this almost always returns '' unless the
        // service was registered before the secret-via-env split landed.
        return $this->readExistingServiceField($name, $configKey);
    }

    /**
     * Read the previously-stored api_key for this service. Source order:
     *   1. `<SERVICE>_API_KEY` in moonshine-v's .env (canonical — env()
     *      wins at runtime so ops can rotate the token without
     *      regenerating config).
     *   2. Baked literal in `config/api-admin.php` (fallback used by
     *      ServiceConfig::fromArray when env is missing).
     *
     * Returns an empty string if neither source yields a value — same
     * behavior as before the preserve-api_key fix, so a brand-new
     * service still ends up with no token until the caller passes one.
     */
    private function readExistingApiKey(string $name): string
    {
        $envPrefix = Str::upper(Str::snake(str_replace('-', '_', $name)));
        $envKey = "{$envPrefix}_API_KEY";

        $envPath = base_path('.env');
        if (File::exists($envPath)) {
            $content = File::get($envPath);
            if (preg_match('/^'.preg_quote($envKey, '/').'=(.*)$/m', $content, $m) === 1) {
                $value = trim($m[1]);
                // Strip paired quotes that Laravel's env parser unwraps.
                if (strlen($value) >= 2) {
                    $first = $value[0];
                    $last = $value[strlen($value) - 1];
                    if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                        $value = substr($value, 1, -1);
                    }
                }
                if ($value !== '') {
                    return $value;
                }
            }
        }

        $configPath = config_path('api-admin.php');
        if (File::exists($configPath)) {
            /** @var array<string, mixed> $config */
            $config = include $configPath;
            $baked = data_get($config, "services.{$name}.api_key");
            if (is_string($baked) && $baked !== '') {
                return $baked;
            }
        }

        return '';
    }

    /**
     * Read an arbitrary stored scalar field from an existing service config entry.
     * Used to preserve OAuth secrets (and similar) across re-registrations when
     * the caller omits the param. Returns '' if the service or field is absent.
     */
    private function readExistingServiceField(string $name, string $field): string
    {
        $configPath = config_path('api-admin.php');
        if (! File::exists($configPath)) {
            return '';
        }

        /** @var array<string, mixed> $config */
        $config = include $configPath;
        $value = data_get($config, "services.{$name}.{$field}");

        return is_string($value) ? $value : '';
    }

    /**
     * @return array<string, mixed>
     */
    private function readExistingRealtimeConfig(string $name): array
    {
        $configPath = config_path('api-admin.php');
        if (! File::exists($configPath)) {
            return [];
        }

        /** @var array<string, mixed> $config */
        $config = include $configPath;
        $value = data_get($config, "services.{$name}.realtime");

        if (! is_array($value)) {
            return [];
        }

        $realtime = [];
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $realtime[$key] = $item;
            }
        }

        return $realtime;
    }

    /**
     * @param list<string> $default
     *
     * @return list<string>
     */
    private function stringList(mixed $value, array $default): array
    {
        if (! is_array($value)) {
            return $default;
        }

        $items = [];
        foreach ($value as $item) {
            if (! is_string($item)) {
                continue;
            }

            $item = trim($item);
            if ($item !== '') {
                $items[] = $item;
            }
        }

        return $items === [] ? $default : array_values(array_unique($items));
    }

    private function positiveInt(mixed $value, int $default): int
    {
        $int = is_int($value) ? $value : (is_numeric($value) ? (int) $value : $default);

        return $int > 0 ? $int : $default;
    }

    /**
     * Write the service's env vars to moonshine-v's .env so env() reads
     * the fresh values the agent passed in. The tool edits .env on the
     * agent's behalf — the agent itself is scope-guarded from touching
     * files under the working copy. Only keys we own are touched; other
     * env vars are preserved byte-for-byte.
     *
     * @param array<string, string> $secrets Suffix → value map for
     *                                       sensitive keys keyed by
     *                                       SUFFIX (e.g. `_OAUTH_PASSWORD`).
     *                                       Each suffix is concatenated with
     *                                       the upper-snake service prefix.
     *                                       Empty values still write the line
     *                                       (so re-registering with empty
     *                                       params explicitly clears).
     */
    private function syncDotEnv(string $name, string $baseUrl, string $apiKey, string $healthPath, array $secrets = []): void
    {
        $envPath = base_path('.env');

        if (! File::exists($envPath)) {
            Log::info('RegisterServiceTool: .env not found, skipping env sync', [
                'expected_at' => $envPath,
            ]);

            return;
        }

        $envPrefix = Str::upper(Str::snake(str_replace('-', '_', $name)));
        $keys = [
            "{$envPrefix}_API_URL" => $baseUrl,
            "{$envPrefix}_API_KEY" => $apiKey,
            "{$envPrefix}_HEALTH_PATH" => $healthPath,
        ];

        foreach ($secrets as $suffix => $value) {
            $keys[$envPrefix.$suffix] = $value;
        }

        $content = File::get($envPath);

        foreach ($keys as $key => $value) {
            $content = $this->upsertEnvLine($content, $key, $value);
        }

        AtomicFileWriter::put($envPath, $content);

        Log::info('RegisterServiceTool: synced .env', [
            'keys' => array_keys($keys),
            'api_key_set' => $apiKey !== '',
        ]);
    }

    /**
     * Replace (or append) a single KEY=VALUE line in a .env file. Values
     * are wrapped in double quotes when they contain whitespace / special
     * characters; empty values yield a bare KEY= line.
     *
     * `|` is included in the quoting trigger because some shells and
     * older parsers interpret it (pipe) when the .env file is sourced —
     * Laravel Sanctum personal access tokens have the form `id|hash`,
     * so we can't rely on `|` being treated as plain text everywhere.
     */
    private function upsertEnvLine(string $content, string $key, string $value): string
    {
        $needsQuoting = $value === '' || preg_match('/[\s#"\'$\\\\|]/', $value) === 1;
        $escaped = $needsQuoting
            ? '"'.addcslashes($value, '"\\').'"'
            : $value;

        $newLine = "{$key}={$escaped}";
        $pattern = '/^'.preg_quote($key, '/').'=.*$/m';

        if (preg_match($pattern, $content)) {
            return preg_replace_callback($pattern, static fn (): string => $newLine, $content) ?? $content;
        }

        // Append with a single newline separator.
        return rtrim($content, "\n")."\n".$newLine."\n";
    }

    /**
     * Push the service's env vars into the running process's environment
     * superglobals + putenv, so `env()` resolves them without waiting for
     * the next worker restart. Laravel loads .env once at boot — without
     * this, the freshly written .env has no effect on the current MCP
     * process.
     *
     * @param array<string, string> $secrets Suffix → value map (same shape
     *                                       as syncDotEnv accepts).
     */
    private function refreshInProcessEnv(string $name, string $baseUrl, string $apiKey, string $healthPath, array $secrets = []): void
    {
        $envPrefix = Str::upper(Str::snake(str_replace('-', '_', $name)));

        $vars = [
            "{$envPrefix}_API_URL" => $baseUrl,
            "{$envPrefix}_API_KEY" => $apiKey,
            "{$envPrefix}_HEALTH_PATH" => $healthPath,
        ];

        foreach ($secrets as $suffix => $value) {
            $vars[$envPrefix.$suffix] = $value;
        }

        foreach ($vars as $key => $value) {
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
            putenv("{$key}={$value}");
        }
    }

    /**
     * Register a panel entry in config/moonshine.php for multi-panel routing.
     */
    private function registerPanel(string $name, string $panel, string $title): void
    {
        $configPath = $this->generatedPanelsPath();
        $layoutClass = $this->layoutNamespace().'\\'.Str::studly($name).'Layout';
        $prefix = TenantRoutes::scopedPrefix($panel);

        $loaded = File::exists($configPath) ? include $configPath : [];
        /** @var array<string, array<string, mixed>> $panels */
        $panels = is_array($loaded) ? $loaded : [];
        $panels[$panel] = [
            'prefix' => $prefix,
            'title' => $title,
            'layout' => $layoutClass,
        ];

        $content = "<?php\n\ndeclare(strict_types=1);\n\nreturn ".$this->exportPhpArray($panels).";\n";

        AtomicFileWriter::ensureDirectoryExists(dirname($configPath));
        AtomicFileWriter::put($configPath, $content);
        $currentPanels = config('moonshine.panels', []);
        config(['moonshine.panels' => array_replace(
            is_array($currentPanels) ? $currentPanels : [],
            $panels,
        )]);

        Log::debug('RegisterServiceTool: registered panel in moonshine.php', [
            'panel' => $panel,
            'prefix' => $prefix,
        ]);
    }

    private function layoutNamespace(): string
    {
        $base = config('api-admin.layouts_path', app_path('MoonShine/ApiAdmin/Layouts'));
        $base = is_string($base) ? $base : app_path('MoonShine/ApiAdmin/Layouts');
        $relative = substr((new ServiceRegistry)->layoutsPath(), strlen($base));

        return 'App\\MoonShine\\ApiAdmin\\Layouts'.str_replace(['/', '\\'], '\\', $relative);
    }
}
