<?php

declare(strict_types=1);

namespace App\ApiAdmin\Console;

use App\Models\ApiAdminTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use MoonShine\Laravel\Models\MoonshineUser;
use Override;

final class TenantMembershipCommand extends Command
{
    #[Override]
    protected $signature = 'api-admin:tenant-member {tenant : Tenant slug} {user : MoonShine user ID} {--revoke : Remove access}';

    #[Override]
    protected $description = 'Grant or revoke explicit browser membership for a hosted tenant';

    public function handle(): int
    {
        $tenant = ApiAdminTenant::query()->where('slug', $this->argument('tenant'))->first();
        $userId = $this->argument('user');
        if (! $tenant instanceof ApiAdminTenant || ! ctype_digit($userId) || ! MoonshineUser::query()->whereKey($userId)->exists()) {
            $this->error('Tenant or MoonShine user does not exist.');

            return self::FAILURE;
        }
        $key = ['tenant_id' => $tenant->getKey(), 'moonshine_user_id' => (int) $userId];
        if ($this->option('revoke')) {
            DB::table('api_admin_tenant_memberships')->where($key)->delete();
        } else {
            DB::table('api_admin_tenant_memberships')->updateOrInsert($key, ['created_at' => now(), 'updated_at' => now()]);
        }
        $this->info('Tenant browser membership updated.');

        return self::SUCCESS;
    }
}
