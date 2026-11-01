<?php

declare(strict_types=1);

namespace App\ApiAdmin\MCP;

use App\ApiAdmin\Tenancy\TenantContext;
use App\Models\ApiAdminMcpToken;

final readonly class HostedMcpAuthentication
{
    public function __construct(
        public ApiAdminMcpToken $token,
        public TenantContext $context,
    ) {}
}
