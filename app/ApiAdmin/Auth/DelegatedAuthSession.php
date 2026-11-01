<?php

declare(strict_types=1);

namespace App\ApiAdmin\Auth;

use App\ApiAdmin\Tenancy\TenantKeyBuilder;
use Illuminate\Http\Request;

final class DelegatedAuthSession
{
    /**
     * @param array{access_token: string, refresh_token?: string, expires_at?: int} $state
     */
    public static function putToken(Request $request, string $service, array $state): void
    {
        $request->session()->put(self::key($service, 'token'), $state);
    }

    /**
     * @return array{access_token: string, refresh_token: string, expires_at: int}|null
     */
    public static function token(Request $request, string $service): ?array
    {
        return self::state($request, $service, 'token');
    }

    /**
     * @param array{access_token: string, refresh_token?: string, expires_at?: int} $state
     */
    public static function putOAuth(Request $request, string $service, array $state): void
    {
        $request->session()->put(self::key($service, 'oauth'), $state);
    }

    /**
     * @return array{access_token: string, refresh_token?: string, expires_at?: int}|null
     */
    public static function oauth(Request $request, string $service): ?array
    {
        return self::state($request, $service, 'oauth');
    }

    /**
     * @return array{access_token: string, refresh_token: string, expires_at: int}|null
     */
    private static function state(Request $request, string $service, string $type): ?array
    {
        $state = $request->session()->get(self::key($service, $type));

        if (! is_array($state)) {
            return null;
        }

        $token = $state['access_token'] ?? null;

        if (! is_string($token) || $token === '') {
            return null;
        }

        $expiresAt = $state['expires_at'] ?? null;

        if (is_int($expiresAt) && $expiresAt !== 0 && $expiresAt <= time()) {
            return null;
        }

        return [
            'access_token' => $token,
            'refresh_token' => is_string($state['refresh_token'] ?? null) ? $state['refresh_token'] : '',
            'expires_at' => is_int($expiresAt) ? $expiresAt : 0,
        ];
    }

    public static function putSessionState(Request $request, string $service, SessionState $state): void
    {
        $request->session()->put(self::key($service, 'session'), $state->toArray());
    }

    public static function sessionState(Request $request, string $service): ?SessionState
    {
        $state = SessionState::fromArray($request->session()->get(self::key($service, 'session')));

        if (! $state instanceof SessionState || $state->isExpired()) {
            return null;
        }

        return $state;
    }

    public static function forget(Request $request, string $service): void
    {
        $request->session()->forget(self::key($service, 'token'));
        $request->session()->forget(self::key($service, 'oauth'));
        $request->session()->forget(self::key($service, 'session'));
    }

    private static function key(string $service, string $type): string
    {
        return TenantKeyBuilder::sessionKey('delegated-auth', $service, $type);
    }
}
