<?php

declare(strict_types=1);

namespace App\ApiAdmin\Console;

use App\Models\ApiAdminTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Override;

/**
 * Operator command for hosted tenants: create, rename, (de)activate and list.
 * Tokens and browser memberships are managed by api-admin:mcp-token and
 * api-admin:tenant-member.
 */
final class TenantCommand extends Command
{
    /** Letters first, so a slug never collides with the numeric tenant ID fallback in URLs. */
    public const string SLUG_PATTERN = '/^[a-z][a-z0-9-]{0,62}$/D';

    #[Override]
    protected $signature = 'api-admin:tenant
        {slug? : Tenant slug used in /t/{slug}/ URLs; omit it to list tenants}
        {--name= : Display name for a new or existing tenant}
        {--deactivate : Block browser and MCP access for the tenant}
        {--activate : Restore access for a deactivated tenant}';

    #[Override]
    protected $description = 'Create, rename, activate, deactivate or list hosted tenants';

    public function handle(): int
    {
        $slug = $this->argument('slug');
        if (! is_string($slug)) {
            return $this->listTenants();
        }

        if (preg_match(self::SLUG_PATTERN, $slug) !== 1) {
            $this->error('Use a slug of lowercase letters, digits and hyphens, starting with a letter (maximum 63 characters).');

            return self::FAILURE;
        }

        $activate = $this->option('activate') === true;
        $deactivate = $this->option('deactivate') === true;
        if ($activate && $deactivate) {
            $this->error('Choose either --activate or --deactivate.');

            return self::FAILURE;
        }

        $name = $this->option('name');
        $name = $name === null ? null : trim($name);
        if ($name !== null && ($name === '' || mb_strlen($name) > 255)) {
            $this->error('The tenant name must contain 1 to 255 characters.');

            return self::FAILURE;
        }

        $tenant = ApiAdminTenant::query()->where('slug', $slug)->first();
        if (! $tenant instanceof ApiAdminTenant) {
            $tenant = ApiAdminTenant::query()->create([
                'slug' => $slug,
                'name' => $name ?? Str::headline($slug),
                'active' => ! $deactivate,
            ]);
            $this->info("Tenant {$slug} created (ID {$tenant->id}).");
        } else {
            if ($name !== null) {
                $tenant->name = $name;
            }
            if ($activate || $deactivate) {
                $tenant->active = $activate;
            }
            if ($tenant->isDirty()) {
                $tenant->save();
                $this->info("Tenant {$slug} updated.");
            } else {
                $this->info("Tenant {$slug} already exists; nothing changed.");
            }
        }

        $this->line('Status: '.($tenant->active ? 'active' : 'inactive'));
        $this->line('Panel: '.self::tenantUrl($slug, 'admin'));
        $this->line('MCP endpoint: '.self::tenantUrl($slug, self::stringConfig('api-admin.hosted.mcp.route', 'api-admin/mcp')));
        if (self::stringConfig('api-admin.hosted.mode', 'local') !== 'hosted') {
            $this->warn('Tenant URLs are served only when API_ADMIN_HOSTED_MODE=hosted.');
        }

        return self::SUCCESS;
    }

    public static function tenantUrl(string $slug, string $path): string
    {
        $prefix = trim(str_replace('{tenant}', $slug, self::stringConfig('api-admin.hosted.route_prefix', 't/{tenant}')), '/');

        return rtrim(self::stringConfig('app.url', 'http://localhost'), '/').'/'.ltrim(($prefix === '' ? '' : $prefix.'/').trim($path, '/'), '/');
    }

    private static function stringConfig(string $key, string $default): string
    {
        $value = config($key, $default);

        return is_string($value) ? $value : $default;
    }

    private function listTenants(): int
    {
        $rows = ApiAdminTenant::query()->orderBy('slug')->get()
            ->map(static fn (ApiAdminTenant $tenant): array => [
                $tenant->id,
                $tenant->slug,
                $tenant->name,
                $tenant->active ? 'active' : 'inactive',
            ])
            ->all();

        if ($rows === []) {
            $this->info('No tenants yet. Create one with: php artisan api-admin:tenant <slug> --name="Display name"');

            return self::SUCCESS;
        }

        $this->table(['ID', 'Slug', 'Name', 'Status'], $rows);

        return self::SUCCESS;
    }
}
