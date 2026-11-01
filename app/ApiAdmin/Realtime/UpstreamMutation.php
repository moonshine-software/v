<?php

declare(strict_types=1);

namespace App\ApiAdmin\Realtime;

use App\ApiAdmin\Tenancy\TenantContext;

/**
 * Normalized upstream mutation event used by webhook ingest and refresh routing.
 *
 * @param array<string, mixed> $metadata
 */
final readonly class UpstreamMutation
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $service,
        public string $resource,
        public string $action,
        public string $id = '',
        public array $metadata = [],
        public string $tenantId = TenantContext::LOCAL_ID,
    ) {}

    /**
     * @return array{
     *     service: string,
     *     resource: string,
     *     action: string,
     *     id: string,
     *     tenant_id: string,
     *     metadata: array<string, mixed>,
     * }
     */
    public function toArray(): array
    {
        return [
            'service' => $this->service,
            'resource' => $this->resource,
            'action' => $this->action,
            'id' => $this->id,
            'tenant_id' => $this->tenantId,
            'metadata' => $this->metadata,
        ];
    }
}
