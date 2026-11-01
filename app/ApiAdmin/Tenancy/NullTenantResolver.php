<?php

declare(strict_types=1);

namespace App\ApiAdmin\Tenancy;

use Illuminate\Support\Facades\Log;

final class NullTenantResolver implements TenantResolver
{
    public function resolve(): TenantContext
    {
        $tenant = TenantContext::local();

        Log::debug('TenantResolver: resolved local tenant', [
            'resolver' => self::class,
            'tenant' => $tenant->toLogContext(),
        ]);

        return $tenant;
    }
}
