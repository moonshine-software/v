<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Unit\Auth;

use App\ApiAdmin\Auth\OAuth2TokenStore;
use App\ApiAdmin\Tenancy\TenantKeyBuilder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as AuthenticatableUser;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use RuntimeException;

class OAuth2TokenStoreTest extends TestCase
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
        Auth::forgetGuards();
    }

    public function test_client_credentials_grant_caches_access_token_with_ttl_minus_30s(): void
    {
        Http::fake([
            'https://idp.test/oauth/token' => Http::response([
                'access_token' => 'cc-token-xyz',
                'expires_in' => 3600,
            ]),
        ]);

        $store = new OAuth2TokenStore(
            serviceName: 'svc',
            tokenUrl: 'https://idp.test/oauth/token',
            clientId: 'cid',
            clientSecret: 'csec',
            scope: 'read',
        );

        $token = $store->getAccessToken(OAuth2TokenStore::GRANT_CLIENT_CREDENTIALS);

        $this->assertSame('cc-token-xyz', $token);
        // Second call hits cache (no extra HTTP).
        $this->assertSame('cc-token-xyz', $store->getAccessToken(OAuth2TokenStore::GRANT_CLIENT_CREDENTIALS));
        Http::assertSentCount(1);
    }

    public function test_hosted_tenant_tokens_do_not_share_cache_keys(): void
    {
        Http::fake([
            'https://idp.test/oauth/token' => Http::sequence()
                ->push(['access_token' => 'tenant-a-token', 'expires_in' => 3600])
                ->push(['access_token' => 'tenant-b-token', 'expires_in' => 3600]),
        ]);

        $tenantA = new OAuth2TokenStore(
            serviceName: 'svc',
            tokenUrl: 'https://idp.test/oauth/token',
            clientId: 'cid',
            clientSecret: 'csec',
            tenantId: 'tenant-a',
        );
        $tenantB = new OAuth2TokenStore(
            serviceName: 'svc',
            tokenUrl: 'https://idp.test/oauth/token',
            clientId: 'cid',
            clientSecret: 'csec',
            tenantId: 'tenant-b',
        );

        $this->assertSame('tenant-a-token', $tenantA->getAccessToken(OAuth2TokenStore::GRANT_CLIENT_CREDENTIALS));
        $this->assertSame('tenant-b-token', $tenantB->getAccessToken(OAuth2TokenStore::GRANT_CLIENT_CREDENTIALS));
        $this->assertSame('tenant-a-token', $tenantA->getAccessToken(OAuth2TokenStore::GRANT_CLIENT_CREDENTIALS));

        Http::assertSentCount(2);
        $this->assertTrue(Cache::has(TenantKeyBuilder::cacheKey(
            'tenant-a',
            'oauth2',
            'svc',
            'access',
            OAuth2TokenStore::GRANT_CLIENT_CREDENTIALS,
            md5('cid||https://idp.test/oauth/token'),
        )));
        $this->assertTrue(Cache::has(TenantKeyBuilder::cacheKey(
            'tenant-b',
            'oauth2',
            'svc',
            'access',
            OAuth2TokenStore::GRANT_CLIENT_CREDENTIALS,
            md5('cid||https://idp.test/oauth/token'),
        )));
    }

    public function test_authenticated_admins_get_independent_oauth_cache_slots(): void
    {
        Http::fake([
            'https://idp.test/oauth/token' => Http::sequence()
                ->push(['access_token' => 'user-101-initial', 'expires_in' => 3600])
                ->push(['access_token' => 'user-202-initial', 'expires_in' => 3600])
                ->push(['access_token' => 'user-101-refreshed', 'expires_in' => 3600]),
        ]);

        $store = new OAuth2TokenStore(
            serviceName: 'svc',
            tokenUrl: 'https://idp.test/oauth/token',
            clientId: 'cid',
            clientSecret: 'csec',
            username: 'u',
            password: 'p',
        );

        $this->actingAsMoonshineUser(101);
        $this->assertSame('user-101-initial', $store->getAccessToken(OAuth2TokenStore::GRANT_PASSWORD));

        $this->actingAsMoonshineUser(202);
        $this->assertSame('user-202-initial', $store->getAccessToken(OAuth2TokenStore::GRANT_PASSWORD));

        $this->actingAsMoonshineUser(101);
        $this->assertSame('user-101-refreshed', $store->refresh(OAuth2TokenStore::GRANT_PASSWORD));

        $this->actingAsMoonshineUser(202);
        $this->assertSame('user-202-initial', $store->getAccessToken(OAuth2TokenStore::GRANT_PASSWORD));

        Http::assertSentCount(3);
        $this->assertTrue(Cache::has(TenantKeyBuilder::cacheKey(
            'local',
            'oauth2',
            'svc',
            'user',
            '101',
            'access',
            OAuth2TokenStore::GRANT_PASSWORD,
            md5('cid||https://idp.test/oauth/token'),
        )));
        $this->assertTrue(Cache::has(TenantKeyBuilder::cacheKey(
            'local',
            'oauth2',
            'svc',
            'user',
            '202',
            'access',
            OAuth2TokenStore::GRANT_PASSWORD,
            md5('cid||https://idp.test/oauth/token'),
        )));
    }

    public function test_password_grant_sends_username_password_and_caches_separately(): void
    {
        Http::fake([
            'https://idp.test/oauth/token' => Http::response([
                'access_token' => 'pwd-token',
                'expires_in' => 7200,
                'refresh_token' => 'pwd-refresh',
            ]),
        ]);

        $store = new OAuth2TokenStore(
            serviceName: 'svc',
            tokenUrl: 'https://idp.test/oauth/token',
            clientId: 'cid',
            clientSecret: 'csec',
            username: 'alice@example.com',
            password: 's3cret',
        );

        $token = $store->getAccessToken(OAuth2TokenStore::GRANT_PASSWORD);

        $this->assertSame('pwd-token', $token);

        Http::assertSent(function (Request $request): bool {
            $body = (string) $request->body();

            return str_contains($body, 'grant_type=password')
                && str_contains($body, 'username=alice%40example.com')
                && str_contains($body, 'password=s3cret');
        });
    }

    public function test_password_grant_persists_refresh_token_for_later_rotation(): void
    {
        Http::fake([
            'https://idp.test/oauth/token' => Http::sequence()
                ->push(['access_token' => 'a1', 'expires_in' => 60, 'refresh_token' => 'rotation-seed'])
                ->push(['access_token' => 'a2', 'expires_in' => 60, 'refresh_token' => 'rotation-2']),
        ]);

        $store = new OAuth2TokenStore(
            serviceName: 'svc',
            tokenUrl: 'https://idp.test/oauth/token',
            clientId: 'cid',
            clientSecret: 'csec',
            username: 'u',
            password: 'p',
        );

        $store->getAccessToken(OAuth2TokenStore::GRANT_PASSWORD);

        $refreshStore = new OAuth2TokenStore(
            serviceName: 'svc',
            tokenUrl: 'https://idp.test/oauth/token',
            clientId: 'cid',
            clientSecret: 'csec',
        );

        $token = $refreshStore->getAccessToken(OAuth2TokenStore::GRANT_REFRESH_TOKEN);

        $this->assertSame('a2', $token);

        Http::assertSent(function (Request $request): bool {
            $body = (string) $request->body();

            return str_contains($body, 'grant_type=refresh_token')
                && str_contains($body, 'refresh_token=rotation-seed');
        });
    }

    public function test_refresh_token_grant_uses_seeded_token_when_cache_empty(): void
    {
        Http::fake([
            'https://idp.test/oauth/token' => Http::response([
                'access_token' => 'r-access',
                'expires_in' => 1800,
            ]),
        ]);

        $store = new OAuth2TokenStore(
            serviceName: 'svc',
            tokenUrl: 'https://idp.test/oauth/token',
            clientId: 'cid',
            clientSecret: 'csec',
            seedRefreshToken: 'seed-rt-9',
        );

        $store->getAccessToken(OAuth2TokenStore::GRANT_REFRESH_TOKEN);

        Http::assertSent(function (Request $request): bool {
            $body = (string) $request->body();

            return str_contains($body, 'grant_type=refresh_token')
                && str_contains($body, 'refresh_token=seed-rt-9');
        });
    }

    public function test_refresh_token_grant_keeps_old_token_when_idp_returns_none(): void
    {
        Http::fake([
            'https://idp.test/oauth/token' => Http::sequence()
                ->push(['access_token' => 'a-1', 'expires_in' => 60, 'refresh_token' => 'rt-1'])
                ->push(['access_token' => 'a-2', 'expires_in' => 60]) // no rotation
                ->push(['access_token' => 'a-3', 'expires_in' => 60]),
        ]);

        $store = new OAuth2TokenStore(
            serviceName: 'svc',
            tokenUrl: 'https://idp.test/oauth/token',
            clientId: 'cid',
            clientSecret: 'csec',
            username: 'u',
            password: 'p',
            seedRefreshToken: 'rt-1',
        );

        $store->getAccessToken(OAuth2TokenStore::GRANT_PASSWORD);

        $refresh = new OAuth2TokenStore(
            serviceName: 'svc',
            tokenUrl: 'https://idp.test/oauth/token',
            clientId: 'cid',
            clientSecret: 'csec',
            seedRefreshToken: 'rt-1',
        );

        $refresh->refresh(OAuth2TokenStore::GRANT_REFRESH_TOKEN);
        $refresh->refresh(OAuth2TokenStore::GRANT_REFRESH_TOKEN);

        Http::assertSent(fn (Request $request): bool => str_contains((string) $request->body(), 'refresh_token=rt-1'));
    }

    public function test_invalidate_clears_only_access_token_for_grant(): void
    {
        Http::fake([
            'https://idp.test/oauth/token' => Http::sequence()
                ->push(['access_token' => 'first', 'expires_in' => 3600])
                ->push(['access_token' => 'second', 'expires_in' => 3600]),
        ]);

        $store = new OAuth2TokenStore(
            serviceName: 'svc',
            tokenUrl: 'https://idp.test/oauth/token',
            clientId: 'cid',
            clientSecret: 'csec',
        );

        $this->assertSame('first', $store->getAccessToken(OAuth2TokenStore::GRANT_CLIENT_CREDENTIALS));
        $store->invalidate(OAuth2TokenStore::GRANT_CLIENT_CREDENTIALS);
        $this->assertSame('second', $store->getAccessToken(OAuth2TokenStore::GRANT_CLIENT_CREDENTIALS));
    }

    public function test_grant_failure_throws_runtime_exception_with_status(): void
    {
        Http::fake([
            'https://idp.test/oauth/token' => Http::response(['error' => 'invalid_grant'], 400),
        ]);

        $store = new OAuth2TokenStore(
            serviceName: 'svc',
            tokenUrl: 'https://idp.test/oauth/token',
            clientId: 'cid',
            clientSecret: 'csec',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/HTTP 400/');

        $store->getAccessToken(OAuth2TokenStore::GRANT_CLIENT_CREDENTIALS);
    }

    public function test_missing_required_params_throws(): void
    {
        $store = new OAuth2TokenStore(
            serviceName: 'svc',
            tokenUrl: '', // missing
            clientId: 'cid',
            clientSecret: 'csec',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/token_url/');

        $store->getAccessToken(OAuth2TokenStore::GRANT_CLIENT_CREDENTIALS);
    }

    public function test_password_grant_missing_username_throws(): void
    {
        $store = new OAuth2TokenStore(
            serviceName: 'svc',
            tokenUrl: 'https://idp.test/oauth/token',
            clientId: 'cid',
            clientSecret: 'csec',
            // username/password absent
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/password grant requires username/');

        $store->getAccessToken(OAuth2TokenStore::GRANT_PASSWORD);
    }

    public function test_refresh_token_grant_with_no_seed_or_cache_throws(): void
    {
        $store = new OAuth2TokenStore(
            serviceName: 'svc',
            tokenUrl: 'https://idp.test/oauth/token',
            clientId: 'cid',
            clientSecret: 'csec',
            // no seed, no cached token
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/cached or seeded refresh_token/');

        $store->getAccessToken(OAuth2TokenStore::GRANT_REFRESH_TOKEN);
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
