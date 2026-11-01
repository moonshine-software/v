<?php

declare(strict_types=1);

namespace App\ApiAdmin\Tenancy;

use Illuminate\Support\Facades\Log;
use RuntimeException;

final class TenantKeyBuilder
{
    public static function cacheKey(string $tenantId, string $domain, string $service, string ...$parts): string
    {
        $segments = ['api-admin'];

        if ($tenantId !== TenantContext::LOCAL_ID) {
            $segments[] = 'tenant';
            $segments[] = self::fingerprint($tenantId);
        }

        $segments[] = self::segment($domain);
        $segments[] = self::segment($service);

        foreach ($parts as $part) {
            $segments[] = self::segment($part);
        }

        $key = implode(':', $segments);
        self::logKey('cache', $tenantId, $domain, $service, $key);

        return $key;
    }

    public static function sessionKey(string $domain, string $service, string ...$parts): string
    {
        $tenant = self::currentTenant();
        $segments = ['api-admin', self::segment($domain)];

        if (! $tenant->isLocal) {
            $segments[] = 'tenant';
            $segments[] = self::fingerprint($tenant->id);
        }

        $segments[] = self::segment($service);

        foreach ($parts as $part) {
            $segments[] = self::segment($part);
        }

        $key = implode('.', $segments);
        self::logKey('session', $tenant->id, $domain, $service, $key);

        return $key;
    }

    public static function realtimeChannel(string $tenantId, string $service, string $resource): string
    {
        $segments = ['api-admin'];

        if ($tenantId !== TenantContext::LOCAL_ID) {
            $segments[] = 'tenant';
            $segments[] = self::fingerprint($tenantId);
        }

        $segments[] = self::segment($service);
        $segments[] = self::segment($resource);

        return implode('.', $segments);
    }

    public static function fingerprint(string $value): string
    {
        return substr(hash('sha256', $value), 0, 12);
    }

    private static function currentTenant(): TenantContext
    {
        if (! app()->bound(TenantResolver::class)) {
            return TenantContext::local();
        }

        $tenant = app()->get(TenantResolver::class)->resolve();

        if ($tenant instanceof TenantContext) {
            return $tenant;
        }

        if (config('api-admin.hosted.mode', 'local') === 'hosted') {
            throw new RuntimeException('Missing tenant context in hosted mode.');
        }

        return TenantContext::local();
    }

    private static function segment(string $value): string
    {
        $segment = preg_replace('/[^A-Za-z0-9_-]+/', '-', $value);
        $segment = is_string($segment) ? trim($segment, '-') : '';

        return $segment !== '' ? strtolower($segment) : 'unknown';
    }

    private static function logKey(string $kind, string $tenantId, string $domain, string $service, string $key): void
    {
        if ($tenantId === TenantContext::LOCAL_ID) {
            return;
        }

        Log::debug('TenantKeyBuilder: built tenant-scoped key', [
            'kind' => $kind,
            'tenant_fingerprint' => self::fingerprint($tenantId),
            'domain' => $domain,
            'service' => $service,
            'key_fingerprint' => self::fingerprint($key),
        ]);
    }
}
