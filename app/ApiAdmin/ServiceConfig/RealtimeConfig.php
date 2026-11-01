<?php

declare(strict_types=1);

namespace App\ApiAdmin\ServiceConfig;

use Illuminate\Support\Facades\Log;

/**
 * DTO for per-service upstream realtime/webhook settings.
 */
final readonly class RealtimeConfig
{
    /**
     * @param list<string> $allowedEvents
     * @param list<string> $sensitivePayloadKeys
     */
    public function __construct(
        public bool $enabled = false,
        public string $webhookSecret = '',
        public string $webhookSignatureHeader = 'X-ApiAdmin-Signature',
        public string $webhookSignatureAlgo = 'sha256',
        public string $webhookEventKey = 'event',
        public string $webhookResourceKey = 'resource',
        public string $webhookActionKey = 'action',
        public string $webhookIdKey = 'id',
        public array $allowedEvents = ['created', 'updated', 'deleted', 'upserted'],
        public int $refreshDebounceMs = 750,
        public string $refreshStrategy = 'auto',
        public array $sensitivePayloadKeys = ['password', 'token', 'secret', 'api_key', 'authorization'],
    ) {}

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config, string $service = ''): self
    {
        $str = static fn (string $key, string $default): string => is_string($config[$key] ?? null) ? trim($config[$key]) : $default;
        $enabled = ($config['enabled'] ?? false) === true;
        $algo = $str('webhook_signature_algo', 'sha256')
            |> strtolower(...);
        if (! in_array($algo, hash_hmac_algos(), true)) {
            Log::warning('RealtimeConfig::fromArray invalid signature algorithm', [
                'service' => $service,
                'algorithm' => $algo,
            ]);
            $algo = 'sha256';
        }

        $strategy = $str('refresh_strategy', 'auto');
        if (! in_array($strategy, ['auto', 'notice', 'disabled'], true)) {
            Log::warning('RealtimeConfig::fromArray invalid refresh strategy', [
                'service' => $service,
                'strategy' => $strategy,
            ]);
            $strategy = 'auto';
        }

        $allowedEvents = self::stringList($config['allowed_events'] ?? null);
        if ($allowedEvents === []) {
            $allowedEvents = ['created', 'updated', 'deleted', 'upserted'];
        }

        $sensitivePayloadKeys = self::stringList($config['sensitive_payload_keys'] ?? null);
        if ($sensitivePayloadKeys === []) {
            $sensitivePayloadKeys = ['password', 'token', 'secret', 'api_key', 'authorization'];
        }

        $refreshDebounceMs = self::positiveInt($config['refresh_debounce_ms'] ?? null, 750);

        $normalized = new self(
            enabled: $enabled,
            webhookSecret: $str('webhook_secret', ''),
            webhookSignatureHeader: $str('webhook_signature_header', 'X-ApiAdmin-Signature') !== '' ? $str('webhook_signature_header', 'X-ApiAdmin-Signature') : 'X-ApiAdmin-Signature',
            webhookSignatureAlgo: $algo,
            webhookEventKey: $str('webhook_event_key', 'event') !== '' ? $str('webhook_event_key', 'event') : 'event',
            webhookResourceKey: $str('webhook_resource_key', 'resource') !== '' ? $str('webhook_resource_key', 'resource') : 'resource',
            webhookActionKey: $str('webhook_action_key', 'action') !== '' ? $str('webhook_action_key', 'action') : 'action',
            webhookIdKey: $str('webhook_id_key', 'id') !== '' ? $str('webhook_id_key', 'id') : 'id',
            allowedEvents: $allowedEvents,
            refreshDebounceMs: $refreshDebounceMs,
            refreshStrategy: $strategy,
            sensitivePayloadKeys: $sensitivePayloadKeys,
        );

        Log::debug('RealtimeConfig::fromArray normalized realtime config', [
            'service' => $service,
            'enabled' => $normalized->enabled,
            'signature_header' => $normalized->webhookSignatureHeader,
            'signature_algo' => $normalized->webhookSignatureAlgo,
            'allowed_events' => $normalized->allowedEvents,
            'refresh_debounce_ms' => $normalized->refreshDebounceMs,
            'refresh_strategy' => $normalized->refreshStrategy,
            'has_webhook_secret' => $normalized->webhookSecret !== '',
        ]);

        if ($normalized->enabled) {
            Log::info('RealtimeConfig::fromArray realtime enabled for service', [
                'service' => $service,
                'allowed_events' => $normalized->allowedEvents,
                'refresh_strategy' => $normalized->refreshStrategy,
            ]);
        }

        if ($normalized->enabled && $normalized->webhookSecret === '') {
            Log::warning('RealtimeConfig::fromArray realtime enabled without webhook secret', [
                'service' => $service,
            ]);
        }

        return $normalized;
    }

    /**
     * @return array{
     *     enabled: bool,
     *     webhook_secret: string,
     *     webhook_signature_header: string,
     *     webhook_signature_algo: string,
     *     webhook_event_key: string,
     *     webhook_resource_key: string,
     *     webhook_action_key: string,
     *     webhook_id_key: string,
     *     allowed_events: list<string>,
     *     refresh_debounce_ms: int,
     *     refresh_strategy: string,
     *     sensitive_payload_keys: list<string>,
     * }
     */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'webhook_secret' => $this->webhookSecret,
            'webhook_signature_header' => $this->webhookSignatureHeader,
            'webhook_signature_algo' => $this->webhookSignatureAlgo,
            'webhook_event_key' => $this->webhookEventKey,
            'webhook_resource_key' => $this->webhookResourceKey,
            'webhook_action_key' => $this->webhookActionKey,
            'webhook_id_key' => $this->webhookIdKey,
            'allowed_events' => $this->allowedEvents,
            'refresh_debounce_ms' => $this->refreshDebounceMs,
            'refresh_strategy' => $this->refreshStrategy,
            'sensitive_payload_keys' => $this->sensitivePayloadKeys,
        ];
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            if (! is_string($item)) {
                continue;
            }
            $item = trim($item);
            if ($item !== '') {
                $items[] = $item;
            }
        }

        return $items
            |> array_unique(...)
            |> array_values(...);
    }

    private static function positiveInt(mixed $value, int $default): int
    {
        $int = is_int($value) ? $value : (is_numeric($value) ? (int) $value : $default);

        return $int > 0 ? $int : $default;
    }
}
