<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Unit;

use App\ApiAdmin\Auth\DelegatedAuthSession;
use App\ApiAdmin\Auth\SessionState;
use App\ApiAdmin\ServiceConfig\ResponseMapping;
use App\ApiAdmin\ServiceConfig\ServiceConfig;
use App\ApiAdmin\Tenancy\TenantContext;
use Illuminate\Http\Client\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;
use RuntimeException;

class ServiceConfigTest extends TestCase
{
    public function test_creates_from_array(): void
    {
        $config = ServiceConfig::fromArray([
            'name' => 'blog',
            'base_url' => 'https://api.blog.test/v1',
            'api_key' => 'test-key-123',
            'panel' => 'blog-admin',
            'auth_type' => 'bearer',
        ]);

        $this->assertEquals('blog', $config->name);
        $this->assertEquals('https://api.blog.test/v1', $config->baseUrl);
        $this->assertEquals('test-key-123', $config->apiKey);
        $this->assertEquals('blog-admin', $config->panel);
        $this->assertSame(TenantContext::LOCAL_ID, $config->tenantId);
    }

    public function test_tenant_id_roundtrip(): void
    {
        $config = ServiceConfig::fromArray([
            'name' => 'blog',
            'tenant_id' => 'tenant-1',
            'base_url' => 'https://api.blog.test/v1',
            'api_key' => 'test-key-123',
        ]);

        $this->assertSame('tenant-1', $config->tenantId);
        $this->assertSame('tenant-1', $config->toArray()['tenant_id']);
    }

    public function test_bearer_auth_headers(): void
    {
        $config = new ServiceConfig(
            name: 'test',
            baseUrl: 'https://api.test',
            apiKey: 'my-token',
            responseMapping: new ResponseMapping,
            authType: 'bearer',
        );

        $headers = $config->authHeaders();

        $this->assertEquals(['Authorization' => 'Bearer my-token'], $headers);
    }

    public function test_custom_header_auth(): void
    {
        $config = new ServiceConfig(
            name: 'test',
            baseUrl: 'https://api.test',
            apiKey: 'api-key-value',
            responseMapping: new ResponseMapping,
            authType: 'header',
            authHeader: 'X-Api-Key',
        );

        $headers = $config->authHeaders();

        $this->assertEquals(['X-Api-Key' => 'api-key-value'], $headers);
    }

    public function test_query_auth(): void
    {
        $config = new ServiceConfig(
            name: 'test',
            baseUrl: 'https://api.test',
            apiKey: 'query-key',
            responseMapping: new ResponseMapping,
            authType: 'query',
        );

        $this->assertEmpty($config->authHeaders());
        $this->assertEquals(['api_key' => 'query-key'], $config->authQuery());
    }

    public function test_delegated_oauth_headers_use_session_token(): void
    {
        $request = \Illuminate\Http\Request::create('/oauth-admin/resource/oauth-task-resource/task-index-page');
        $request->setLaravelSession(new Store('test', new ArraySessionHandler(300)));
        app()->instance('request', $request);

        DelegatedAuthSession::putOAuth($request, 'oauth', [
            'access_token' => 'delegated-token',
            'refresh_token' => 'delegated-refresh',
            'expires_at' => time() + 300,
        ]);

        $config = new ServiceConfig(
            name: 'oauth',
            baseUrl: 'https://api.test',
            apiKey: '',
            responseMapping: new ResponseMapping,
            authType: 'oauth2_password',
            adminLogin: true,
        );

        $this->assertSame(['Authorization' => 'Bearer delegated-token'], $config->authHeaders());
    }

    public function test_delegated_session_headers_use_session_state(): void
    {
        $request = \Illuminate\Http\Request::create('/spa-admin/resource/spa-post-resource/post-index-page');
        $request->setLaravelSession(new Store('test', new ArraySessionHandler(300)));
        app()->instance('request', $request);

        DelegatedAuthSession::putSessionState($request, 'spa', new SessionState(
            cookies: ['sanctum_session' => 'abc', 'XSRF-TOKEN' => 'csrf-token'],
            csrfToken: 'csrf-token',
            expiresAt: time() + 300,
        ));

        $config = new ServiceConfig(
            name: 'spa',
            baseUrl: 'https://api.test',
            apiKey: '',
            responseMapping: new ResponseMapping,
            authType: 'session',
            csrfHeaderName: 'X-XSRF-TOKEN',
            adminLogin: true,
        );

        $this->assertSame([
            'Cookie' => 'sanctum_session=abc; XSRF-TOKEN=csrf-token',
            'X-XSRF-TOKEN' => 'csrf-token',
        ], $config->authHeaders());
    }

    public function test_to_array_roundtrip(): void
    {
        $config = ServiceConfig::fromArray([
            'name' => 'shop',
            'base_url' => 'https://api.shop.test',
            'api_key' => 'key',
            'panel' => 'shop-admin',
            'auth_type' => 'bearer',
            'response_mapping' => [
                'data_key' => 'results',
                'total_key' => 'count',
            ],
        ]);

        $array = $config->toArray();

        $this->assertEquals('shop', $array['name']);
        $this->assertEquals('results', $array['response_mapping']['data_key']);
        $this->assertEquals('count', $array['response_mapping']['total_key']);
    }

    public function test_admin_auth_config_from_array_roundtrip(): void
    {
        $config = ServiceConfig::fromArray([
            'name' => 'shop',
            'base_url' => 'https://api.shop.test',
            'api_key' => 'key',
            'admin_auth' => [
                'mode' => 'oauth2_authorization_code',
                'token_url' => 'https://idp.test/oauth/token',
                'authorize_url' => 'https://idp.test/oauth/authorize',
                'scopes' => ['openid', 'profile', 'profile'],
                'redirect_uri' => 'https://admin.test/shop-admin/oauth/callback',
            ],
        ]);

        $this->assertSame('oauth2_authorization_code', $config->adminAuth->mode);
        $this->assertSame('https://idp.test/oauth/token', $config->adminAuth->tokenUrl);
        $this->assertSame('https://idp.test/oauth/authorize', $config->adminAuth->authorizeUrl);
        $this->assertSame(['openid', 'profile'], $config->adminAuth->scopes);
        $this->assertSame('https://admin.test/shop-admin/oauth/callback', $config->adminAuth->redirectUri);

        $array = $config->toArray();
        $this->assertSame('oauth2_authorization_code', $array['admin_auth']['mode']);
        $this->assertSame(['openid', 'profile'], $array['admin_auth']['scopes']);
    }

    public function test_admin_auth_mode_legacy_flat_key_is_supported(): void
    {
        $config = ServiceConfig::fromArray([
            'name' => 'shop',
            'base_url' => 'https://api.shop.test',
            'api_key' => 'key',
            'admin_auth_mode' => 'delegated_token',
        ]);

        $this->assertSame('delegated_token', $config->adminAuth->mode);
        $this->assertSame('delegated_token', $config->toArray()['admin_auth']['mode']);
    }

    public function test_health_path_defaults_to_empty(): void
    {
        $config = ServiceConfig::fromArray([
            'name' => 'blog',
            'base_url' => 'https://api.blog.test/v1',
            'api_key' => 'k',
        ]);

        $this->assertSame('', $config->healthPath);
        $this->assertSame('', $config->toArray()['health_path']);
    }

    public function test_health_path_from_array_roundtrip(): void
    {
        $config = ServiceConfig::fromArray([
            'name' => 'blog',
            'base_url' => 'https://api.blog.test/v1',
            'api_key' => 'k',
            'health_path' => '/health',
        ]);

        $this->assertSame('/health', $config->healthPath);
        $this->assertSame('/health', $config->toArray()['health_path']);
    }

    public function test_realtime_config_defaults_to_disabled(): void
    {
        $config = ServiceConfig::fromArray([
            'name' => 'blog',
            'base_url' => 'https://api.blog.test/v1',
            'api_key' => 'k',
        ]);

        $this->assertFalse($config->realtime->enabled);
        $this->assertSame('', $config->realtime->webhookSecret);
        $this->assertSame('X-ApiAdmin-Signature', $config->realtime->webhookSignatureHeader);
        $this->assertSame(['created', 'updated', 'deleted', 'upserted'], $config->realtime->allowedEvents);
        $this->assertSame(750, $config->realtime->refreshDebounceMs);
        $this->assertSame('auto', $config->realtime->refreshStrategy);
    }

    public function test_realtime_config_from_array_roundtrip(): void
    {
        $config = ServiceConfig::fromArray([
            'name' => 'blog',
            'base_url' => 'https://api.blog.test/v1',
            'api_key' => 'k',
            'realtime' => [
                'enabled' => true,
                'webhook_secret' => 'super-secret',
                'webhook_signature_header' => 'X-Hub-Signature-256',
                'webhook_signature_algo' => 'sha512',
                'webhook_event_key' => 'type',
                'webhook_resource_key' => 'model',
                'webhook_action_key' => 'verb',
                'webhook_id_key' => 'uuid',
                'allowed_events' => ['created', 'updated', 'created', ''],
                'refresh_debounce_ms' => 1200,
                'refresh_strategy' => 'notice',
                'sensitive_payload_keys' => ['password', 'access_token'],
            ],
        ]);

        $this->assertTrue($config->realtime->enabled);
        $this->assertSame('super-secret', $config->realtime->webhookSecret);
        $this->assertSame('X-Hub-Signature-256', $config->realtime->webhookSignatureHeader);
        $this->assertSame('sha512', $config->realtime->webhookSignatureAlgo);
        $this->assertSame('type', $config->realtime->webhookEventKey);
        $this->assertSame('model', $config->realtime->webhookResourceKey);
        $this->assertSame('verb', $config->realtime->webhookActionKey);
        $this->assertSame('uuid', $config->realtime->webhookIdKey);
        $this->assertSame(['created', 'updated'], $config->realtime->allowedEvents);
        $this->assertSame(1200, $config->realtime->refreshDebounceMs);
        $this->assertSame('notice', $config->realtime->refreshStrategy);
        $this->assertSame(['password', 'access_token'], $config->realtime->sensitivePayloadKeys);

        $array = $config->toArray();
        $this->assertTrue($array['realtime']['enabled']);
        $this->assertSame('super-secret', $array['realtime']['webhook_secret']);
        $this->assertSame(['created', 'updated'], $array['realtime']['allowed_events']);
    }

    public function test_invalid_realtime_config_falls_back_to_safe_defaults(): void
    {
        $config = ServiceConfig::fromArray([
            'name' => 'blog',
            'base_url' => 'https://api.blog.test/v1',
            'api_key' => 'k',
            'realtime' => [
                'enabled' => true,
                'webhook_signature_algo' => 'not-real',
                'webhook_signature_header' => '',
                'allowed_events' => [42],
                'refresh_debounce_ms' => -1,
                'refresh_strategy' => 'reload-now',
                'sensitive_payload_keys' => [],
            ],
        ]);

        $this->assertSame('sha256', $config->realtime->webhookSignatureAlgo);
        $this->assertSame('X-ApiAdmin-Signature', $config->realtime->webhookSignatureHeader);
        $this->assertSame(['created', 'updated', 'deleted', 'upserted'], $config->realtime->allowedEvents);
        $this->assertSame(750, $config->realtime->refreshDebounceMs);
        $this->assertSame('auto', $config->realtime->refreshStrategy);
        $this->assertSame(['password', 'token', 'secret', 'api_key', 'authorization'], $config->realtime->sensitivePayloadKeys);
    }

    public function test_filter_format_defaults_to_nested(): void
    {
        $mapping = ResponseMapping::fromArray([]);

        $this->assertSame('nested', $mapping->filterFormat);
        $this->assertTrue($mapping->supportsSorting);
    }

    public function test_filter_format_from_array_roundtrip(): void
    {
        $mapping = ResponseMapping::fromArray([
            'filter_format' => 'flat',
            'supports_sorting' => false,
        ]);

        $this->assertSame('flat', $mapping->filterFormat);
        $this->assertFalse($mapping->supportsSorting);

        $array = $mapping->toArray();
        $this->assertSame('flat', $array['filter_format']);
        $this->assertFalse($array['supports_sorting']);
    }

    public function test_invalid_filter_format_falls_back_to_nested(): void
    {
        $mapping = ResponseMapping::fromArray([
            'filter_format' => 'xml-style',
        ]);

        $this->assertSame('nested', $mapping->filterFormat);
    }

    public function test_filter_sort_search_param_names_default_to_canonical(): void
    {
        $mapping = ResponseMapping::fromArray([]);

        $this->assertSame('filter', $mapping->filterParamName);
        $this->assertSame('sort', $mapping->sortParamName);
        $this->assertSame('search', $mapping->searchParamName);
    }

    public function test_filter_sort_search_param_names_roundtrip(): void
    {
        // Classic Laravel + Spatie Query Builder config: plural `filters`
        // bucket with plain `sort` and free-text `q` search.
        $mapping = ResponseMapping::fromArray([
            'filter_param_name' => 'filters',
            'sort_param_name' => 'order_by',
            'search_param_name' => 'q',
        ]);

        $this->assertSame('filters', $mapping->filterParamName);
        $this->assertSame('order_by', $mapping->sortParamName);
        $this->assertSame('q', $mapping->searchParamName);

        $array = $mapping->toArray();
        $this->assertSame('filters', $array['filter_param_name']);
        $this->assertSame('order_by', $array['sort_param_name']);
        $this->assertSame('q', $array['search_param_name']);
    }

    public function test_empty_filter_param_name_falls_back_to_default(): void
    {
        // Empty / non-string value must not leak as "" query key — that
        // would produce `?=status_eq=foo` and silently no-op upstream.
        $mapping = ResponseMapping::fromArray([
            'filter_param_name' => '',
        ]);

        $this->assertSame('filter', $mapping->filterParamName);
    }

    public function test_detail_data_key_defaults_to_empty(): void
    {
        $mapping = ResponseMapping::fromArray([]);

        $this->assertSame('', $mapping->detailDataKey);
    }

    public function test_detail_data_key_roundtrip(): void
    {
        // Double-nested shape: double-envelope `{"resource": {"data": {...}}}`.
        // The dot-path lets the caster walk through the outer wrapper in
        // one hop without needing a separate fallback heuristic.
        $mapping = ResponseMapping::fromArray([
            'detail_data_key' => 'resource.data',
        ]);

        $this->assertSame('resource.data', $mapping->detailDataKey);
        $this->assertSame('resource.data', $mapping->toArray()['detail_data_key']);
    }

    public function test_cursor_pagination_fields_roundtrip(): void
    {
        $mapping = ResponseMapping::fromArray([
            'pagination_type' => 'cursor',
            'next_cursor_key' => 'cursor.next',
            'cursor_query_param' => 'cursor',
        ]);

        $this->assertSame('cursor', $mapping->paginationType);
        $this->assertSame('cursor.next', $mapping->nextCursorKey);
        $this->assertSame('cursor', $mapping->cursorQueryParam);

        $array = $mapping->toArray();
        $this->assertSame('cursor', $array['pagination_type']);
    }

    public function test_invalid_pagination_type_falls_back_to_length_aware(): void
    {
        $mapping = ResponseMapping::fromArray([
            'pagination_type' => 'keyset',
        ]);

        $this->assertSame('length_aware', $mapping->paginationType);
    }

    public function test_link_header_pagination_roundtrip_with_total_header(): void
    {
        $mapping = ResponseMapping::fromArray([
            'pagination_type' => 'link_header',
            'total_header' => 'X-Total',
        ]);

        $this->assertSame('link_header', $mapping->paginationType);
        $this->assertSame('X-Total', $mapping->totalHeader);

        $array = $mapping->toArray();
        $this->assertSame('link_header', $array['pagination_type']);
        $this->assertSame('X-Total', $array['total_header']);
    }

    public function test_total_header_defaults_to_x_total_count(): void
    {
        $mapping = ResponseMapping::fromArray([]);

        $this->assertSame('X-Total-Count', $mapping->totalHeader);
    }

    public function test_basic_auth_encodes_credentials(): void
    {
        $config = new ServiceConfig(
            name: 'legacy',
            baseUrl: 'https://api.legacy.test',
            apiKey: 'alice:super-secret',
            responseMapping: new ResponseMapping,
            authType: 'basic',
        );

        $headers = $config->authHeaders();

        $this->assertSame(
            ['Authorization' => 'Basic '.base64_encode('alice:super-secret')],
            $headers,
        );
    }

    public function test_oauth2_cc_fetches_and_caches_access_token(): void
    {
        Cache::flush();
        Http::fake([
            'https://idp.test/oauth/token' => Http::response([
                'access_token' => 'iDp-access-42',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
            ]),
        ]);

        $config = new ServiceConfig(
            name: 'shop',
            baseUrl: 'https://api.shop.test',
            apiKey: '',
            responseMapping: new ResponseMapping,
            authType: 'oauth2_cc',
            tokenUrl: 'https://idp.test/oauth/token',
            oauthClientId: 'client-abc',
            oauthClientSecret: 'secret-def',
            oauthScope: 'read write',
        );

        $headers = $config->authHeaders();
        $this->assertSame(['Authorization' => 'Bearer iDp-access-42'], $headers);

        // Second call must read from cache — no additional HTTP request.
        $config->authHeaders();
        Http::assertSentCount(1);

        Http::assertSent(static function (Request $request): bool {
            parse_str($request->body(), $body);

            return $request->url() === 'https://idp.test/oauth/token'
                && ($body['grant_type'] ?? null) === 'client_credentials'
                && ($body['client_id'] ?? null) === 'client-abc'
                && ($body['client_secret'] ?? null) === 'secret-def'
                && ($body['scope'] ?? null) === 'read write';
        });
    }

    public function test_oauth2_cc_invalidate_forces_refresh_on_next_call(): void
    {
        Cache::flush();
        Http::fake([
            'https://idp.test/oauth/token' => Http::sequence()
                ->push(['access_token' => 'token-1', 'expires_in' => 3600])
                ->push(['access_token' => 'token-2', 'expires_in' => 3600]),
        ]);

        $config = new ServiceConfig(
            name: 'shop',
            baseUrl: 'https://api.shop.test',
            apiKey: '',
            responseMapping: new ResponseMapping,
            authType: 'oauth2_cc',
            tokenUrl: 'https://idp.test/oauth/token',
            oauthClientId: 'client-abc',
            oauthClientSecret: 'secret-def',
        );

        $this->assertSame(['Authorization' => 'Bearer token-1'], $config->authHeaders());

        $config->invalidateOAuthToken();

        $this->assertSame(['Authorization' => 'Bearer token-2'], $config->authHeaders());
    }

    public function test_oauth2_cc_missing_config_throws(): void
    {
        $config = new ServiceConfig(
            name: 'broken',
            baseUrl: 'https://api.broken.test',
            apiKey: '',
            responseMapping: new ResponseMapping,
            authType: 'oauth2_cc',
            // token_url / client_id / secret deliberately blank
        );

        $this->expectException(RuntimeException::class);
        $config->authHeaders();
    }
}
