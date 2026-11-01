<?php

declare(strict_types=1);

namespace App\ApiAdmin\Realtime;

use App\ApiAdmin\Providers\ApiAdminServiceProvider;
use App\ApiAdmin\Realtime\Events\UpstreamResourceMutated;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

final readonly class WebhookController
{
    public function __construct(
        private WebhookVerifier $verifier,
        private UpstreamMutationNormalizer $normalizer,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $service = $request->route('service');
        abort_unless(is_string($service) && $service !== '', 404);
        $requestId = $request->headers->get('X-Request-Id', '');
        $requestId = is_string($requestId) ? $requestId : '';

        Log::debug('WebhookController::__invoke route entry', [
            'service' => $service,
            'request_id' => $requestId,
        ]);

        try {
            $serviceConfig = ApiAdminServiceProvider::resolveService($service);
        } catch (RuntimeException $e) {
            Log::warning('WebhookController::__invoke rejected unknown service', [
                'service' => $service,
                'message' => $e->getMessage(),
                'status' => 404,
            ]);

            return $this->jsonError('Unknown API admin service.', 404);
        }

        if (! $this->verifier->verify($request, $serviceConfig)) {
            Log::warning('WebhookController::__invoke rejected unauthorized webhook', [
                'tenant_id' => $serviceConfig->tenantId,
                'service' => $service,
                'realtime_enabled' => $serviceConfig->realtime->enabled,
                'status' => 403,
            ]);

            return $this->jsonError('Webhook rejected.', 403);
        }

        try {
            $payload = $this->decodePayload($request);
            $mutation = $this->normalizer->normalize($serviceConfig, $payload);
        } catch (InvalidArgumentException $e) {
            Log::warning('WebhookController::__invoke rejected malformed webhook payload', [
                'service' => $service,
                'message' => $e->getMessage(),
                'status' => 422,
            ]);

            return $this->jsonError($e->getMessage(), 422);
        }

        UpstreamResourceMutated::dispatch($mutation);

        Log::info('WebhookController::__invoke dispatched upstream mutation event', [
            'tenant_id' => $mutation->tenantId,
            'service' => $mutation->service,
            'resource' => $mutation->resource,
            'action' => $mutation->action,
            'id' => $mutation->id,
            'status' => 200,
        ]);

        return response()->json([
            'success' => true,
            'mutation' => $mutation->toArray(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodePayload(Request $request): array
    {
        $content = trim($request->getContent());
        if ($content === '') {
            throw new InvalidArgumentException('Webhook payload is empty.');
        }

        $decoded = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
            throw new InvalidArgumentException('Webhook payload must be a JSON object.');
        }

        return $this->stringKeyedArray($decoded);
    }

    /**
     * @param array<mixed> $value
     *
     * @return array<string, mixed>
     */
    private function stringKeyedArray(array $value): array
    {
        $result = [];
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $result[$key] = $item;
            }
        }

        return $result;
    }

    private function jsonError(string $message, int $status): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], $status);
    }
}
