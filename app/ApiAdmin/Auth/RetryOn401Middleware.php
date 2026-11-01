<?php

declare(strict_types=1);

namespace App\ApiAdmin\Auth;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response as LaravelResponse;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * Single-shot retry middleware for HTTP 401/419 responses.
 *
 * Wraps the Guzzle handler stack: when the inner handler resolves with a 401/419
 * response, calls `$handler->refresh($response)`. If the handler returns true,
 * the request is rebuilt via `$handler->rebuildRequest($request)` and re-sent
 * exactly once. The retry response is returned regardless of its status — no
 * second refresh is attempted, which makes refresh loops structurally impossible.
 *
 * Why a custom middleware and not Guzzle's built-in `Middleware::retry`:
 *   - Built-in retry re-sends the *same* request. Refresh-on-401 needs to swap
 *     auth credentials between attempts, which the built-in cannot do.
 *   - This middleware is response-only — it runs after the inner stack resolves
 *     the request, so the retry path also benefits from the rest of the stack
 *     (logging, cookies, etc.).
 */
final class RetryOn401Middleware
{
    /**
     * Status codes that trigger a single refresh+retry.
     *
     *   401 — token expired / invalid (oauth, bearer)
     *   419 — Laravel/Sanctum CSRF token mismatch (session flow)
     */
    private const array RETRY_STATUSES = [401, 419];

    /**
     * Install the middleware on the given PendingRequest.
     *
     * No-op when handler is null — bearer/header/query/basic/none auth types
     * have no refresh semantics, so installing the middleware would only add
     * latency without doing anything.
     */
    public static function install(PendingRequest $client, ?AuthRetryHandler $handler): PendingRequest
    {
        if (! $handler instanceof AuthRetryHandler) {
            return $client;
        }

        return $client->withMiddleware(static fn (callable $next): callable => static function (RequestInterface $request, array $options) use ($next, $handler): PromiseInterface {
            $promise = self::invokeHandler($next, $request, $options);

            return $promise->then(static function (ResponseInterface $response) use ($next, $request, $options, $handler): PromiseInterface {
                $status = $response->getStatusCode();

                if (! in_array($status, self::RETRY_STATUSES, true)) {
                    return Create::promiseFor($response);
                }

                Log::debug('[Auth.RetryOn401] caught', [
                    'service' => $handler->service(),
                    'status' => $status,
                    'method' => $request->getMethod(),
                    'path' => $request->getUri()->getPath(),
                ]);

                $shouldRetry = $handler->refresh(new LaravelResponse($response), $request);

                if (! $shouldRetry) {
                    Log::warning('[Auth.RetryOn401] refresh refused, surfacing', [
                        'service' => $handler->service(),
                        'status' => $status,
                    ]);

                    return Create::promiseFor($response);
                }

                Log::info('[Auth.RetryOn401] handler refreshed, retrying once', [
                    'service' => $handler->service(),
                    'status' => $status,
                ]);

                return self::invokeHandler($next, $handler->rebuildRequest($request), $options);
            });
        });
    }

    /**
     * Type-narrowing wrapper around a Guzzle handler-stack callable.
     *
     * The callable signature in Guzzle is documented as
     * `fn (RequestInterface, array): PromiseInterface`, but PHP's `callable`
     * pseudo-type erases the return shape, so PHPStan widens the call result
     * to `mixed`. A runtime instanceof check narrows it back without resorting
     * to inline @var or unconditional casts.
     *
     * @param array<array-key, mixed> $options
     */
    private static function invokeHandler(callable $next, RequestInterface $request, array $options): PromiseInterface
    {
        $result = $next($request, $options);

        if (! $result instanceof PromiseInterface) {
            throw new RuntimeException(
                'RetryOn401Middleware: handler stack returned a non-Promise — Guzzle middleware contract violated.',
            );
        }

        return $result;
    }
}
