<?php

declare(strict_types=1);

namespace App\ApiAdmin\Tenancy;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

final class TenantSecretStore
{
    /**
     * @param array<string, string> $secrets
     */
    public function putMany(string $tenantId, string $service, array $secrets): void
    {
        foreach ($secrets as $name => $value) {
            try {
                DB::table('api_admin_tenant_secrets')->updateOrInsert(
                    ['tenant_id' => $tenantId, 'service' => $service, 'name' => $name],
                    ['value' => Crypt::encryptString($value), 'created_at' => now(), 'updated_at' => now()],
                );
                Cache::forget($this->key($tenantId, $service, $name));
            } catch (Throwable $e) {
                Log::error('TenantSecretStore: failed to persist tenant secret', [
                    'tenant_fingerprint' => TenantKeyBuilder::fingerprint($tenantId),
                    'service' => $service,
                    'secret_name' => $name,
                    'exception' => $e::class,
                ]);

                throw new RuntimeException("Failed to persist tenant secret [{$name}].", is_int($e->getCode()) ? $e->getCode() : 0, previous: $e);
            }
        }
    }

    public function get(string $tenantId, string $service, string $name, string $default = ''): string
    {
        try {
            $query = DB::table('api_admin_tenant_secrets')
                ->where('tenant_id', $tenantId)->where('service', $service)->where('name', $name);
            $encrypted = $query->value('value');

            if ($encrypted === null) {
                $legacy = Cache::get($this->key($tenantId, $service, $name));
                if (! is_string($legacy) || $legacy === '') {
                    return $default;
                }
                // Verify legacy ciphertext before persisting it. Concurrent new writes win.
                Crypt::decryptString($legacy);
                DB::table('api_admin_tenant_secrets')->insertOrIgnore([
                    'tenant_id' => $tenantId, 'service' => $service, 'name' => $name,
                    'value' => $legacy, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $encrypted = $query->value('value');
                Cache::forget($this->key($tenantId, $service, $name));
            }
            if (! is_string($encrypted)) {
                throw new RuntimeException('Persisted tenant secret is invalid.');
            }

            return Crypt::decryptString($encrypted);
        } catch (Throwable $e) {
            Log::error('TenantSecretStore: failed to read tenant secret', [
                'tenant_fingerprint' => TenantKeyBuilder::fingerprint($tenantId),
                'service' => $service,
                'secret_name' => $name,
                'exception' => $e::class,
            ]);

            throw new RuntimeException("Failed to read tenant secret [{$name}].", is_int($e->getCode()) ? $e->getCode() : 0, previous: $e);
        }
    }

    private function key(string $tenantId, string $service, string $name): string
    {
        return TenantKeyBuilder::cacheKey($tenantId, 'secret', $service, $name);
    }
}
