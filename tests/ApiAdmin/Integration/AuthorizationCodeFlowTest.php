<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Integration;

use App\ApiAdmin\Auth\DelegatedAuthSession;
use App\ApiAdmin\Auth\UpstreamUserProvider;
use App\ApiAdmin\ServiceConfig\ServiceConfig;
use App\ApiAdmin\Tenancy\TenantContext;
use App\ApiAdmin\Tenancy\TenantKeyBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use MoonShine\Laravel\Models\MoonshineUser;
use Tests\TestCase;

class AuthorizationCodeFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_redirect_generates_pkce_challenge_and_state(): void
    {
        $this->bindOauthService();

        $response = $this->get('/oauth-admin/oauth/redirect');
        $response->assertRedirect();

        $location = $response->headers->get('Location');
        $this->assertIsString($location);
        $query = $this->parseQueryString($location);

        $this->assertSame('code', $query['response_type'] ?? null);
        $this->assertSame('cid', $query['client_id'] ?? null);
        $this->assertSame('S256', $query['code_challenge_method'] ?? null);
        $this->assertSame('openid profile', $query['scope'] ?? null);
        $this->assertIsString($query['state'] ?? null);
        $this->assertIsString($query['code_challenge'] ?? null);
        $this->assertArrayNotHasKey('code_verifier', $query);

        $pending = session(TenantKeyBuilder::sessionKey('oauth-code', 'oauth_panel', 'pending'));
        $this->assertIsArray($pending);
        $verifier = $pending['code_verifier'] ?? null;
        $this->assertIsString($verifier);
        $this->assertSame($query['state'], $pending['state'] ?? null);
        $this->assertSame($query['code_challenge'], $this->challenge($verifier));
    }

    public function test_callback_exchanges_code_validates_state_and_logs_in_synced_user(): void
    {
        $this->bindOauthService();

        Http::fake([
            'https://idp.test/oauth/token' => Http::response([
                'access_token' => 'oauth-access',
                'refresh_token' => 'oauth-refresh',
                'expires_in' => 600,
            ]),
            'https://api.oauth.test/me' => Http::response([
                'sub' => 'oauth-user-1',
                'email' => 'oauth@example.test',
                'name' => 'OAuth User',
            ]),
        ]);

        $key = TenantKeyBuilder::sessionKey('oauth-code', 'oauth_panel', 'pending');
        $response = $this
            ->withSession([
                $key => [
                    'state' => 'state-1',
                    'code_verifier' => 'verifier-1',
                    'redirect_uri' => 'https://moonshine.test/oauth-admin/oauth/callback',
                ],
            ])
            ->get('/oauth-admin/oauth/callback?state=state-1&code=code-1');

        $response->assertRedirect('/oauth-admin');

        $user = MoonshineUser::query()->where('email', 'oauth@example.test')->firstOrFail();
        $this->assertAuthenticatedAs($user, UpstreamUserProvider::guardName('oauth-admin'));
        $this->assertSame('oauth-access', DelegatedAuthSession::oauth(request(), 'oauth_panel')['access_token'] ?? null);

        Http::assertSent(function (Request $request): bool {
            $body = (string) $request->body();

            return $request->url() === 'https://idp.test/oauth/token'
                && str_contains($body, 'grant_type=authorization_code')
                && str_contains($body, 'code=code-1')
                && str_contains($body, 'code_verifier=verifier-1');
        });
    }

    public function test_callback_rejects_invalid_state_before_code_exchange(): void
    {
        $this->bindOauthService();
        Http::fake();

        $key = TenantKeyBuilder::sessionKey('oauth-code', 'oauth_panel', 'pending');
        $this
            ->withSession([
                $key => [
                    'state' => 'state-1',
                    'code_verifier' => 'verifier-1',
                    'redirect_uri' => 'https://moonshine.test/oauth-admin/oauth/callback',
                ],
            ])
            ->get('/oauth-admin/oauth/callback?state=wrong&code=code-1')
            ->assertBadRequest();

        Http::assertNothingSent();
    }

    /**
     * @return array<string, string>
     */
    private function parseQueryString(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $strings = [];
        foreach ($query as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $strings[$key] = $value;
            }
        }

        return $strings;
    }

    private function challenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, binary: true)), '+/', '-_'), '=');
    }

    private function bindOauthService(): void
    {
        $config = [
            'name' => 'oauth_panel',
            'base_url' => 'https://api.oauth.test',
            'panel' => 'oauth-admin',
            'auth_type' => 'none',
            'oauth_client_id' => 'cid',
            'oauth_client_secret' => 'csec',
            'admin_auth' => [
                'mode' => 'oauth2_authorization_code',
                'authorize_url' => 'https://idp.test/oauth/authorize',
                'token_url' => 'https://idp.test/oauth/token',
                'validate_endpoint' => '/me',
                'scopes' => ['openid', 'profile'],
                'redirect_uri' => 'https://moonshine.test/oauth-admin/oauth/callback',
            ],
            'tenant_id' => TenantContext::LOCAL_ID,
        ];

        config([
            'api-admin.services' => [
                'oauth_panel' => $config,
            ],
            'auth.providers.moonshine_users' => [
                'driver' => 'eloquent',
                'model' => MoonshineUser::class,
            ],
            'auth.guards.'.UpstreamUserProvider::guardName('oauth-admin') => [
                'driver' => 'session',
                'provider' => 'moonshine_users',
            ],
        ]);

        Auth::forgetGuards();

        $service = ServiceConfig::fromArray($config);
        app()->instance('api-admin.tenant.local.service.oauth_panel', $service);
        app()->instance('api-admin.service.oauth_panel', $service);
    }
}
