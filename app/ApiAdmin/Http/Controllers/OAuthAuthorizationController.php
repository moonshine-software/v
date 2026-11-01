<?php

declare(strict_types=1);

namespace App\ApiAdmin\Http\Controllers;

use App\ApiAdmin\Auth\DelegatedAuthSession;
use App\ApiAdmin\Auth\UpstreamUserProvider;
use App\ApiAdmin\Providers\ApiAdminServiceProvider;
use App\ApiAdmin\ServiceConfig\AdminAuthConfig;
use App\ApiAdmin\ServiceConfig\ServiceConfig;
use App\ApiAdmin\Tenancy\TenantKeyBuilder;
use App\ApiAdmin\Tenancy\TenantRoutes;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

final class OAuthAuthorizationController extends Controller
{
    public function redirect(Request $request): RedirectResponse
    {
        $service = $this->serviceForPanel($request);
        $this->ensureOAuthCodeMode($service);

        $state = $this->base64Url(random_bytes(32));
        $codeVerifier = $this->base64Url(random_bytes(64));
        $codeChallenge = $this->base64Url(hash('sha256', $codeVerifier, binary: true));
        $redirectUri = $this->redirectUri($service);

        $request->session()->put($this->stateKey($service), [
            'state' => $state,
            'code_verifier' => $codeVerifier,
            'redirect_uri' => $redirectUri,
        ]);

        Log::info('[OAuthAuthorizationController.redirect] starting authorization_code flow', [
            'service' => $service->name,
            'panel' => $service->panel,
            'authorize_url' => $service->adminAuth->authorizeUrl,
            'redirect_uri' => $redirectUri,
            'scopes_count' => count($service->adminAuth->scopes),
        ]);

        return redirect()->away($this->authorizationUrl($service, $state, $codeChallenge, $redirectUri));
    }

    public function callback(Request $request): RedirectResponse
    {
        $service = $this->serviceForPanel($request);
        $this->ensureOAuthCodeMode($service);

        $pending = $request->session()->pull($this->stateKey($service));
        $state = $request->query('state');
        $code = $request->query('code');

        if (! is_array($pending)
            || ! is_string($pending['state'] ?? null)
            || ! hash_equals($pending['state'], is_string($state) ? $state : '')
        ) {
            Log::warning('[OAuthAuthorizationController.callback] rejected callback state', [
                'service' => $service->name,
                'panel' => $service->panel,
            ]);

            abort(Response::HTTP_BAD_REQUEST, 'Invalid OAuth state.');
        }

        if (! is_string($code) || $code === '') {
            Log::warning('[OAuthAuthorizationController.callback] missing authorization code', [
                'service' => $service->name,
                'panel' => $service->panel,
            ]);

            abort(Response::HTTP_BAD_REQUEST, 'Missing OAuth authorization code.');
        }

        $codeVerifier = $pending['code_verifier'] ?? null;
        $redirectUri = $pending['redirect_uri'] ?? null;

        if (! is_string($codeVerifier) || $codeVerifier === '' || ! is_string($redirectUri) || $redirectUri === '') {
            Log::warning('[OAuthAuthorizationController.callback] missing verifier state', [
                'service' => $service->name,
                'panel' => $service->panel,
            ]);

            abort(Response::HTTP_BAD_REQUEST, 'Missing OAuth verifier.');
        }

        $tokenState = $service->oauthTokenStoreForAdminAuth()
            ->fetchAuthorizationCodeGrant($code, $codeVerifier, $redirectUri);

        DelegatedAuthSession::putOAuth($request, $service->name, $tokenState);
        $this->loginUpstreamUser($service, $tokenState);

        Log::info('Authorization_code flow completed', [
            'tenant_id' => $service->tenantId,
            'service' => $service->name,
            'panel' => $service->panel,
            'has_refresh_token' => $tokenState['refresh_token'] !== '',
        ]);

        return redirect()->to($this->panelUrl($service));
    }

    private function serviceForPanel(Request $request): ServiceConfig
    {
        $panel = $request->route('panel');
        abort_unless(is_string($panel) && $panel !== '', 404);
        $services = config('api-admin.services', []);

        if (! is_array($services)) {
            throw new RuntimeException('api-admin.services config is not an array.');
        }

        foreach ($services as $name => $config) {
            if (! is_array($config)) {
                continue;
            }

            if (($config['panel'] ?? null) === $panel) {
                return ApiAdminServiceProvider::resolveService((string) $name);
            }
        }

        throw new RuntimeException("No ApiAdmin service registered for panel '{$panel}'.");
    }

    private function ensureOAuthCodeMode(ServiceConfig $service): void
    {
        if ($service->adminAuth->mode !== AdminAuthConfig::MODE_OAUTH2_AUTHORIZATION_CODE) {
            throw new RuntimeException("Service '{$service->name}' does not use oauth2_authorization_code admin auth.");
        }

        if ($service->adminAuth->authorizeUrl === '' || $service->oauthTokenStoreForAdminAuth()->tokenUrl === '') {
            throw new RuntimeException("Service '{$service->name}' requires admin_auth.authorize_url and admin_auth.token_url.");
        }
    }

    private function authorizationUrl(ServiceConfig $service, string $state, string $codeChallenge, string $redirectUri): string
    {
        $query = [
            'response_type' => 'code',
            'client_id' => $service->oauthClientId,
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ];

        if ($service->adminAuth->scopes !== []) {
            $query['scope'] = implode(' ', $service->adminAuth->scopes);
        }

        return $service->adminAuth->authorizeUrl.(str_contains($service->adminAuth->authorizeUrl, '?') ? '&' : '?').http_build_query($query);
    }

    private function redirectUri(ServiceConfig $service): string
    {
        if ($service->adminAuth->redirectUri !== '') {
            return $service->adminAuth->redirectUri;
        }

        return url('/'.TenantRoutes::expandPrefix(TenantRoutes::scopedPrefix($service->panel.'/oauth/callback')));
    }

    private function panelUrl(ServiceConfig $service): string
    {
        return '/'.TenantRoutes::expandPrefix(TenantRoutes::scopedPrefix($service->panel));
    }

    private function stateKey(ServiceConfig $service): string
    {
        return TenantKeyBuilder::sessionKey('oauth-code', $service->name, 'pending');
    }

    private function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /**
     * @param array{access_token: string, refresh_token: string, expires_at: int} $tokenState
     */
    private function loginUpstreamUser(ServiceConfig $service, array $tokenState): void
    {
        $guard = Auth::guard(UpstreamUserProvider::guardName($service->panel));
        if ($guard->check()) {
            return;
        }

        $user = $this->upstreamUserProvider($service)->retrieveByCredentials($tokenState);

        if (! $user instanceof Authenticatable) {
            throw new RuntimeException("Service '{$service->name}' OAuth identity sync failed.");
        }

        $guard->login($user);
        request()->session()->regenerate();
    }

    private function upstreamUserProvider(ServiceConfig $service): UpstreamUserProvider
    {
        return new UpstreamUserProvider(
            serviceName: $service->name,
            baseUrl: $service->baseUrl,
            validateEndpoint: $service->adminAuth->validateEndpoint,
            healthPath: $service->healthPath,
            mode: $service->adminAuth->mode,
            tenantId: $service->tenantId,
        );
    }
}
