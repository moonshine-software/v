<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Integration;

use App\ApiAdmin\Realtime\Events\UpstreamResourceMutated;
use App\ApiAdmin\Realtime\UpstreamMutation;
use App\ApiAdmin\Tenancy\TenantKeyBuilder;
use Illuminate\Support\Facades\Log;
use Orchestra\Testbench\TestCase;

class RealtimeBroadcastTest extends TestCase
{
    public function test_mutation_event_broadcasts_on_service_resource_channel(): void
    {
        $event = new UpstreamResourceMutated(new UpstreamMutation(
            service: 'Blog API',
            resource: 'posts/comments',
            action: 'updated',
            id: '42',
            metadata: ['payload' => ['token' => '[redacted]']],
        ));

        $channels = $event->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertSame('api-admin.blog-api.posts-comments', (string) $channels[0]);
        $this->assertSame('api-admin.upstream-resource-mutated', $event->broadcastAs());
        $this->assertSame([
            'service' => 'Blog API',
            'resource' => 'posts/comments',
            'action' => 'updated',
            'id' => '42',
        ], $event->broadcastWith());
        $this->assertArrayNotHasKey('metadata', $event->broadcastWith());
    }

    public function test_hosted_mutation_event_broadcasts_on_tenant_scoped_channel(): void
    {
        $event = new UpstreamResourceMutated(new UpstreamMutation(
            service: 'blog',
            resource: 'posts',
            action: 'updated',
            id: '42',
            tenantId: 'tenant-a',
        ));

        $channels = $event->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertSame(
            'private-api-admin.tenant.'.TenantKeyBuilder::fingerprint('tenant-a').'.blog.posts',
            (string) $channels[0],
        );
    }

    public function test_dispatch_uses_null_broadcaster_when_broadcasting_is_not_configured(): void
    {
        Log::shouldReceive('debug')->once();
        Log::shouldReceive('warning')
            ->once()
            ->with('UpstreamResourceMutated::broadcastOn broadcasting disabled; using null broadcaster', [
                'tenant_fingerprint' => TenantKeyBuilder::fingerprint('local'),
                'channel' => 'api-admin.blog.posts',
            ]);
        config(['broadcasting.default' => 'null']);

        $event = new UpstreamResourceMutated(new UpstreamMutation(
            service: 'blog',
            resource: 'posts',
            action: 'updated',
            id: '42',
        ));

        $event->broadcastOn();
    }
}
