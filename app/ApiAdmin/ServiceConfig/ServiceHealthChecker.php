<?php

declare(strict_types=1);

namespace App\ApiAdmin\ServiceConfig;

use App\ApiAdmin\Http\OutboundHttp;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pings a service's health endpoint and returns a lightweight status snapshot.
 *
 * Results are cached briefly so that repeated renders (e.g. the admin hub) do not
 * add up to a visible latency cost.
 */
final readonly class ServiceHealthChecker
{
    public const string STATUS_OK = 'ok';

    public const string STATUS_DEGRADED = 'degraded';

    public const string STATUS_DOWN = 'down';

    public function __construct(
        private ?CacheRepository $cache = null,
        private float $timeout = 1.5,
        private int $ttlSeconds = 30,
    ) {}

    /**
     * @return array{status: self::STATUS_*, code: int|null, url: string}
     */
    public function check(ServiceConfig $service): array
    {
        $url = $this->resolveUrl($service);
        $cache = $this->cache ?? Cache::store();
        $cacheKey = 'api-admin:health:'.$service->tenantId.':'.$service->name;

        Log::info('ServiceHealthChecker: health check started', [
            'tenant_id' => $service->tenantId,
            'service' => $service->name,
            'url' => $url,
        ]);

        /** @var array{status: self::STATUS_*, code: int|null, url: string}|null $cached */
        $cached = $cache->get($cacheKey);
        if ($cached !== null) {
            Log::debug('ServiceHealthChecker: returned cached result', [
                'tenant_id' => $service->tenantId,
                'service' => $service->name,
                'status' => $cached['status'],
            ]);

            return $cached;
        }

        $result = $this->probe($service, $url);
        $cache->put($cacheKey, $result, $this->ttlSeconds);

        Log::info('ServiceHealthChecker: health check completed', [
            'tenant_id' => $service->tenantId,
            'service' => $service->name,
            'status' => $result['status'],
            'code' => $result['code'],
        ]);

        return $result;
    }

    /**
     * @return array{status: self::STATUS_*, code: int|null, url: string}
     */
    private function probe(ServiceConfig $service, string $url): array
    {
        try {
            Log::debug('ServiceHealthChecker: probing upstream health endpoint', [
                'tenant_id' => $service->tenantId,
                'service' => $service->name,
                'url' => $url,
            ]);

            // Panels with their own login have no service credentials to send:
            // resolving them would attempt an upstream login and report a false outage.
            $hasServiceCredentials = ! $service->adminLogin && $service->adminAuth->mode === AdminAuthConfig::MODE_MOONSHINE;
            $response = OutboundHttp::client($service->baseUrl)->timeout($this->timeout)
                ->withHeaders($hasServiceCredentials ? $service->authHeaders() : [])
                ->withQueryParameters($hasServiceCredentials ? $service->authQuery() : [])
                ->get($url);

            $code = $response->status();
            $status = match (true) {
                $response->successful() => self::STATUS_OK,
                $code >= 500 => self::STATUS_DOWN,
                default => self::STATUS_DEGRADED,
            };

            return ['status' => $status, 'code' => $code, 'url' => $url];
        } catch (ConnectionException $e) {
            Log::debug('ServiceHealthChecker: connection failed', [
                'tenant_id' => $service->tenantId,
                'service' => $service->name,
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return ['status' => self::STATUS_DOWN, 'code' => null, 'url' => $url];
        } catch (Throwable $e) {
            Log::error('ServiceHealthChecker: unexpected error', [
                'tenant_id' => $service->tenantId,
                'service' => $service->name,
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return ['status' => self::STATUS_DOWN, 'code' => null, 'url' => $url];
        }
    }

    private function resolveUrl(ServiceConfig $service): string
    {
        $healthPath = $service->healthPath;

        // Absolute URLs win as-is. This covers the common split-deployment
        // shape where base_url is "/api" but the health endpoint lives at
        // the host root (e.g. Laravel's default "/up"). Without this path,
        // agents had to hack relative paths like "/../up".
        if (preg_match('#^https?://#i', $healthPath) === 1) {
            return $healthPath;
        }

        $base = rtrim($service->baseUrl, '/');
        $path = ltrim($healthPath, '/');

        return $path === '' ? $base : $base.'/'.$path;
    }
}
