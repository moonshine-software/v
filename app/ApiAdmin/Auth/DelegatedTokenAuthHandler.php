<?php

declare(strict_types=1);

namespace App\ApiAdmin\Auth;

use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Throwable;

/**
 * Per-user token/PAT auth handler for admin_auth_mode="delegated_token".
 */
final readonly class DelegatedTokenAuthHandler implements AuthRetryHandler
{
    public function __construct(
        private string $serviceName,
        private OAuth2TokenStore $tokenStore,
        private Request $request,
        private string $sessionType = 'token',
    ) {}

    /**
     * @return array<string, string>
     */
    public function currentHeaders(): array
    {
        $state = $this->state();

        if ($state === null) {
            Log::debug('[DelegatedTokenAuthHandler.currentHeaders] no delegated token in session', [
                'service' => $this->serviceName,
                'session_type' => $this->sessionType,
            ]);

            return [];
        }

        return ['Authorization' => 'Bearer '.$state['access_token']];
    }

    public function refresh(Response $failed, ?RequestInterface $request = null): bool
    {
        $state = $this->state();

        Log::info('[DelegatedTokenAuthHandler.refresh] upstream rejected delegated token', [
            'service' => $this->serviceName,
            'status' => $failed->status(),
            'has_refresh_token' => $state !== null && $state['refresh_token'] !== '',
            'session_type' => $this->sessionType,
        ]);

        if ($state === null) {
            return false;
        }

        if ($state['refresh_token'] === '') {
            DelegatedAuthSession::forget($this->request, $this->serviceName);

            return false;
        }

        try {
            $next = $this->tokenStore->fetchRefreshGrantForToken($state['refresh_token']);
            if ($next['refresh_token'] === '') {
                $next['refresh_token'] = $state['refresh_token'];
            }

            $this->putState($next);

            return true;
        } catch (Throwable $e) {
            Log::warning('[DelegatedTokenAuthHandler.refresh] refresh failed', [
                'service' => $this->serviceName,
                'error' => $e->getMessage(),
            ]);

            DelegatedAuthSession::forget($this->request, $this->serviceName);

            return false;
        }
    }

    public function rebuildRequest(RequestInterface $request): RequestInterface
    {
        $state = $this->state();

        if ($state === null) {
            throw new RuntimeException(
                "DelegatedTokenAuthHandler[{$this->serviceName}]: cannot rebuild request without a delegated token.",
            );
        }

        return $request->withHeader('Authorization', 'Bearer '.$state['access_token']);
    }

    public function service(): string
    {
        return $this->serviceName;
    }

    /**
     * @return array{access_token: string, refresh_token: string, expires_at: int}|null
     */
    private function state(): ?array
    {
        $state = $this->sessionType === 'oauth'
            ? DelegatedAuthSession::oauth($this->request, $this->serviceName)
            : DelegatedAuthSession::token($this->request, $this->serviceName);

        if ($state === null) {
            return null;
        }

        return [
            'access_token' => $state['access_token'],
            'refresh_token' => is_string($state['refresh_token'] ?? null) ? $state['refresh_token'] : '',
            'expires_at' => is_int($state['expires_at'] ?? null) ? $state['expires_at'] : 0,
        ];
    }

    /**
     * @param array{access_token: string, refresh_token?: string, expires_at?: int} $state
     */
    private function putState(array $state): void
    {
        if ($this->sessionType === 'oauth') {
            DelegatedAuthSession::putOAuth($this->request, $this->serviceName, $state);

            return;
        }

        DelegatedAuthSession::putToken($this->request, $this->serviceName, $state);
    }
}
