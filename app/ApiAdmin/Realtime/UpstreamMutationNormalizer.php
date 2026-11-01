<?php

declare(strict_types=1);

namespace App\ApiAdmin\Realtime;

use App\ApiAdmin\ServiceConfig\ServiceConfig;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

final readonly class UpstreamMutationNormalizer
{
    /**
     * @param array<string, mixed> $payload
     */
    public function normalize(ServiceConfig $service, array $payload): UpstreamMutation
    {
        Log::debug('UpstreamMutationNormalizer::normalize entered', [
            'service' => $service->name,
            'payload_keys' => array_keys($payload),
        ]);

        try {
            $resource = $this->stringValue($payload, [
                $service->realtime->webhookResourceKey,
                'resource',
                'model',
                'entity',
            ]);
            $action = $this->normalizeAction($this->stringValue($payload, [
                $service->realtime->webhookActionKey,
                $service->realtime->webhookEventKey,
                'action',
                'event',
                'type',
            ]));
            $id = $this->stringValue($payload, [
                $service->realtime->webhookIdKey,
                'id',
                'uuid',
                'key',
                'data.id',
                'data.uuid',
            ], required: false);

            if ($resource === '') {
                throw new InvalidArgumentException('Webhook payload is missing a resource/model key.');
            }

            if ($action === '') {
                throw new InvalidArgumentException('Webhook payload is missing a supported action/event key.');
            }

            if (! in_array($action, $service->realtime->allowedEvents, true)) {
                Log::warning('UpstreamMutationNormalizer::normalize rejected unsupported event type', [
                    'service' => $service->name,
                    'resource' => $resource,
                    'action' => $action,
                    'allowed_events' => $service->realtime->allowedEvents,
                ]);

                throw new InvalidArgumentException("Unsupported webhook event type [{$action}].");
            }

            $mutation = new UpstreamMutation(
                service: $service->name,
                resource: $resource,
                action: $action,
                id: $id,
                metadata: [
                    'payload' => $this->redactPayload($payload, $service->realtime->sensitivePayloadKeys),
                ],
                tenantId: $service->tenantId,
            );

            Log::info('UpstreamMutationNormalizer::normalize accepted upstream mutation', [
                'service' => $mutation->service,
                'tenant_id' => $mutation->tenantId,
                'resource' => $mutation->resource,
                'action' => $mutation->action,
                'id' => $mutation->id,
            ]);

            Log::debug('UpstreamMutationNormalizer::normalize completed', [
                'service' => $mutation->service,
                'resource' => $mutation->resource,
                'action' => $mutation->action,
                'id_present' => $mutation->id !== '',
            ]);

            return $mutation;
        } catch (InvalidArgumentException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::error('UpstreamMutationNormalizer::normalize failed unexpectedly', [
                'service' => $service->name,
                'error' => $e->getMessage(),
                'exception' => $e::class,
            ]);

            throw new InvalidArgumentException('Webhook payload could not be normalized.', $e->getCode(), previous: $e);
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string> $keys
     */
    private function stringValue(array $payload, array $keys, bool $required = true): string
    {
        foreach ($keys as $key) {
            $value = $this->valueAtPath($payload, $key);
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
            if (is_int($value)) {
                return (string) $value;
            }
        }

        return $required ? '' : '';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function valueAtPath(array $payload, string $path): mixed
    {
        $current = $payload;
        foreach (explode('.', $path) as $segment) {
            if (! is_array($current) || ! array_key_exists($segment, $current)) {
                return null;
            }

            $current = $current[$segment];
        }

        return $current;
    }

    private function normalizeAction(string $action): string
    {
        return match (strtolower(trim($action))) {
            'create', 'created' => 'created',
            'update', 'updated' => 'updated',
            'delete', 'deleted', 'destroy', 'destroyed' => 'deleted',
            'upsert', 'upserted' => 'upserted',
            default => strtolower(trim($action)),
        };
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string> $sensitiveKeys
     *
     * @return array<string, mixed>
     */
    private function redactPayload(array $payload, array $sensitiveKeys): array
    {
        $redacted = [];
        foreach ($payload as $key => $value) {
            if ($this->isSensitive($key, $sensitiveKeys)) {
                $redacted[$key] = '[redacted]';
                continue;
            }

            $redacted[$key] = is_array($value)
                ? $this->redactPayload($this->stringKeyedArray($value), $sensitiveKeys)
                : $value;
        }

        return $redacted;
    }

    /**
     * @param list<string> $sensitiveKeys
     */
    private function isSensitive(string $key, array $sensitiveKeys): bool
    {
        return array_any($sensitiveKeys, fn (string $sensitiveKey): bool => str_contains(strtolower($key), strtolower($sensitiveKey)));
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
}
