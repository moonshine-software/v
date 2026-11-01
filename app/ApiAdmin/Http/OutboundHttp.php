<?php

declare(strict_types=1);

namespace App\ApiAdmin\Http;

use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

final class OutboundHttp
{
    public static function client(string $baseUrl): PendingRequest
    {
        return Http::baseUrl($baseUrl)->withoutRedirecting()->withMiddleware(
            static fn (callable $handler): Closure => self::guardedHandler($handler, $baseUrl),
        );
    }

    /**
     * @param callable(RequestInterface, array<array-key, mixed>): PromiseInterface $handler
     *
     * @return Closure(RequestInterface, array<array-key, mixed>): PromiseInterface
     */
    private static function guardedHandler(callable $handler, string $baseUrl): Closure
    {
        /** @param array<array-key, mixed> $options */
        $guard = static function (RequestInterface $request, array $options) use ($handler, $baseUrl): PromiseInterface {
            $origin = self::origin($baseUrl);
            $uri = $request->getUri();
            if (self::origin((string) $uri) !== $origin) {
                Log::warning('Rejected origin change');

                throw new RuntimeException('Upstream requests must stay on the configured origin.');
            }

            $options['allow_redirects'] = false;
            $options['verify'] = true;
            $options['proxy'] = '';
            if (config('api-admin.hosted.mode') === 'hosted' && config('api-admin.http.allow_private_addresses', false) !== true) {
                $host = trim($uri->getHost(), '[]');
                $addresses = app(HostResolver::class)->resolve($host);
                foreach ($addresses as $address) {
                    if (! self::isPublicAddress($address)) {
                        Log::warning('Rejected nonpublic destination', ['host' => $host]);

                        throw new RuntimeException('Hosted upstream destinations must resolve to public IP addresses.');
                    }
                }

                // Pin the validated address for this request: a second DNS lookup
                // by cURL would reintroduce DNS rebinding between check and connect.
                $address = $addresses[0];
                $port = $uri->getPort() ?? ($uri->getScheme() === 'https' ? 443 : 80);
                $pinned = str_contains($address, ':') ? '['.$address.']' : $address;
                $options['curl'] = [CURLOPT_PROXY => ''];
                if (filter_var($host, FILTER_VALIDATE_IP) === false) {
                    $options['curl'][CURLOPT_RESOLVE] = ["{$host}:{$port}:{$pinned}"];
                }
            }

            return $handler($request, $options);
        };

        return $guard;
    }

    private static function isPublicAddress(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) === false) {
            return false;
        }

        // NAT64 can translate an apparently global IPv6 destination into a
        // private IPv4 address. Disallow both standardized translation prefixes.
        $packed = inet_pton($address);

        return $packed !== false && (strlen($packed) !== 16 || ! str_starts_with($packed, "\x00\x64\xff\x9b".str_repeat("\x00", 8)) && ! str_starts_with($packed, "\x00\x64\xff\x9b\x00\x01"));
    }

    private static function origin(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['host'], $parts['scheme'])
            || ! in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass'])
            || preg_match('/[\x00-\x20\\\\]/', $url) === 1) {
            throw new RuntimeException('Upstream URL must be HTTP(S) without credentials or control characters.');
        }

        $scheme = strtolower($parts['scheme']);

        return $scheme.'://'.strtolower($parts['host']).':'.($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
    }
}
