<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Unit\Realtime;

use App\ApiAdmin\Realtime\WebhookVerifier;
use App\ApiAdmin\ServiceConfig\RealtimeConfig;
use App\ApiAdmin\ServiceConfig\ResponseMapping;
use App\ApiAdmin\ServiceConfig\ServiceConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Orchestra\Testbench\TestCase;

class WebhookVerifierTest extends TestCase
{
    public function test_accepts_enabled_service_without_secret(): void
    {
        $service = $this->service(new RealtimeConfig(enabled: true));
        $request = Request::create('/api-admin/webhooks/blog', 'POST', content: '{"event":"updated"}');

        $this->assertTrue((new WebhookVerifier)->verify($request, $service));
    }

    public function test_rejects_disabled_service(): void
    {
        $service = $this->service(new RealtimeConfig(enabled: false));
        $request = Request::create('/api-admin/webhooks/blog', 'POST', content: '{}');

        $this->assertFalse((new WebhookVerifier)->verify($request, $service));
    }

    public function test_accepts_matching_hmac_signature(): void
    {
        $body = '{"event":"updated","resource":"posts","id":1}';
        $secret = 'webhook-secret';
        $signature = 'sha256='.hash_hmac('sha256', $body, $secret);
        $service = $this->service(new RealtimeConfig(
            enabled: true,
            webhookSecret: $secret,
            webhookSignatureHeader: 'X-Hub-Signature-256',
        ));
        $request = Request::create('/api-admin/webhooks/blog', 'POST', server: [
            'HTTP_X_HUB_SIGNATURE_256' => $signature,
        ], content: $body);

        $this->assertTrue((new WebhookVerifier)->verify($request, $service));
    }

    public function test_rejects_invalid_hmac_signature(): void
    {
        Log::shouldReceive('debug')->twice();
        Log::shouldReceive('warning')
            ->once()
            ->with('WebhookVerifier::verify rejected invalid signature', [
                'tenant_id' => 'local',
                'service' => 'blog',
                'signature_header' => 'X-Hub-Signature-256',
            ]);
        $service = $this->service(new RealtimeConfig(
            enabled: true,
            webhookSecret: 'webhook-secret',
            webhookSignatureHeader: 'X-Hub-Signature-256',
        ));
        $request = Request::create('/api-admin/webhooks/blog', 'POST', server: [
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256=bad',
        ], content: '{"event":"updated"}');

        $this->assertFalse((new WebhookVerifier)->verify($request, $service));
    }

    private function service(RealtimeConfig $realtime): ServiceConfig
    {
        return new ServiceConfig(
            name: 'blog',
            baseUrl: 'https://api.blog.test',
            apiKey: '',
            responseMapping: new ResponseMapping,
            realtime: $realtime,
        );
    }
}
