<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Unit\Realtime;

use App\ApiAdmin\Realtime\UpstreamMutationNormalizer;
use App\ApiAdmin\ServiceConfig\RealtimeConfig;
use App\ApiAdmin\ServiceConfig\ResponseMapping;
use App\ApiAdmin\ServiceConfig\ServiceConfig;
use InvalidArgumentException;
use Orchestra\Testbench\TestCase;

class UpstreamMutationNormalizerTest extends TestCase
{
    public function test_normalizes_canonical_payload(): void
    {
        $mutation = (new UpstreamMutationNormalizer)->normalize($this->service(), [
            'event' => 'updated',
            'resource' => 'posts',
            'id' => 42,
            'data' => [
                'title' => 'Updated',
                'password' => 'secret',
            ],
        ]);

        $this->assertSame('blog', $mutation->service);
        $this->assertSame('posts', $mutation->resource);
        $this->assertSame('updated', $mutation->action);
        $this->assertSame('42', $mutation->id);

        $payload = $mutation->metadata['payload'] ?? null;
        if (! is_array($payload)) {
            $this->fail('Expected normalized mutation metadata to include payload array.');
        }

        $data = $payload['data'] ?? null;
        if (! is_array($data)) {
            $this->fail('Expected normalized mutation payload to include data array.');
        }

        $this->assertSame('[redacted]', $data['password'] ?? null);
    }

    public function test_normalizes_common_aliases(): void
    {
        $mutation = (new UpstreamMutationNormalizer)->normalize($this->service(), [
            'type' => 'destroyed',
            'model' => 'comments',
            'data' => [
                'uuid' => 'abc-123',
            ],
        ]);

        $this->assertSame('comments', $mutation->resource);
        $this->assertSame('deleted', $mutation->action);
        $this->assertSame('abc-123', $mutation->id);
    }

    public function test_custom_payload_keys_are_supported(): void
    {
        $service = $this->service(new RealtimeConfig(
            enabled: true,
            webhookEventKey: 'kind',
            webhookResourceKey: 'entity',
            webhookActionKey: 'verb',
            webhookIdKey: 'key',
            allowedEvents: ['upserted'],
        ));

        $mutation = (new UpstreamMutationNormalizer)->normalize($service, [
            'kind' => 'ignored-when-verb-present',
            'entity' => 'products',
            'verb' => 'upsert',
            'key' => 'sku-1',
        ]);

        $this->assertSame('products', $mutation->resource);
        $this->assertSame('upserted', $mutation->action);
        $this->assertSame('sku-1', $mutation->id);
    }

    public function test_rejects_missing_resource(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('resource/model');

        (new UpstreamMutationNormalizer)->normalize($this->service(), [
            'event' => 'updated',
            'id' => 1,
        ]);
    }

    public function test_rejects_unsupported_event(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported webhook event type');

        (new UpstreamMutationNormalizer)->normalize($this->service(), [
            'event' => 'archived',
            'resource' => 'posts',
            'id' => 1,
        ]);
    }

    private function service(?RealtimeConfig $realtime = null): ServiceConfig
    {
        return new ServiceConfig(
            name: 'blog',
            baseUrl: 'https://api.blog.test',
            apiKey: '',
            responseMapping: new ResponseMapping,
            realtime: $realtime ?? new RealtimeConfig(enabled: true),
        );
    }
}
