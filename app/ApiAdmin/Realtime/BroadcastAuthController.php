<?php

declare(strict_types=1);

namespace App\ApiAdmin\Realtime;

use App\ApiAdmin\Http\RestEndpointAuthorization;
use App\ApiAdmin\Providers\ApiAdminServiceProvider;
use App\ApiAdmin\Tenancy\TenantBrowserAccess;
use App\ApiAdmin\Tenancy\TenantContext;
use App\ApiAdmin\Tenancy\TenantKeyBuilder;
use App\ApiAdmin\Tenancy\TenantResolver;
use App\Models\ApiAdminTenant;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class BroadcastAuthController
{
    public function __invoke(Request $request): mixed
    {
        abort_unless(config('api-admin.hosted.mode') === 'hosted', 404);
        $channel = $request->input('channel_name');
        abort_unless(is_string($channel) && str_starts_with($channel, 'private-api-admin.tenant.'), 403);
        $services = config('api-admin.services', []);
        abort_unless(is_array($services), 403);

        foreach ($services as $name => $service) {
            if (! is_string($name) || ! is_array($service) || ! is_array($service['realtime'] ?? null)
                || ($service['realtime']['enabled'] ?? false) !== true) {
                continue;
            }
            $tenantId = $service['tenant_id'] ?? null;
            if (! is_string($tenantId) || $tenantId === TenantContext::LOCAL_ID) {
                continue;
            }
            $prefix = 'private-'.Str::beforeLast(TenantKeyBuilder::realtimeChannel($tenantId, $name, 'resource'), '.').'.';
            if (! str_starts_with($channel, $prefix) || strlen($channel) <= strlen($prefix)) {
                continue;
            }
            abort_unless(preg_match('/^[a-z0-9_-]+$/D', substr($channel, strlen($prefix))) === 1, 403);

            $tenant = ApiAdminTenant::query()->whereKey($tenantId)->where('active', true)->first();
            abort_unless($tenant instanceof ApiAdminTenant, 403);
            $context = new TenantContext($tenantId, $tenant->slug, $tenant->name, 'hosted', false);
            app()->instance(TenantResolver::class, new readonly class($context) implements TenantResolver
            {
                public function __construct(private TenantContext $context) {}

                public function resolve(): TenantContext
                {
                    return $this->context;
                }
            });

            // Resolve the server-owned tenant before delegated guards read tenant-scoped sessions.
            app(RestEndpointAuthorization::class)->authenticate($request, ApiAdminServiceProvider::resolveService($name, $context));
            Broadcast::channel(substr($channel, 8), static fn (?Authenticatable $user): bool => app(TenantBrowserAccess::class)->allows($user));
            Log::debug('Private subscription authorized', ['service' => $name, 'tenant_id' => $tenantId]);

            return Broadcast::auth($request);
        }

        abort(403);
    }
}
