<?php

declare(strict_types=1);

namespace App\ApiAdmin\Console;

use App\ApiAdmin\Tenancy\TenantContext;
use App\ApiAdmin\Tenancy\TenantSecretStore;
use Illuminate\Console\Command;
use Override;

final class MigrateTenantSecretsCommand extends Command
{
    #[Override]
    protected $signature = 'api-admin:migrate-tenant-secrets';

    #[Override]
    protected $description = 'Move legacy configured tenant secrets from cache into encrypted database storage';

    public function handle(TenantSecretStore $store): int
    {
        $services = config('api-admin.services', []);
        if (! is_array($services)) {
            $this->error('Invalid service configuration.');

            return self::FAILURE;
        }
        $count = 0;
        foreach ($services as $name => $service) {
            if (! is_string($name)) {
                continue;
            }
            if (! is_array($service)) {
                continue;
            }
            $tenantId = $service['tenant_id'] ?? TenantContext::LOCAL_ID;
            if (! is_string($tenantId)) {
                continue;
            }
            if ($tenantId === TenantContext::LOCAL_ID) {
                continue;
            }
            foreach (['api_key', 'oauth_client_secret', 'oauth_password', 'oauth_refresh_token', 'session_password', 'webhook_secret'] as $secret) {
                $store->get($tenantId, $name, $secret);
            }
            $count++;
        }
        $this->info("Checked {$count} hosted services. Available legacy secrets are now stored in the database.");

        return self::SUCCESS;
    }
}
