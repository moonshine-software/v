<?php

declare(strict_types=1);

namespace App\ApiAdmin\Tenancy;

use App\Models\ApiAdminTenant;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use MoonShine\Laravel\Models\MoonshineUser;

final class TenantBrowserAccess
{
    public function allows(?Authenticatable $user): bool
    {
        if (! $user instanceof MoonshineUser) {
            return false;
        }
        if (config('api-admin.hosted.mode', 'local') !== 'hosted') {
            return true;
        }
        $tenant = app()->get(TenantResolver::class)->resolve();
        if ($tenant === null || $tenant->isLocal) {
            return false;
        }

        return ApiAdminTenant::query()->whereKey($tenant->id)->where('active', true)->exists()
            && DB::table('api_admin_tenant_memberships')
                ->where('tenant_id', $tenant->id)
                ->where('moonshine_user_id', $user->getAuthIdentifier())
                ->exists();
    }

    public function authorize(?Authenticatable $user): void
    {
        abort_unless($this->allows($user), 403, 'Tenant membership required.');
    }
}
