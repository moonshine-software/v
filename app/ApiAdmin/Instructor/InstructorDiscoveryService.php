<?php

declare(strict_types=1);

namespace App\ApiAdmin\Instructor;

use App\ApiAdmin\Http\OutboundHttp;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

final readonly class InstructorDiscoveryService
{
    private const int SAMPLE_LIMIT = 3;

    /**
     * @param list<string> $paths
     * @param array<string, mixed> $auth
     *
     * @return array{success: bool, base_url: string, probes: list<array<string, mixed>>, warnings: list<string>}
     */
    public function discover(string $baseUrl, array $paths, array $auth = []): array
    {
        $baseUrl = rtrim(trim($baseUrl), '/');
        $candidatePaths = $this->candidatePaths($paths);

        Log::info('InstructorDiscoveryService: discovery started', [
            'base_host' => parse_url($baseUrl, PHP_URL_HOST),
            'candidate_count' => count($candidatePaths),
            'auth_type' => $auth['auth_type'] ?? 'none',
        ]);

        if (! filter_var($baseUrl, FILTER_VALIDATE_URL)) {
            Log::warning('InstructorDiscoveryService: invalid base URL', ['base_url' => $baseUrl]);

            return [
                'success' => false,
                'base_url' => $baseUrl,
                'probes' => [],
                'warnings' => ['Enter a valid absolute API base URL.'],
            ];
        }

        $probes = [];
        $warnings = [];

        foreach ($candidatePaths as $path) {
            $probe = $this->probe($baseUrl, $path, $auth);
            $probes[] = $probe;

            $status = is_int($probe['status'] ?? null) ? $probe['status'] : null;
            Log::debug('InstructorDiscoveryService: path probed', [
                'path' => $path,
                'status' => $status,
                'timings_ms' => $probe['timings_ms'] ?? null,
                'guessed_format' => $probe['guessed_format'] ?? null,
                'detected_columns' => $probe['detected_columns'] ?? [],
            ]);

            if (($probe['success'] ?? false) !== true) {
                $message = $probe['error'] ?? null;
                if (is_string($message) && $message !== '') {
                    $warnings[] = $path.': '.$message;
                }
            }
        }

        $successful = array_filter($probes, static fn (array $probe): bool => ($probe['success'] ?? false) === true);

        if ($successful === []) {
            $warnings[] = 'No candidate endpoint returned a JSON list or object that can be mapped.';
            Log::warning('InstructorDiscoveryService: no usable probes', [
                'base_host' => parse_url($baseUrl, PHP_URL_HOST),
                'candidate_count' => count($candidatePaths),
            ]);
        }

        Log::info('InstructorDiscoveryService: discovery completed', [
            'base_host' => parse_url($baseUrl, PHP_URL_HOST),
            'successful_probes' => count($successful),
            'warnings' => count($warnings),
        ]);

        return [
            'success' => $successful !== [],
            'base_url' => $baseUrl,
            'probes' => $probes,
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /**
     * @param list<string> $paths
     *
     * @return list<string>
     */
    private function candidatePaths(array $paths): array
    {
        $normalized = [];

        foreach ($paths as $path) {
            $path = trim($path);
            if ($path === '') {
                continue;
            }

            $normalized[] = str_starts_with($path, '/') ? $path : '/'.$path;
        }

        if ($normalized === []) {
            $fallbackEnabled = config('api-admin.instructor.fallback_probes', true);
            if ($fallbackEnabled === true) {
                $normalized = ['/users', '/posts', '/products', '/health'];
            }
        }

        return $normalized
            |> array_unique(...)
            |> array_values(...);
    }

    /**
     * @param array<string, mixed> $auth
     *
     * @return array<string, mixed>
     */
    private function probe(string $baseUrl, string $path, array $auth): array
    {
        $startedAt = microtime(true);

        try {
            $request = OutboundHttp::client($baseUrl)
                ->connectTimeout(3)
                ->timeout(10)
                ->acceptJson();

            $authType = is_string($auth['auth_type'] ?? null) ? $auth['auth_type'] : 'none';
            $apiKey = is_string($auth['api_key'] ?? null) ? $auth['api_key'] : '';

            if ($authType === 'bearer' && $apiKey !== '') {
                $request = $request->withToken($apiKey);
            } elseif ($authType === 'header' && $apiKey !== '') {
                $header = is_string($auth['auth_header'] ?? null) && $auth['auth_header'] !== ''
                    ? $auth['auth_header']
                    : 'Authorization';
                $request = $request->withHeaders([$header => $apiKey]);
            } elseif ($authType === 'query' && $apiKey !== '') {
                $request = $request->withQueryParameters(['api_key' => $apiKey]);
            } elseif ($authType === 'basic' && $apiKey !== '' && str_contains($apiKey, ':')) {
                [$user, $password] = explode(':', $apiKey, 2);
                $request = $request->withBasicAuth($user, $password);
            }

            $response = $request->get($path);
        } catch (Throwable $e) {
            Log::warning('InstructorDiscoveryService: probe failed', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'path' => $path,
                'status' => null,
                'timings_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'error' => 'HTTP request failed: '.$e->getMessage(),
            ];
        }

        return $this->summarizeResponse($path, $response, (int) round((microtime(true) - $startedAt) * 1000));
    }

    /**
     * @return array<string, mixed>
     */
    private function summarizeResponse(string $path, Response $response, int $timingsMs): array
    {
        $status = $response->status();
        $body = $response->json();

        if (! is_array($body)) {
            return [
                'success' => false,
                'path' => $path,
                'status' => $status,
                'timings_ms' => $timingsMs,
                'error' => 'Response is not JSON.',
            ];
        }

        [$format, $mapping] = $this->guessMapping($body);
        $columns = $this->detectColumns($body, is_array($mapping) ? ($mapping['data_key'] ?? null) : null);

        return [
            'success' => $response->successful(),
            'path' => $path,
            'status' => $status,
            'timings_ms' => $timingsMs,
            'guessed_format' => $format,
            'guessed_mapping' => $mapping,
            'detected_columns' => $columns,
            'sample_json' => $this->sample($body, is_array($mapping) ? ($mapping['data_key'] ?? null) : null),
            'hints' => $this->hints($status, $columns, $format),
        ];
    }

    /**
     * @param array<mixed> $body
     *
     * @return array{0: string|null, 1: array<string, string>|null}
     */
    private function guessMapping(array $body): array
    {
        if (array_is_list($body)) {
            return ['flat', ['data_key' => '', 'primary_key' => $this->guessPrimaryKey($body[0] ?? null)]];
        }

        if ($this->hasAll($body, ['data', 'total', 'per_page', 'current_page', 'last_page'])) {
            $items = is_array($body['data'] ?? null) ? $body['data'] : [];

            return ['laravel', [
                'data_key' => 'data',
                'total_key' => 'total',
                'per_page_key' => 'per_page',
                'current_page_key' => 'current_page',
                'last_page_key' => 'last_page',
                'primary_key' => $this->guessPrimaryKey($items[0] ?? null),
            ]];
        }

        if (isset($body['results'], $body['count'])) {
            $items = is_array($body['results']) ? $body['results'] : [];

            return ['django', [
                'data_key' => 'results',
                'total_key' => 'count',
                'per_page_key' => 'page_size',
                'current_page_key' => 'current_page',
                'last_page_key' => 'last_page',
                'primary_key' => $this->guessPrimaryKey($items[0] ?? null),
            ]];
        }

        foreach ($body as $key => $value) {
            if (is_string($key) && is_array($value) && array_is_list($value)) {
                return ['custom', ['data_key' => $key, 'primary_key' => $this->guessPrimaryKey($value[0] ?? null)]];
            }
        }

        return ['custom', ['data_key' => '', 'primary_key' => $this->guessPrimaryKey($body)]];
    }

    /**
     * @param array<mixed> $body
     * @param list<string> $keys
     */
    private function hasAll(array $body, array $keys): bool
    {
        return array_all($keys, fn (string $key): bool => array_key_exists($key, $body));
    }

    private function guessPrimaryKey(mixed $item): string
    {
        if (! is_array($item)) {
            return 'id';
        }

        foreach (['id', 'uuid', 'key'] as $key) {
            if (array_key_exists($key, $item)) {
                return $key;
            }
        }

        return 'id';
    }

    /**
     * @param array<mixed> $body
     *
     * @return list<string>
     */
    private function detectColumns(array $body, ?string $dataKey): array
    {
        $candidate = null;

        if ($dataKey !== null && $dataKey !== '' && isset($body[$dataKey]) && is_array($body[$dataKey])) {
            $items = $body[$dataKey];
            $candidate = is_array($items[0] ?? null) ? $items[0] : null;
        } elseif (array_is_list($body)) {
            $candidate = is_array($body[0] ?? null) ? $body[0] : null;
        }

        if (! is_array($candidate)) {
            foreach ($body as $value) {
                if (is_array($value) && array_is_list($value) && is_array($value[0] ?? null)) {
                    $candidate = $value[0];
                    break;
                }
            }
        }

        if (! is_array($candidate)) {
            $candidate = $body;
        }

        return array_filter(array_keys($candidate), is_string(...))
            |> array_values(...);
    }

    /**
     * @param array<mixed> $body
     */
    private function sample(array $body, ?string $dataKey): mixed
    {
        if ($dataKey !== null && $dataKey !== '' && isset($body[$dataKey]) && is_array($body[$dataKey])) {
            return [$dataKey => array_slice($body[$dataKey], 0, self::SAMPLE_LIMIT)];
        }

        if (array_is_list($body)) {
            return array_slice($body, 0, self::SAMPLE_LIMIT);
        }

        return $body;
    }

    /**
     * @param list<string> $columns
     *
     * @return list<string>
     */
    private function hints(int $status, array $columns, ?string $format): array
    {
        $hints = [];

        if ($status === 401 || $status === 403) {
            $hints[] = 'Auth failed. Review auth type and credentials before confirmation.';
        } elseif ($status === 404) {
            $hints[] = 'Endpoint not found. Enter an explicit resource path.';
        } elseif ($status >= 500) {
            $hints[] = 'Upstream error. Confirm the service is healthy before generation.';
        }

        if ($columns === []) {
            $hints[] = 'No columns detected; probe a populated list endpoint.';
        }

        if ($format === 'custom') {
            $hints[] = 'Custom response mapping detected; review data_key and primary_key before generation.';
        }

        return $hints;
    }
}
