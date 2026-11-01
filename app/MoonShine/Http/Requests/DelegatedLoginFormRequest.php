<?php

declare(strict_types=1);

namespace App\MoonShine\Http\Requests;

use App\ApiAdmin\Auth\DelegatedAuthSession;
use App\ApiAdmin\Auth\UpstreamUserProvider;
use App\ApiAdmin\Http\OutboundHttp;
use App\ApiAdmin\Providers\ApiAdminServiceProvider;
use App\ApiAdmin\ServiceConfig\AdminAuthConfig;
use App\ApiAdmin\ServiceConfig\ServiceConfig;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use MoonShine\Laravel\Http\Requests\LoginFormRequest;
use Override;
use RuntimeException;
use Throwable;

class DelegatedLoginFormRequest extends LoginFormRequest
{
    #[Override]
    protected function prepareForValidation(): void
    {
        $service = $this->serviceForCurrentPanel();
        if ($service instanceof ServiceConfig && ($service->adminLogin || $service->adminAuth->mode !== AdminAuthConfig::MODE_MOONSHINE)) {
            return;
        }

        parent::prepareForValidation();
    }

    /** @return array{username: array<string>, password: array<string>} */
    #[Override]
    public function rules(): array
    {
        return $this->serviceForCurrentPanel()?->adminAuth->mode === AdminAuthConfig::MODE_DELEGATED_TOKEN
            ? ['username' => ['required', 'string'], 'password' => ['nullable', 'string']]
            : parent::rules();
    }

    #[Override]
    public function getThrottleKey(): string
    {
        return $this->serviceForCurrentPanel()?->adminAuth->mode === AdminAuthConfig::MODE_DELEGATED_TOKEN
            ? 'api-admin-pat:'.hash('sha256', trim($this->string('username')->value())).'|'.$this->ip()
            : parent::getThrottleKey();
    }

    /**
     * @throws ValidationException
     */
    #[Override]
    public function authenticate(): void
    {
        $service = $this->serviceForCurrentPanel();

        if (! $service instanceof ServiceConfig || (! $service->adminLogin && $service->adminAuth->mode === AdminAuthConfig::MODE_MOONSHINE)) {
            parent::authenticate();

            return;
        }

        $this->ensureIsNotRateLimited();

        try {
            $upstreamUser = $this->authenticateUpstream($service);
            $this->loginUpstreamUser($service, $upstreamUser ?? $this->linkUpstreamSubject($service));
        } catch (Throwable) {
            $this->failDelegatedLogin();
        }

        session()->regenerate();

        RateLimiter::clear($this->getThrottleKey());
    }

    private function serviceForCurrentPanel(): ?ServiceConfig
    {
        $panel = request()->getPanelName();
        $services = config('api-admin.services', []);

        if (! is_array($services)) {
            return null;
        }

        foreach ($services as $name => $config) {
            if (! is_array($config)) {
                continue;
            }

            if (($config['panel'] ?? null) !== $panel) {
                continue;
            }

            return ApiAdminServiceProvider::resolveService((string) $name);
        }

        return null;
    }

    private function authenticateUpstream(ServiceConfig $service): ?Authenticatable
    {
        if ($service->adminAuth->mode === AdminAuthConfig::MODE_DELEGATED_TOKEN) {
            return $this->authenticateDelegatedToken($service);
        }

        $usernameRaw = $this->input('username');
        $passwordRaw = $this->input('password');
        $username = is_string($usernameRaw) ? $usernameRaw : '';
        $password = is_string($passwordRaw) ? $passwordRaw : '';

        if ($service->authType === 'oauth2_password') {
            $state = $service->oauthTokenStore()
                ->fetchPasswordGrantForCredentials($username, $password);

            DelegatedAuthSession::putOAuth($this, $service->name, $state);

            return null;
        }

        if ($service->authType === 'session') {
            $state = $service->sessionAuthHandlerForCredentials($username, $password)
                ->loginFresh();

            DelegatedAuthSession::putSessionState($this, $service->name, $state);

            return null;
        }

        throw new RuntimeException("Service '{$service->name}' does not support delegated admin login.");
    }

    private function authenticateDelegatedToken(ServiceConfig $service): Authenticatable
    {
        $tokenRaw = $this->input('username');
        $refreshRaw = $this->input('password');
        $token = is_string($tokenRaw) ? trim($tokenRaw) : '';
        $refreshToken = is_string($refreshRaw) ? trim($refreshRaw) : '';
        $endpoint = $service->adminAuth->validateEndpoint !== ''
            ? $service->adminAuth->validateEndpoint
            : ($service->healthPath !== '' ? $service->healthPath : '/me');

        if ($token === '') {
            throw new RuntimeException("Service '{$service->name}' delegated token login requires a token.");
        }

        Log::info('Validating delegated token through guarded HTTP client', [
            'service' => $service->name,
            'endpoint' => $endpoint,
            'has_refresh_token' => $refreshToken !== '',
        ]);

        $response = OutboundHttp::client($service->baseUrl)
            ->connectTimeout(5)
            ->timeout(15)
            ->acceptJson()
            ->withToken($token)
            ->get($endpoint);

        if (! $response->successful()) {
            Log::warning('[DelegatedLoginFormRequest] delegated token validation failed', [
                'service' => $service->name,
                'endpoint' => $endpoint,
                'status' => $response->status(),
            ]);

            throw new RuntimeException("Service '{$service->name}' delegated token validation failed.");
        }

        DelegatedAuthSession::putToken($this, $service->name, [
            'access_token' => $token,
            'refresh_token' => $refreshToken,
            'expires_at' => 0,
        ]);

        $user = $this->upstreamUserProvider($service)
            ->retrieveByCredentials([
                'access_token' => $token,
                'refresh_token' => $refreshToken,
                'expires_at' => 0,
            ]);

        if (! $user instanceof Authenticatable) {
            throw new RuntimeException("Service '{$service->name}' delegated token identity sync failed.");
        }

        return $user;
    }

    private function linkUpstreamSubject(ServiceConfig $service): Authenticatable
    {
        $subject = $this->input('username');
        if (! is_string($subject) || trim($subject) === '') {
            throw new RuntimeException("Service '{$service->name}' delegated login requires a username.");
        }

        return $this->upstreamUserProvider($service)->linkAuthenticatedSubject(trim($subject));
    }

    private function loginUpstreamUser(ServiceConfig $service, Authenticatable $user): void
    {
        $guard = Auth::guard(UpstreamUserProvider::guardName($service->panel));
        $guard->login($user, $this->boolean('remember'));
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

    /**
     * @throws ValidationException
     */
    private function failDelegatedLogin(): never
    {
        RateLimiter::hit($this->getThrottleKey());

        throw ValidationException::withMessages([
            'username' => __('moonshine::auth.failed'),
        ]);
    }
}
