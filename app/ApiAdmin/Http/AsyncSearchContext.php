<?php

declare(strict_types=1);

namespace App\ApiAdmin\Http;

use App\ApiAdmin\Fields\RestBelongsTo;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/** Authenticated render-time context preserves cascades without trusting URL overrides. */
final class AsyncSearchContext
{
    /** @param array<string, mixed> $context */
    public static function encode(RestBelongsTo $field, array $context): string
    {
        return Crypt::encryptString(json_encode([
            'resource' => $field->getParentResource()?->getUriKey(),
            'relation' => $field->getColumn(),
            'tenant' => $field->getParentResource()?->getServiceConfig()->tenantId,
            'parent_id' => (string) ($field->getParentResource()?->getItemID() ?? ''),
            'expires' => time() + 3600,
            'context' => $context,
        ], JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    public static function decode(RestBelongsTo $field, mixed $token): array
    {
        if ($token === null) {
            return [];
        }
        abort_unless(is_string($token), 422);

        try {
            $payload = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            abort(403, 'Invalid search context');
        }
        abort_unless(is_array($payload)
            && ($payload['resource'] ?? null) === $field->getParentResource()?->getUriKey()
            && ($payload['relation'] ?? null) === $field->getColumn()
            && ($payload['tenant'] ?? null) === $field->getParentResource()?->getServiceConfig()->tenantId
            && ($payload['parent_id'] ?? null) === (string) ($field->getParentResource()?->getItemID() ?? '')
            && is_int($payload['expires'] ?? null) && $payload['expires'] >= time()
            && is_array($payload['context'] ?? null), 403, 'Invalid search context');
        /** @var array<string, mixed> $context */
        $context = $payload['context'];

        return $context;
    }
}
