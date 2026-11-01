<?php

declare(strict_types=1);

namespace App\ApiAdmin\Tenancy;

interface TenantResolver
{
    public function resolve(): ?TenantContext;
}
