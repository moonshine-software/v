<?php

declare(strict_types=1);

namespace App\ApiAdmin\Tenancy;

use App\Models\ApiAdminTenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

final readonly class HttpTenantResolver implements TenantResolver
{
    public function __construct(
        private Request $request,
    ) {}

    public function resolve(): ?TenantContext
    {
        $mode = $this->mode();
        $identifier = $this->tenantIdentifier();

        if ($identifier === null) {
            if ($mode === 'local') {
                $tenant = TenantContext::local();

                Log::debug('TenantResolver: resolved default local tenant', [
                    'resolver' => self::class,
                    'tenant' => $tenant->toLogContext(),
                ]);

                return $tenant;
            }

            Log::warning('TenantResolver: missing tenant identifier', [
                'resolver' => self::class,
                'mode' => $mode,
            ]);

            return null;
        }

        try {
            $tenant = ApiAdminTenant::query()->where('slug', $identifier)->first();

            if (! $tenant instanceof ApiAdminTenant && ctype_digit($identifier)) {
                $tenant = ApiAdminTenant::query()->find((int) $identifier);
            }
        } catch (Throwable $e) {
            Log::error('TenantResolver: failed to look up tenant', [
                'resolver' => self::class,
                'mode' => $mode,
                'tenant_identifier' => $identifier,
                'error' => $e->getMessage(),
                'class' => $e::class,
            ]);

            return null;
        }

        if (! $tenant instanceof ApiAdminTenant) {
            Log::warning('TenantResolver: unknown tenant identifier', [
                'resolver' => self::class,
                'mode' => $mode,
                'tenant_identifier' => $identifier,
            ]);

            return null;
        }

        if (! $tenant->active) {
            Log::warning('TenantResolver: inactive tenant rejected', [
                'resolver' => self::class,
                'mode' => $mode,
                'tenant_id' => $tenant->keyAsString(),
                'tenant_slug' => $tenant->slug,
            ]);

            return null;
        }

        $context = new TenantContext(
            id: $tenant->keyAsString(),
            slug: $tenant->slug,
            name: $tenant->name,
            mode: $mode,
            isLocal: false,
        );

        Log::debug('TenantResolver: resolved hosted tenant', [
            'resolver' => self::class,
            'tenant' => $context->toLogContext(),
        ]);

        return $context;
    }

    private function mode(): string
    {
        $mode = config('api-admin.hosted.mode', 'local');

        return $mode === 'hosted' ? 'hosted' : 'local';
    }

    private function tenantIdentifier(): ?string
    {
        $routeTenant = $this->request->route('tenant');
        if (is_string($routeTenant) && $routeTenant !== '') {
            return $routeTenant;
        }

        $configuredHeader = config('api-admin.hosted.tenant_header', 'X-ApiAdmin-Tenant');
        $headerName = is_string($configuredHeader) && $configuredHeader !== ''
            ? $configuredHeader
            : 'X-ApiAdmin-Tenant';
        $headerTenant = $this->request->header($headerName);
        if (is_string($headerTenant) && $headerTenant !== '') {
            return $headerTenant;
        }

        $queryTenant = $this->request->query('tenant');
        if (is_string($queryTenant) && $queryTenant !== '') {
            return $queryTenant;
        }

        return null;
    }
}
