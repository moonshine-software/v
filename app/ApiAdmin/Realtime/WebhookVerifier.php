<?php

declare(strict_types=1);

namespace App\ApiAdmin\Realtime;

use App\ApiAdmin\ServiceConfig\ServiceConfig;
use App\ApiAdmin\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

final readonly class WebhookVerifier
{
    public function verify(Request $request, ServiceConfig $service): bool
    {
        Log::debug('WebhookVerifier::verify entered', [
            'tenant_id' => $service->tenantId,
            'service' => $service->name,
            'enabled' => $service->realtime->enabled,
            'signature_header' => $service->realtime->webhookSignatureHeader,
            'signature_algo' => $service->realtime->webhookSignatureAlgo,
            'has_webhook_secret' => $service->realtime->webhookSecret !== '',
        ]);

        if (! $service->realtime->enabled) {
            Log::warning('WebhookVerifier::verify rejected disabled realtime service', [
                'tenant_id' => $service->tenantId,
                'service' => $service->name,
            ]);

            return false;
        }

        if ($service->realtime->webhookSecret === '') {
            if ($service->tenantId !== TenantContext::LOCAL_ID || config('api-admin.hosted.mode') === 'hosted') {
                Log::warning('Unsigned webhook rejected', [
                    'tenant_id' => $service->tenantId,
                    'service' => $service->name,
                ]);

                return false;
            }

            Log::debug('WebhookVerifier::verify accepted unsigned webhook because no secret is configured', [
                'tenant_id' => $service->tenantId,
                'service' => $service->name,
            ]);

            return true;
        }

        $signature = $request->headers->get($service->realtime->webhookSignatureHeader, '');
        if (! is_string($signature) || trim($signature) === '') {
            Log::warning('WebhookVerifier::verify missing signature header', [
                'tenant_id' => $service->tenantId,
                'service' => $service->name,
                'signature_header' => $service->realtime->webhookSignatureHeader,
            ]);

            return false;
        }

        $expected = hash_hmac(
            $service->realtime->webhookSignatureAlgo,
            $request->getContent(),
            $service->realtime->webhookSecret,
        );
        $actual = $this->normalizeSignature($signature, $service->realtime->webhookSignatureAlgo);
        $verified = hash_equals($expected, $actual);

        Log::debug('WebhookVerifier::verify completed signature comparison', [
            'tenant_id' => $service->tenantId,
            'service' => $service->name,
            'verified' => $verified,
            'signature_present' => true,
        ]);

        if (! $verified) {
            Log::warning('WebhookVerifier::verify rejected invalid signature', [
                'tenant_id' => $service->tenantId,
                'service' => $service->name,
                'signature_header' => $service->realtime->webhookSignatureHeader,
            ]);
        }

        return $verified;
    }

    private function normalizeSignature(string $signature, string $algo): string
    {
        $signature = trim($signature);
        $prefix = $algo.'=';

        if (str_starts_with($signature, $prefix)) {
            return substr($signature, strlen($prefix));
        }

        return $signature;
    }
}
