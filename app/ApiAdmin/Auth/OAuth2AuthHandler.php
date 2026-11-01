<?php

declare(strict_types=1);

namespace App\ApiAdmin\Auth;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Throwable;

/**
 * AuthRetryHandler for the three OAuth 2.0 grants
 * (client_credentials, password, refresh_token).
 *
 * On 401 the handler invalidates the cached access token, fetches a fresh one
 * via OAuth2TokenStore::refresh, and swaps the Authorization header on the
 * retried request. A failed refresh (IdP down, client revoked) returns false
 * from refresh() so RetryOn401Middleware surfaces the original 401 instead of
 * looping indefinitely.
 */
final readonly class OAuth2AuthHandler implements AuthRetryHandler
{
    public function __construct(
        private OAuth2TokenStore $tokenStore,
        private string $grant,
        private string $serviceName,
    ) {}

    public function refresh(Response $failed, ?RequestInterface $request = null): bool
    {
        Log::debug('[OAuth2AuthHandler.refresh] invalidating + refreshing', [
            'service' => $this->serviceName,
            'grant' => $this->grant,
            'status' => $failed->status(),
        ]);

        try {
            $authorization = $request?->getHeaderLine('Authorization') ?? '';
            $rejectedToken = str_starts_with($authorization, 'Bearer ') ? substr($authorization, 7) : null;
            $this->tokenStore->refresh($this->grant, $rejectedToken);

            return true;
        } catch (Throwable $e) {
            Log::warning('[OAuth2AuthHandler.refresh] refresh failed, surfacing 401', [
                'service' => $this->serviceName,
                'grant' => $this->grant,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function rebuildRequest(RequestInterface $request): RequestInterface
    {
        $token = $this->tokenStore->getAccessToken($this->grant);

        if ($token === '') {
            throw new RuntimeException(
                "OAuth2AuthHandler[{$this->serviceName}]: rebuildRequest called but token store returned an empty token after refresh.",
            );
        }

        return $request->withHeader('Authorization', 'Bearer '.$token);
    }

    public function service(): string
    {
        return $this->serviceName;
    }
}
