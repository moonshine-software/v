<?php

declare(strict_types=1);

namespace App\ApiAdmin\MCP;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Turn HTTP / network exceptions thrown out of the resource layer into the
 * shape MCP tools return for upstream failures.
 *
 * Distinguishes `UPSTREAM_ERROR` (reached the upstream, got ≥400) from
 * `NETWORK_ERROR` (couldn't reach it at all) — agents usually try
 * `probe_endpoint` on the latter and retry on the former.
 */
final class UpstreamErrorResponder
{
    private const int ERROR_BODY_KEY_LIMIT = 50;

    private const int RAW_BODY_BYTE_CAP = 2048;

    /**
     * @return array<string, mixed>
     */
    public static function fromThrowable(Throwable $e): array
    {
        if ($e instanceof ValidationException) {
            return ['success' => false, 'code' => 'VALIDATION', 'error' => $e->getMessage()];
        }

        // Exception messages can contain response bodies or credential-bearing URLs.
        Log::error('[FIX:upstream-error] UpstreamErrorResponder::fromThrowable', [
            'exception' => $e::class,
            'status' => $e instanceof RequestException ? $e->response->status() : null,
        ]);

        if ($e instanceof RequestException) {
            return self::fromRequestException($e);
        }

        if ($e instanceof ConnectionException) {
            return [
                'success' => false,
                'code' => 'NETWORK_ERROR',
                'error' => $e->getMessage(),
                'hint' => 'Upstream unreachable — check base_url and network. Use probe_endpoint to debug connectivity.',
            ];
        }

        return [
            'success' => false,
            'code' => 'UPSTREAM_ERROR',
            'error' => $e->getMessage(),
            'hint' => 'Use probe_endpoint to inspect the raw response.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function fromRequestException(RequestException $e): array
    {
        $response = $e->response;
        $status = $response->status();
        $body = (string) $response->body();
        $json = $response->json();

        $errorBody = is_array($json)
            ? array_slice($json, 0, self::ERROR_BODY_KEY_LIMIT, preserve_keys: true)
            : substr($body, 0, self::RAW_BODY_BYTE_CAP);

        return [
            'success' => false,
            'code' => 'UPSTREAM_ERROR',
            'status' => $status,
            'error' => "Upstream returned HTTP {$status}",
            'error_body' => $errorBody,
            'hint' => 'Use probe_endpoint to inspect the raw response and verify auth / payload shape.',
        ];
    }
}
