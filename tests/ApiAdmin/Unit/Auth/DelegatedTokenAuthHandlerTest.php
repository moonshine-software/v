<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Unit\Auth;

use App\ApiAdmin\Auth\DelegatedAuthSession;
use App\ApiAdmin\Auth\DelegatedTokenAuthHandler;
use App\ApiAdmin\Auth\OAuth2TokenStore;
use GuzzleHttp\Psr7\Request as PsrRequest;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;

class DelegatedTokenAuthHandlerTest extends TestCase
{
    public function test_current_headers_use_delegated_token_session_state(): void
    {
        $request = $this->requestWithSession();
        DelegatedAuthSession::putToken($request, 'svc', [
            'access_token' => 'token-1',
            'refresh_token' => 'refresh-1',
            'expires_at' => time() + 3600,
        ]);

        $handler = new DelegatedTokenAuthHandler('svc', $this->tokenStore(), $request);

        $this->assertSame(['Authorization' => 'Bearer token-1'], $handler->currentHeaders());
    }

    public function test_refresh_rotates_delegated_token_and_rebuilds_request(): void
    {
        Http::fake([
            'https://api.test/fail' => Http::response(['error' => 'expired'], 401),
            'https://idp.test/oauth/token' => Http::response([
                'access_token' => 'token-2',
                'refresh_token' => 'refresh-2',
                'expires_in' => 600,
            ]),
        ]);

        $request = $this->requestWithSession();
        DelegatedAuthSession::putToken($request, 'svc', [
            'access_token' => 'token-1',
            'refresh_token' => 'refresh-1',
            'expires_at' => time() + 60,
        ]);

        $handler = new DelegatedTokenAuthHandler('svc', $this->tokenStore(), $request);

        $this->assertTrue($handler->refresh(Http::get('https://api.test/fail')));
        $state = DelegatedAuthSession::token($request, 'svc');
        $this->assertNotNull($state);
        $this->assertSame('token-2', $state['access_token']);
        $this->assertSame('refresh-2', $state['refresh_token']);

        $rebuilt = $handler->rebuildRequest(new PsrRequest('GET', 'https://api.test/me'));
        $this->assertSame(['Bearer token-2'], $rebuilt->getHeader('Authorization'));
    }

    public function test_refresh_without_refresh_token_forgets_delegated_state(): void
    {
        Http::fake([
            'https://api.test/fail' => Http::response(['error' => 'expired'], 401),
        ]);

        $request = $this->requestWithSession();
        DelegatedAuthSession::putToken($request, 'svc', [
            'access_token' => 'token-1',
            'refresh_token' => '',
            'expires_at' => time() - 1,
        ]);

        $handler = new DelegatedTokenAuthHandler('svc', $this->tokenStore(), $request);

        $this->assertFalse($handler->refresh(Http::get('https://api.test/fail')));
        $this->assertNull(DelegatedAuthSession::token($request, 'svc'));
    }

    private function requestWithSession(): Request
    {
        $request = Request::create('/admin');
        $request->setLaravelSession(new Store('test-session', new ArraySessionHandler(3600)));

        return $request;
    }

    private function tokenStore(): OAuth2TokenStore
    {
        return new OAuth2TokenStore(
            serviceName: 'svc',
            tokenUrl: 'https://idp.test/oauth/token',
            clientId: 'cid',
            clientSecret: 'csec',
        );
    }
}
