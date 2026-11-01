<?php

declare(strict_types=1);

namespace App\ApiAdmin\MCP;

use App\ApiAdmin\Http\PathSegment;
use App\ApiAdmin\Resources\AbstractRestResource;
use Illuminate\Support\Facades\Log;

/**
 * Binds `{placeholder}` tokens in a relation-resource's endpoint to the
 * parent-context values supplied by the MCP caller.
 *
 * The relation resources generated for `has_many` / `has_one` carry paths
 * like `/posts/{post_id}/comments`. They normally resolve those placeholders
 * inside REST field controllers (the parent is known from the route). For
 * MCP data reads we have no route — the agent must pass `parent_context`
 * explicitly, and we substitute here before AbstractRestResource fires the
 * HTTP request.
 *
 * Returns `{hadPlaceholders: bool, ignoredParentContext: bool}` so the tool
 * layer can emit a warning when the caller supplies `parent_context` on a
 * non-relation resource (placeholders empty) instead of failing.
 */
final class EndpointBinder
{
    /**
     * @param array<string, scalar|null> $parentContext
     *
     * @return array{hadPlaceholders: bool, ignoredParentContext: bool, resolved: string}
     */
    public static function bind(AbstractRestResource $resource, array $parentContext): array
    {
        $endpoint = $resource->getDeclaredEndpoint();
        $placeholders = self::extractPlaceholders($endpoint);

        if ($placeholders === []) {
            $resource->setEndpointOverride(null);

            return [
                'hadPlaceholders' => false,
                'ignoredParentContext' => $parentContext !== [],
                'resolved' => $endpoint,
            ];
        }

        $missing = [];
        $resolved = $endpoint;

        foreach ($placeholders as $name) {
            $value = $parentContext[$name] ?? null;

            if (! is_scalar($value) || (is_string($value) && $value === '')) {
                $missing[] = $name;

                continue;
            }

            $resolved = str_replace('{'.$name.'}', PathSegment::encode((string) $value), $resolved);
        }

        if ($missing !== []) {
            Log::error('EndpointBinder::bind — missing placeholder values', [
                'resource' => $resource::class,
                'endpoint' => $endpoint,
                'missing' => $missing,
            ]);

            throw new UnresolvedPlaceholdersException(
                "Endpoint '{$endpoint}' has unresolved placeholders: ".implode(', ', $missing),
                $missing,
            );
        }

        $resource->setEndpointOverride($resolved);

        Log::debug('EndpointBinder::bind — endpoint resolved', [
            'resource' => $resource::class,
            'declared' => $endpoint,
            'resolved' => $resolved,
        ]);

        return [
            'hadPlaceholders' => true,
            'ignoredParentContext' => false,
            'resolved' => $resolved,
        ];
    }

    /**
     * @return list<string>
     */
    private static function extractPlaceholders(string $endpoint): array
    {
        if (! preg_match_all('/\{([a-zA-Z_]\w*)\}/', $endpoint, $m)) {
            return [];
        }

        return $m[1]
            |> array_unique(...)
            |> array_values(...);
    }
}
