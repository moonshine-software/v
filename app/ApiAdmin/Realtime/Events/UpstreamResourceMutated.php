<?php

declare(strict_types=1);

namespace App\ApiAdmin\Realtime\Events;

use App\ApiAdmin\Realtime\UpstreamMutation;
use App\ApiAdmin\Tenancy\TenantContext;
use App\ApiAdmin\Tenancy\TenantKeyBuilder;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

final readonly class UpstreamResourceMutated implements ShouldBroadcastNow
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public UpstreamMutation $mutation,
    ) {}

    /**
     * @return list<Channel>
     */
    public function broadcastOn(): array
    {
        $channel = $this->channelName();
        $payload = $this->broadcastWith();

        Log::debug('UpstreamResourceMutated::broadcastOn preparing broadcast', [
            'tenant_fingerprint' => TenantKeyBuilder::fingerprint($this->mutation->tenantId),
            'channel' => $channel,
            'payload_keys' => array_keys($payload),
        ]);

        $defaultBroadcaster = config('broadcasting.default', 'null');
        if (! is_string($defaultBroadcaster) || $defaultBroadcaster === 'null') {
            Log::warning('UpstreamResourceMutated::broadcastOn broadcasting disabled; using null broadcaster', [
                'tenant_fingerprint' => TenantKeyBuilder::fingerprint($this->mutation->tenantId),
                'channel' => $channel,
            ]);
        }

        return [$this->mutation->tenantId === TenantContext::LOCAL_ID
            ? new Channel($channel)
            : new PrivateChannel($channel)];
    }

    public function broadcastAs(): string
    {
        return 'api-admin.upstream-resource-mutated';
    }

    /**
     * @return array{
     *     service: string,
     *     resource: string,
     *     action: string,
     *     id: string,
     * }
     */
    public function broadcastWith(): array
    {
        return [
            'service' => $this->mutation->service,
            'resource' => $this->mutation->resource,
            'action' => $this->mutation->action,
            'id' => $this->mutation->id,
        ];
    }

    private function channelName(): string
    {
        return TenantKeyBuilder::realtimeChannel(
            $this->mutation->tenantId,
            $this->mutation->service,
            $this->mutation->resource,
        );
    }
}
