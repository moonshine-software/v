<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Unit\Auth;

use App\ApiAdmin\Auth\DelegatedAuthSession;
use App\ApiAdmin\Auth\SessionState;
use App\ApiAdmin\Tenancy\TenantContext;
use App\ApiAdmin\Tenancy\TenantResolver;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Orchestra\Testbench\TestCase;

class DelegatedAuthSessionTest extends TestCase
{
    public function test_local_delegated_auth_session_keys_keep_legacy_shape(): void
    {
        $request = $this->requestWithSession();

        DelegatedAuthSession::putOAuth($request, 'svc', [
            'access_token' => 'local-token',
            'expires_at' => time() + 3600,
        ]);

        $this->assertSame('local-token', DelegatedAuthSession::oauth($request, 'svc')['access_token'] ?? null);
        $this->assertTrue($request->session()->has('api-admin.delegated-auth.svc.oauth'));
    }

    public function test_hosted_delegated_auth_session_keys_are_tenant_scoped(): void
    {
        $request = $this->requestWithSession();

        $this->bindTenant('tenant-a', 'acme');
        DelegatedAuthSession::putOAuth($request, 'svc', [
            'access_token' => 'tenant-a-token',
            'expires_at' => time() + 3600,
        ]);

        $this->bindTenant('tenant-b', 'beta');
        DelegatedAuthSession::putSessionState($request, 'svc', new SessionState(
            cookies: ['sessionid' => 'tenant-b'],
            csrfToken: 'csrf-b',
            expiresAt: time() + 3600,
        ));

        $this->assertNull($this->delegatedOAuth($request, 'svc'));
        $this->assertSame('tenant-b', DelegatedAuthSession::sessionState($request, 'svc')?->cookies['sessionid']);

        $this->bindTenant('tenant-a', 'acme');
        $tenantAState = $this->delegatedOAuth($request, 'svc');
        $this->assertNotNull($tenantAState);
        $this->assertSame('tenant-a-token', $tenantAState['access_token']);
        $this->assertNull(DelegatedAuthSession::sessionState($request, 'svc'));
    }

    private function requestWithSession(): Request
    {
        $request = Request::create('/admin');
        $request->setLaravelSession(new Store('test-session', new ArraySessionHandler(3600)));

        return $request;
    }

    /**
     * @return array{access_token: string, refresh_token?: string, expires_at?: int}|null
     */
    private function delegatedOAuth(Request $request, string $service): ?array
    {
        return DelegatedAuthSession::oauth($request, $service);
    }

    private function bindTenant(string $id, string $slug): void
    {
        config(['api-admin.hosted.mode' => 'hosted']);

        app()->instance(TenantResolver::class, new readonly class($id, $slug) implements TenantResolver
        {
            public function __construct(
                private string $id,
                private string $slug,
            ) {}

            public function resolve(): TenantContext
            {
                return new TenantContext(
                    id: $this->id,
                    slug: $this->slug,
                    name: $this->slug,
                    mode: 'hosted',
                    isLocal: false,
                );
            }
        });
    }
}
