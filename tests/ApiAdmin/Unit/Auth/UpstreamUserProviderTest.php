<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Unit\Auth;

use App\ApiAdmin\Auth\DelegatedAuthSession;
use App\ApiAdmin\Auth\UpstreamUserProvider;
use App\ApiAdmin\Tenancy\TenantContext;
use App\ApiAdmin\Tenancy\TenantResolver;
use App\Models\MoonshineUserUpstreamIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Http;
use MoonShine\Laravel\Models\MoonshineUser;
use Tests\TestCase;

class UpstreamUserProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_retrieve_by_credentials_upserts_user_and_identity_link(): void
    {
        Http::fake([
            'https://api.test/me' => Http::response([
                'id' => 'up-1',
                'email' => 'alice@example.test',
                'name' => 'Alice',
                'avatar_url' => 'https://cdn.test/a.png',
            ]),
        ]);

        $provider = new UpstreamUserProvider('svc', 'https://api.test', '/me');

        $user = $provider->retrieveByCredentials([
            'access_token' => 'access-1',
            'refresh_token' => 'refresh-1',
            'expires_at' => time() + 600,
        ]);

        $this->assertInstanceOf(MoonshineUser::class, $user);
        $this->assertSame('alice@example.test', $user->email);
        $this->assertSame('Alice', $user->name);
        $this->assertSame('https://cdn.test/a.png', $user->avatar);

        $identity = MoonshineUserUpstreamIdentity::query()->firstOrFail();
        $this->assertSame($user->getKey(), $identity->moonshine_user_id);
        $this->assertSame('svc', $identity->provider_service);
        $this->assertSame('up-1', $identity->subject_id);
        $this->assertSame('refresh-1', $identity->refresh_token);
        $this->assertNotNull($identity->access_token_expires_at);

        Http::assertSent(fn (\Illuminate\Http\Client\Request $request): bool => $request->hasHeader('Authorization', 'Bearer access-1'));
    }

    public function test_retrieve_by_id_refreshes_profile_from_current_session_state(): void
    {
        Http::fake([
            'https://api.test/me' => Http::sequence()
                ->push([
                    'id' => 'up-1',
                    'email' => 'old@example.test',
                    'name' => 'Old Name',
                ])
                ->push([
                    'id' => 'up-1',
                    'email' => 'new@example.test',
                    'name' => 'New Name',
                    'picture' => 'https://cdn.test/new.png',
                ]),
        ]);

        $request = $this->requestWithSession();
        app()->instance('request', $request);

        $provider = new UpstreamUserProvider('svc', 'https://api.test', '/me');
        $user = $provider->retrieveByCredentials(['access_token' => 'access-1']);
        $this->assertInstanceOf(MoonshineUser::class, $user);

        DelegatedAuthSession::putToken($request, 'svc', [
            'access_token' => 'access-2',
            'refresh_token' => 'refresh-2',
            'expires_at' => time() + 600,
        ]);

        $updated = $provider->retrieveById($user->getKey());

        $this->assertInstanceOf(MoonshineUser::class, $updated);
        $this->assertSame($user->getKey(), $updated->getKey());
        $this->assertSame('new@example.test', $updated->email);
        $this->assertSame('New Name', $updated->name);
        $this->assertSame('https://cdn.test/new.png', $updated->avatar);
        $this->assertSame(1, MoonshineUserUpstreamIdentity::query()->count());
    }

    public function test_upstream_email_cannot_take_over_existing_local_admin(): void
    {
        $admin = MoonshineUser::query()->create(['email' => 'admin@example.test', 'name' => 'Owner', 'password' => 'unknown']);
        Http::fake(['https://api.test/me' => Http::response(['id' => 'attacker', 'email' => 'admin@example.test'])]);
        $user = new UpstreamUserProvider('svc', 'https://api.test', '/me')->retrieveByCredentials(['access_token' => 'token']);
        $this->assertInstanceOf(MoonshineUser::class, $user);
        $this->assertNotSame($admin->getKey(), $user->getKey());
        $this->assertNotSame($admin->email, $user->email);
        $this->assertSame('Owner', $admin->fresh()?->name);
    }

    public function test_hosted_identity_and_guard_are_scoped_to_tenant(): void
    {
        config(['api-admin.hosted.mode' => 'hosted']);
        Http::fake(['https://api.test/me' => Http::response(['id' => 'same-subject', 'email' => 'same@example.test'])]);
        $this->useTenant('a');
        $firstProvider = new UpstreamUserProvider('svc', 'https://api.test', '/me', tenantId: 'a');
        $first = $firstProvider->retrieveByCredentials(['access_token' => 'token-a']);
        $this->assertInstanceOf(MoonshineUser::class, $first);
        $firstGuard = UpstreamUserProvider::guardName('panel');
        $this->useTenant('b');
        $secondProvider = new UpstreamUserProvider('svc', 'https://api.test', '/me', tenantId: 'b');
        $second = $secondProvider->retrieveByCredentials(['access_token' => 'token-b']);
        $this->assertInstanceOf(MoonshineUser::class, $second);
        $this->assertNotSame($first->getKey(), $second->getKey());
        $this->assertNotSame($firstGuard, UpstreamUserProvider::guardName('panel'));
        $this->assertSame(2, MoonshineUserUpstreamIdentity::query()->count());
        $this->assertNull($secondProvider->retrieveById($first->getKey()));
        $this->assertNull($firstProvider->retrieveByCredentials(['access_token' => 'token-a']));
    }

    public function test_invalid_current_upstream_token_does_not_restore_session_user(): void
    {
        Http::fake(['https://api.test/me' => Http::sequence()->push(['id' => 'one'])->pushStatus(401)]);
        $request = $this->requestWithSession();
        app()->instance('request', $request);
        $provider = new UpstreamUserProvider('svc', 'https://api.test', '/me');
        $user = $provider->retrieveByCredentials(['access_token' => 'valid']);
        $this->assertInstanceOf(MoonshineUser::class, $user);
        $this->assertNull($provider->retrieveById($user->getKey()));
        DelegatedAuthSession::putToken($request, 'svc', ['access_token' => 'revoked', 'refresh_token' => '', 'expires_at' => 0]);
        $this->assertNull($provider->retrieveById($user->getKey()));
    }

    private function useTenant(string $id): void
    {
        app()->instance(TenantResolver::class, new readonly class($id) implements TenantResolver
        {
            public function __construct(private string $id) {}

            public function resolve(): TenantContext
            {
                return new TenantContext($this->id, $this->id, $this->id, 'hosted', false);
            }
        });
    }

    private function requestWithSession(): Request
    {
        $request = Request::create('/admin');
        $request->setLaravelSession(new Store('test-session', new ArraySessionHandler(3600)));

        return $request;
    }
}
