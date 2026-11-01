<?php

declare(strict_types=1);

namespace App\ApiAdmin\Tenancy;

use Illuminate\Support\Str;

final class TenantRoutes
{
    public static function hostedMode(): bool
    {
        return config('api-admin.hosted.mode', 'local') === 'hosted';
    }

    public static function scopedPrefix(string $prefix): string
    {
        $normalized = trim($prefix, '/');

        if (! self::hostedMode()) {
            return $normalized;
        }

        $tenantPrefix = self::tenantRoutePrefix();

        return $tenantPrefix.($normalized !== '' ? '/'.$normalized : '');
    }

    public static function tenantRoutePrefix(): string
    {
        $prefix = config('api-admin.hosted.route_prefix', 't/{tenant}');

        return is_string($prefix) && trim($prefix, '/') !== ''
            ? trim($prefix, '/')
            : 't/{tenant}';
    }

    public static function expandPrefix(string $prefix, ?TenantContext $tenant = null): string
    {
        $tenantSegment = self::currentTenantSegment($tenant);

        return str_replace('{tenant}', $tenantSegment, trim($prefix, '/'));
    }

    /**
     * @return array{tenant?: string}
     */
    public static function routeParams(?TenantContext $tenant = null): array
    {
        if (! self::hostedMode()) {
            return [];
        }

        return ['tenant' => self::currentTenantSegment($tenant)];
    }

    public static function pathMatchesPrefix(string $prefixPattern, string $path): bool
    {
        $prefixPattern = trim($prefixPattern, '/');
        $path = trim($path, '/');

        if ($prefixPattern === '') {
            return $path === '';
        }

        $pattern = preg_replace('/\\\\\{[^}]+\\\\\}/', '[^/]+', preg_quote($prefixPattern, '/'));
        $pattern = is_string($pattern) ? $pattern : preg_quote($prefixPattern, '/');

        return (bool) preg_match('#^'.$pattern.'(?:/|$)#', $path);
    }

    public static function currentTenantSegment(?TenantContext $tenant = null): string
    {
        $routeTenant = request()->route('tenant');
        if (is_string($routeTenant) && $routeTenant !== '') {
            return $routeTenant;
        }

        if ($tenant instanceof TenantContext && $tenant->slug !== '') {
            return $tenant->slug;
        }

        return Str::slug(TenantContext::LOCAL_ID);
    }
}
