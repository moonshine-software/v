<?php

declare(strict_types=1);

namespace App\ApiAdmin\Auth;

use Illuminate\Http\Client\Response;
use Psr\Http\Message\RequestInterface;

/**
 * Refresh-and-retry hook for HTTP auth schemes that can recover from a 401/419.
 *
 * Implementations:
 *   - OAuth2AuthHandler — invalidates the cached access token and forces a re-fetch
 *     before swapping the Authorization header on the retried request.
 *   - SessionAuthHandler — drops the cached cookie/CSRF state, performs a fresh
 *     login, and re-attaches the new cookie + CSRF header to the retried request.
 *
 * The middleware (RetryOn401Middleware) guarantees a single retry per request, so
 * implementations cannot accidentally cause refresh loops.
 */
interface AuthRetryHandler
{
    /**
     * Decide whether refresh + retry should happen for this failed response.
     *
     * Implementations may perform side effects here: invalidate cached tokens,
     * call IdP / login endpoint, persist new state to cache.
     *
     * Return true to authorize a single retry with the new credentials.
     * Return false to surface the original failure unchanged (e.g. when a
     * refresh attempt itself fails — better to fail fast than loop).
     */
    public function refresh(Response $failed, ?RequestInterface $request = null): bool;

    /**
     * Mutate the PSR-7 request before the retry is dispatched.
     *
     * Typically swaps the Authorization header (oauth) or attaches the new
     * Cookie + CSRF header (session). The middleware passes the *original*
     * request — implementations should clone-with-headers, not return a wholly
     * new request, so non-auth headers (Content-Type, body) are preserved.
     */
    public function rebuildRequest(RequestInterface $request): RequestInterface;

    /**
     * Service name for log correlation. Multiple services share the middleware
     * pool, so logs without this become useless.
     */
    public function service(): string;
}
