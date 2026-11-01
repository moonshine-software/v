<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\ApiAdmin\Auth\OAuth2TokenStore;
use App\ApiAdmin\ServiceConfig\ResponseMapping;
use App\ApiAdmin\ServiceConfig\ServiceConfig;
use App\ApiAdmin\Tenancy\TenantKeyBuilder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class OAuthTokenRotationTest extends TestCase
{
    use RefreshDatabase;

    public function test_rotated_refresh_token_survives_cache_clear_and_is_encrypted(): void
    {
        Http::fake([
            'https://idp.test/token' => Http::sequence()
                ->push(['access_token' => 'access-1', 'refresh_token' => 'rotated-1'])
                ->push(['access_token' => 'access-2'])
                ->push(['access_token' => 'access-3']),
            'https://api.test/items' => Http::response(['data' => []]),
        ]);

        $this->assertTrue($this->service()->httpClient()->get('/items')->successful());
        $encrypted = DB::table('api_admin_tenant_secrets')->value('value');
        $this->assertIsString($encrypted);
        $this->assertStringNotContainsString('rotated-1', $encrypted);

        foreach ([1, 2] as $iteration) {
            Cache::flush();
            $this->assertTrue($this->service()->httpClient()->get('/items')->successful());
        }

        $tokens = Http::recorded(static fn (Request $request): bool => $request->url() === 'https://idp.test/token');
        $this->assertSame(['seed', 'rotated-1', 'rotated-1'], $tokens->map(
            static fn (array $pair): mixed => $pair[0]['refresh_token'],
        )->values()->all());
    }

    public function test_existing_cached_rotation_is_migrated_before_the_seed_is_used(): void
    {
        $key = TenantKeyBuilder::cacheKey('local', 'oauth2', 'rotation', 'refresh', md5('client|https://idp.test/token'));
        Cache::put($key, 'legacy-rotation', 3600);
        Http::fake(['https://idp.test/token' => Http::response(['access_token' => 'access'])]);

        $this->service()->oauthTokenStore()->getAccessToken(OAuth2TokenStore::GRANT_REFRESH_TOKEN);
        Cache::flush();
        $this->service()->oauthTokenStore()->getAccessToken(OAuth2TokenStore::GRANT_REFRESH_TOKEN);

        Http::assertSentCount(2);
        Http::assertNotSent(static fn (Request $request): bool => $request['refresh_token'] !== 'legacy-rotation');
        $this->assertDatabaseCount('api_admin_tenant_secrets', 1);
    }

    public function test_delegated_refresh_uses_only_the_supplied_user_token(): void
    {
        $key = TenantKeyBuilder::cacheKey('local', 'oauth2', 'rotation', 'refresh', md5('client|https://idp.test/token'));
        Cache::put($key, 'another-users-token', 3600);
        Http::fake(['https://idp.test/token' => Http::response(['access_token' => 'access', 'refresh_token' => 'next'])]);

        $state = $this->service()->oauthTokenStore()->fetchRefreshGrantForToken('delegated-user-token');

        $this->assertSame('next', $state['refresh_token']);
        Http::assertSent(static fn (Request $request): bool => $request['refresh_token'] === 'delegated-user-token');
        $this->assertDatabaseCount('api_admin_tenant_secrets', 0);
    }

    public function test_explicit_new_seed_does_not_reuse_previous_rotation_or_access_token(): void
    {
        Http::fake(['https://idp.test/token' => Http::sequence()
            ->push(['access_token' => 'old-access', 'refresh_token' => 'old-rotation'])
            ->push(['access_token' => 'new-access', 'refresh_token' => 'new-rotation']),
        ]);
        $this->assertSame('old-access', $this->service()->oauthTokenStore()->getAccessToken(OAuth2TokenStore::GRANT_REFRESH_TOKEN));
        $this->assertSame('new-access', $this->service('new-seed')->oauthTokenStore()->getAccessToken(OAuth2TokenStore::GRANT_REFRESH_TOKEN));
        Http::assertSent(static fn (Request $request): bool => $request['refresh_token'] === 'new-seed');
    }

    public function test_tenant_user_and_client_rotation_state_stays_isolated_after_cache_clear(): void
    {
        Http::fake(static function (Request $request) {
            $token = $request['refresh_token'];
            $thisToken = is_string($token) ? $token : '';

            return Http::response(['access_token' => 'access', 'refresh_token' => $thisToken.'-next']);
        });
        config(['auth.guards.moonshine' => ['driver' => 'session', 'provider' => 'users']]);

        foreach (['tenant-a', 'tenant-b'] as $tenant) {
            foreach ([101, 202] as $userId) {
                $user = new User;
                $user->setAttribute('id', $userId);
                Auth::guard('moonshine')->setUser($user);
                foreach (['client-a', 'client-b'] as $client) {
                    $store = new OAuth2TokenStore('rotation', 'https://idp.test/token', $client, 'secret', seedRefreshToken: 'seed', tenantId: $tenant);
                    $store->getAccessToken(OAuth2TokenStore::GRANT_REFRESH_TOKEN);
                }
            }
        }
        $this->assertDatabaseCount('api_admin_tenant_secrets', 8);
        Cache::flush();
        $store = new OAuth2TokenStore('rotation', 'https://idp.test/token', 'client-a', 'secret', seedRefreshToken: 'seed', tenantId: 'tenant-a');
        $store->getAccessToken(OAuth2TokenStore::GRANT_REFRESH_TOKEN);
        Http::assertSentCount(9);
        $requests = Http::recorded();
        $last = $requests->last();
        $this->assertNotNull($last);
        $this->assertSame('seed-next', $last[0]['refresh_token']);
    }

    public function test_failed_refresh_releases_the_lock_for_a_later_request(): void
    {
        Http::fake(['https://idp.test/token' => Http::sequence()
            ->push(['error' => 'temporarily_unavailable'], 503)
            ->push(['access_token' => 'recovered', 'refresh_token' => 'next']),
        ]);
        $store = $this->service()->oauthTokenStore();

        try {
            $store->getAccessToken(OAuth2TokenStore::GRANT_REFRESH_TOKEN);
            $this->fail('An upstream failure must be reported.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('HTTP 503', $exception->getMessage());
        }
        $this->assertSame('recovered', $store->getAccessToken(OAuth2TokenStore::GRANT_REFRESH_TOKEN));
        Http::assertSentCount(2);
    }

    public function test_late_401_reuses_the_token_another_request_already_refreshed(): void
    {
        $service = $this->service();
        Http::fake([
            'https://idp.test/token' => Http::sequence()
                ->push(['access_token' => 'stale', 'refresh_token' => 'rotated-1'])
                ->push(['access_token' => 'fresh', 'refresh_token' => 'rotated-2']),
            'https://api.test/items' => static function (Request $request) use ($service) {
                if ($request->hasHeader('Authorization', 'Bearer stale')) {
                    $service->oauthTokenStore()->refresh(OAuth2TokenStore::GRANT_REFRESH_TOKEN, 'stale');

                    return Http::response(['error' => 'expired'], 401);
                }

                return Http::response(['data' => [['id' => 1]]]);
            },
        ]);

        $this->assertTrue($service->httpClient()->get('/items')->successful());
        $this->assertCount(2, Http::recorded(static fn (Request $request): bool => $request->url() === 'https://idp.test/token'));
        Http::assertSent(static fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer fresh'));
    }

    public function test_corrupt_rotation_fails_without_falling_back_to_seed_or_sending_http(): void
    {
        Http::fake(['https://idp.test/token' => Http::response(['access_token' => 'access', 'refresh_token' => 'rotation'])]);
        $this->service()->oauthTokenStore()->getAccessToken(OAuth2TokenStore::GRANT_REFRESH_TOKEN);
        DB::table('api_admin_tenant_secrets')->update(['value' => 'corrupt ciphertext']);
        Cache::flush();

        try {
            $this->service()->oauthTokenStore()->getAccessToken(OAuth2TokenStore::GRANT_REFRESH_TOKEN);
            $this->fail('Corrupt ciphertext must fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Failed to read tenant secret', $exception->getMessage());
        }
        Http::assertSentCount(1);
    }

    #[DataProvider('concurrentOperations')]
    public function test_two_processes_share_one_refresh_and_release_the_lock(string $operation): void
    {
        $directory = sys_get_temp_dir().'/moonshine-oauth-race-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($directory);
        File::put($directory.'/database.sqlite', '');
        File::put($directory.'/upstream.json', json_encode(['refresh' => 'seed', 'calls' => 0], JSON_THROW_ON_ERROR));
        config(['database.connections.oauth_race' => ['driver' => 'sqlite', 'database' => $directory.'/database.sqlite']]);
        DB::connection('oauth_race')->getSchemaBuilder()->create('api_admin_tenant_secrets', static function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id');
            $table->string('service');
            $table->string('name');
            $table->text('value');
            $table->timestamps();
            $table->unique(['tenant_id', 'service', 'name']);
        });
        $processes = [];
        $key = config('app.key');
        $this->assertIsString($key);

        try {
            foreach ([1, 2] as $worker) {
                $process = new Process([PHP_BINARY, base_path('tests/Fixtures/oauth-token-worker.php'), $directory, $operation, (string) $worker], base_path(), [
                    'XDEBUG_MODE' => 'off', 'APP_ENV' => 'testing', 'LOG_CHANNEL' => 'null',
                    'APP_KEY' => $key, 'DB_DATABASE' => $directory.'/database.sqlite',
                    'DB_CONNECTION' => 'sqlite', 'CACHE_STORE' => 'file', 'SESSION_DRIVER' => 'array',
                ]);
                $process->setTimeout(15);
                $process->start();
                $processes[] = $process;
            }
            foreach ($processes as $process) {
                $this->assertSame(0, $process->wait(), $process->getErrorOutput().$process->getOutput());
                $this->assertSame('access-next', trim($process->getOutput()));
            }
            $state = json_decode(File::get($directory.'/upstream.json'), true, flags: JSON_THROW_ON_ERROR);
            $this->assertIsArray($state);
            $this->assertSame(1, $state['calls']);
        } finally {
            foreach ($processes as $process) {
                $process->stop();
            }
            DB::purge('oauth_race');
            File::deleteDirectory($directory);
        }
    }

    /** @return array<string, array{string}> */
    public static function concurrentOperations(): array
    {
        return ['cache miss' => ['get'], 'simultaneous 401' => ['refresh']];
    }

    private function service(string $seed = 'seed'): ServiceConfig
    {
        return new ServiceConfig(name: 'rotation', baseUrl: 'https://api.test', apiKey: '', responseMapping: new ResponseMapping, authType: 'oauth2_refresh_token',
            tokenUrl: 'https://idp.test/token', oauthClientId: 'client', oauthClientSecret: 'secret', oauthRefreshToken: $seed);
    }
}
