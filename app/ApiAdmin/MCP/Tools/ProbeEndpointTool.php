<?php

declare(strict_types=1);

namespace App\ApiAdmin\MCP\Tools;

use App\ApiAdmin\Providers\ApiAdminServiceProvider;
use App\ApiAdmin\ServiceConfig\ServiceConfig;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * MCP Tool: probe_endpoint
 *
 * Dials an API endpoint through a registered service's HTTP client (with auth
 * headers applied) and returns a sample of the response plus guessed response
 * mapping. The AI agent uses this to decide how to call `register_service` /
 * `create_resource` without guessing formats.
 *
 * The response contains:
 *   - status / headers
 *   - sample_json (full body for small payloads, first N items for lists)
 *   - guessed_mapping { data_key, total_key, primary_key, per_page_key,
 *       current_page_key, last_page_key }
 *   - guessed_format: "laravel" | "django" | "flat" | null
 *   - hints: actionable notes on auth / 4xx / shape
 *   - timings_ms
 *
 * Destructive-safe by default: performs GET unless the caller explicitly asks
 * for POST to inspect a POST-RPC list/search endpoint.
 */
final class ProbeEndpointTool implements ToolContract
{
    private const int SAMPLE_ITEMS_LIMIT = 3;

    /**
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return [
            'name' => 'probe_endpoint',
            'description' => 'Probe an endpoint through a registered service and return status, sample JSON, headers, timings and a guessed response mapping (Laravel, Django, flat, or custom including Spring Boot pagination). Defaults to GET; set method=POST with body for POST-RPC list/search endpoints.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'service' => [
                        'type' => 'string',
                        'description' => 'Registered service name (as in register_service)',
                    ],
                    'path' => [
                        'type' => 'string',
                        'description' => 'Endpoint path to call, relative to service base_url (e.g. "/posts")',
                    ],
                    'query' => [
                        'type' => 'object',
                        'description' => 'Optional query string parameters',
                    ],
                    'method' => [
                        'type' => 'string',
                        'enum' => ['GET', 'POST'],
                        'description' => 'HTTP method for the probe. Defaults to GET. Use POST only for non-mutating POST-RPC list/search endpoints.',
                    ],
                    'body' => [
                        'type' => 'object',
                        'description' => 'JSON body for POST probes. Ignored for GET.',
                    ],
                    'detect_filter_for' => [
                        'type' => 'object',
                        'description' => 'If provided, the tool makes THREE extra probes using the selected method to decide the filter shape the upstream API reads. Provide a column + a value that genuinely filters the list (e.g. {"column": "status", "value": "todo"}). For GET, filters go in query; for POST, filters are merged into the JSON body. The tool probes filter[col]=val (nested — JSON:API / Laravel Query Builder default), filters[col]=val (plural_nested — Laravel with Spatie Query Builder default config), and col=val (flat); whichever returns a DIFFERENT item count than the baseline is taken as the supported shape. When `plural_nested` wins, set `filter_format: "nested"` AND `filter_param_name: "filters"` in response_mapping. Returns guessed_filter_format and all counts in `filter_format_detection`.',
                        'properties' => [
                            'column' => ['type' => 'string'],
                            'value' => ['type' => 'string'],
                        ],
                        'required' => ['column', 'value'],
                    ],
                ],
                'required' => ['service', 'path'],
            ],
        ];
    }

    /**
     * @param array{service?: string, path?: string, query?: array<string, mixed>, method?: string, body?: array<string, mixed>, detect_filter_for?: array<string, string>} $params
     *
     * @return array<string, mixed>
     */
    public function execute(array $params): array
    {
        $service = $params['service'] ?? '';
        $path = $params['path'] ?? '';
        $query = $this->stringKeyedObject($params['query'] ?? []);
        $method = isset($params['method']) ? strtoupper($params['method']) : 'GET';
        $requestBody = $this->stringKeyedObject($params['body'] ?? []);

        if ($service === '' || $path === '') {
            return [
                'success' => false,
                'error' => "Missing required 'service' or 'path'",
                'code' => 'VALIDATION',
            ];
        }

        if (! in_array($method, ['GET', 'POST'], true)) {
            return [
                'success' => false,
                'error' => "Unsupported probe method '{$method}'",
                'code' => 'VALIDATION',
                'hint' => 'probe_endpoint currently supports GET and POST only.',
            ];
        }

        Log::info('ProbeEndpointTool: executing', ['service' => $service, 'path' => $path, 'method' => $method]);

        try {
            $config = ApiAdminServiceProvider::resolveService($service);
        } catch (Throwable $e) {
            return [
                'success' => false,
                'error' => "Service '{$service}' is not registered",
                'code' => 'SERVICE_NOT_REGISTERED',
                'hint' => 'Call list_services to see what is registered, or register_service first.',
            ];
        }

        $startedAt = microtime(true);

        try {
            $response = $this->sendProbeRequest($config, $method, $path, $query, $requestBody);
        } catch (Throwable $e) {
            return [
                'success' => false,
                'error' => "HTTP request failed: {$e->getMessage()}",
                'code' => 'NETWORK_ERROR',
                'hint' => 'Verify base_url and network connectivity. If the service expects TLS verification or custom headers, adjust the ServiceConfig.',
            ];
        }

        $timingsMs = (int) round((microtime(true) - $startedAt) * 1000);
        $status = $response->status();
        /** @var array<string, array<int, string>> $rawHeaders */
        $rawHeaders = $response->headers();
        $headers = $this->selectInterestingHeaders($rawHeaders);
        $body = $response->json();

        $hints = $this->hintsFor($status, $body, $rawHeaders);

        if ($body === null || (! is_array($body))) {
            return [
                'success' => true,
                'status' => $status,
                'timings_ms' => $timingsMs,
                'headers' => $headers,
                'sample_raw' => substr((string) $response->body(), 0, 2000),
                'guessed_format' => null,
                'guessed_mapping' => null,
                'hints' => array_merge(['Response is not JSON — the generator cannot map it automatically.'], $hints),
            ];
        }

        [$format, $mapping] = $this->guessMapping($body);

        // Link-header pagination — a Link header with `rel="next"|"last"` flips the
        // format from "flat" to "github" preset regardless of body shape.
        if ($this->looksLikeLinkHeader($rawHeaders)) {
            $format = 'github';
            if (is_array($mapping)) {
                $mapping['pagination_type'] = 'link_header';
                $mapping['total_header'] = $this->detectTotalHeader($rawHeaders);
            }
        }
        $dataKey = is_string($mapping['data_key'] ?? null) ? $mapping['data_key'] : null;
        $sample = $this->buildSample($body, $dataKey);
        $detectedColumns = $this->detectColumns($body, $dataKey);

        $result = [
            'success' => true,
            'status' => $status,
            'method' => $method,
            'timings_ms' => $timingsMs,
            'headers' => $headers,
            'sample_json' => $sample,
            'guessed_format' => $format,
            'guessed_mapping' => $mapping,
            'detected_columns' => $detectedColumns,
            'hints' => $hints,
        ];

        // Optional second pass: detect filter_format by comparing baseline list
        // size against flat-encoded and nested-encoded probes with the same
        // filter. Requires the caller to give us a column+value that actually
        // narrows the result — e.g. {"column":"status","value":"todo"}.
        $detect = $params['detect_filter_for'] ?? null;
        if (is_array($detect) && isset($detect['column'], $detect['value']) && $detect['column'] !== '') {
            $baseline = $this->countItems($body, $dataKey);

            $filterResult = $this->probeFilterFormat(
                $config,
                $method,
                $path,
                $query,
                $requestBody,
                $dataKey,
                $detect['column'],
                $detect['value'],
                $baseline,
            );

            $result['filter_format_detection'] = $filterResult;
            $result['guessed_filter_format'] = $filterResult['winner'] ?? null;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     */
    private function sendProbeRequest(ServiceConfig $config, string $method, string $path, array $query, array $body): Response
    {
        if ($method === 'POST') {
            return $config->httpClient()->post($path, $body);
        }

        return $config->httpClient()->get($path, $query);
    }

    /**
     * @return array<string, mixed>
     */
    private function stringKeyedObject(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $object = [];

        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $object[$key] = $item;
            }
        }

        return $object;
    }

    /**
     * Issue extra probes with the same column+value filter encoded nested,
     * plural-nested and flat, then compare the resulting counts to the
     * baseline. Whichever one changes the count is the format the upstream
     * actually understands.
     *
     * @param array<string, mixed> $query Baseline query used with the primary probe.
     * @param array<string, mixed> $body Baseline JSON body used with the primary probe.
     *
     * @return array{winner: string|null, baseline: int|null, nested: int|null, flat: int|null, note: string}
     */
    private function probeFilterFormat(
        ServiceConfig $config,
        string $method,
        string $path,
        array $query,
        array $body,
        ?string $dataKey,
        string $column,
        string $value,
        ?int $baseline,
    ): array {
        $nestedCount = null;
        $pluralNestedCount = null;
        $flatCount = null;

        try {
            $nestedBody = $body;
            $nestedBody['filter'] = [$column => $value];
            $nested = $this->sendProbeRequest($config, $method, $path, array_merge($query, ['filter' => [$column => $value]]), $nestedBody);

            if ($nested->successful() && is_array($nested->json())) {
                $nestedCount = $this->countItems($nested->json(), $dataKey);
            }
        } catch (Throwable) {
            // Leave nestedCount null — treated as "no signal".
        }

        // Plural-nested (`?filters[col]=val`) is common on Laravel projects
        // using Spatie Query Builder with default config, so we probe it
        // too. When it wins, the caller should set both filter_format=nested
        // AND filter_param_name=filters in response_mapping.
        try {
            $pluralNestedBody = $body;
            $pluralNestedBody['filters'] = [$column => $value];
            $pluralNested = $this->sendProbeRequest($config, $method, $path, array_merge($query, ['filters' => [$column => $value]]), $pluralNestedBody);

            if ($pluralNested->successful() && is_array($pluralNested->json())) {
                $pluralNestedCount = $this->countItems($pluralNested->json(), $dataKey);
            }
        } catch (Throwable) {
            // Leave pluralNestedCount null.
        }

        try {
            $flatBody = $body;
            $flatBody[$column] = $value;
            $flat = $this->sendProbeRequest($config, $method, $path, array_merge($query, [$column => $value]), $flatBody);

            if ($flat->successful() && is_array($flat->json())) {
                $flatCount = $this->countItems($flat->json(), $dataKey);
            }
        } catch (Throwable) {
            // Leave flatCount null.
        }

        $note = sprintf(
            'baseline=%s nested=%s plural_nested=%s flat=%s',
            $baseline === null ? 'null' : (string) $baseline,
            $nestedCount === null ? 'null' : (string) $nestedCount,
            $pluralNestedCount === null ? 'null' : (string) $pluralNestedCount,
            $flatCount === null ? 'null' : (string) $flatCount,
        );

        $winner = null;

        // Winner = the only one that reported a filtered count different
        // from baseline. If several differ simultaneously the signal is
        // ambiguous — we record "ambiguous" so the caller can decide
        // based on other probe data.
        $differsFromBaseline = fn (?int $c): bool => $c !== null && $baseline !== null && $c !== $baseline;
        $nestedDiffers = $differsFromBaseline($nestedCount);
        $pluralDiffers = $differsFromBaseline($pluralNestedCount);
        $flatDiffers = $differsFromBaseline($flatCount);

        $diffCount = (int) $nestedDiffers + (int) $pluralDiffers + (int) $flatDiffers;

        if ($diffCount === 1) {
            $winner = $flatDiffers ? 'flat' : ($pluralDiffers ? 'plural_nested' : 'nested');
        } elseif ($diffCount > 1) {
            // Several forms seemingly filtered — can't tell which one the
            // API actually honored (e.g. `status=todo` might coincidentally
            // match in both shapes if the upstream treats unknown params as
            // "match anything"). Defer to nested — the most common shape —
            // and flag the ambiguity in the note so the caller can override
            // manually if needed.
            $winner = 'nested';
            $note .= ' (ambiguous — multiple forms changed the count; defaulted to nested)';
        }

        return [
            'winner' => $winner,
            'baseline' => $baseline,
            'nested' => $nestedCount,
            'plural_nested' => $pluralNestedCount,
            'flat' => $flatCount,
            'note' => $note,
        ];
    }

    /**
     * Count items in a list-like response. Uses data_key when present,
     * otherwise falls back to direct array length (for flat responses).
     */
    private function countItems(mixed $body, ?string $dataKey): ?int
    {
        if (! is_array($body)) {
            return null;
        }

        $data = $dataKey !== null && $dataKey !== '' ? data_get($body, $dataKey) : null;
        if (is_array($data)) {
            return count($data);
        }

        if (array_is_list($body)) {
            return count($body);
        }

        return null;
    }

    /**
     * Extract the column names from the first item in a list response (or
     * from a single-item response), so the agent can decide field shapes
     * without guessing.
     *
     * @param array<mixed> $body
     *
     * @return list<string>
     */
    private function detectColumns(array $body, ?string $dataKey): array
    {
        $candidate = null;

        $data = $dataKey !== null && $dataKey !== '' ? data_get($body, $dataKey) : null;
        if ($data === []) {
            return [];
        }

        if (is_array($data)) {
            $items = $data;
            $candidate = is_array($items[0] ?? null) ? $items[0] : null;
        } elseif (array_is_list($body)) {
            $candidate = is_array($body[0] ?? null) ? $body[0] : null;
        }

        // Fallback: scan the envelope for the first key whose value is a
        // non-empty list of objects. This handles APIs that use a plural
        // wrapper without matching the known presets (`{"tasks": [...]}`
        // for the CQRS task-manager, `{"items": [...]}` for ad-hoc APIs).
        if (! is_array($candidate)) {
            foreach ($body as $value) {
                if (is_array($value) && array_is_list($value) && isset($value[0]) && is_array($value[0])) {
                    $candidate = $value[0];
                    break;
                }
            }
        }

        if (! is_array($candidate)) {
            // Single-resource response — use the top-level keys as columns.
            $candidate = $body;
        }

        return array_filter(
            array_keys($candidate),
            is_string(...),
        )
            |> array_values(...);
    }

    /**
     * Pick a small set of response headers useful for debugging.
     *
     * @param array<string, array<int, string>> $headers
     *
     * @return array<string, string>
     */
    private function selectInterestingHeaders(array $headers): array
    {
        $keep = ['Content-Type', 'X-Total-Count', 'Link', 'X-RateLimit-Remaining', 'WWW-Authenticate'];
        $out = [];

        foreach ($keep as $name) {
            $lower = strtolower($name);
            foreach ($headers as $k => $v) {
                if (strtolower((string) $k) === $lower) {
                    $out[$name] = implode(', ', $v);
                }
            }
        }

        return $out;
    }

    /**
     * @param array<string, array<int, string>> $rawHeaders
     *
     * @return array<int, string>
     */
    private function hintsFor(int $status, mixed $body, array $rawHeaders = []): array
    {
        $hints = [];

        if ($status === 401 || $status === 403) {
            $hints[] = 'Auth failed. Double-check api_key and auth_type on the service config.';
        } elseif ($status >= 500) {
            $hints[] = 'Upstream 5xx — the API is reachable but errored. Inspect sample_raw / sample_json for upstream error shape.';
        } elseif ($status === 404) {
            $hints[] = 'Path not found. Verify the endpoint relative to base_url.';
        } elseif ($status === 419) {
            $hints[] = '419 = CSRF token expired. Set auth_type="session" with the matching csrf_token_source / csrf_header_name so the admin auto-refreshes the session on retry.';
        }

        if ($status === 401 || $status === 419) {
            $sessionHint = $this->detectSessionFingerprint($rawHeaders);

            if ($sessionHint !== null) {
                $hints[] = $sessionHint;
            }

            $oauthHint = $this->detectOAuthFingerprint($rawHeaders);

            if ($oauthHint !== null) {
                $hints[] = $oauthHint;
            }
        }

        if ($status >= 200 && $status < 300 && is_array($body) && $body === []) {
            $hints[] = 'Response body is empty JSON. Probe a known-populated endpoint to infer the paginator shape.';
        }

        return $hints;
    }

    /**
     * Inspect Set-Cookie headers for session-flow markers (XSRF-TOKEN,
     * sessionid, JSESSIONID, _session_id). When the upstream sets a session
     * cookie even on a failed auth attempt, that's the strongest signal that
     * a token-based auth_type will not work — the API expects a logged-in
     * session.
     *
     * @param array<string, array<int, string>> $rawHeaders
     */
    private function detectSessionFingerprint(array $rawHeaders): ?string
    {
        $setCookies = $rawHeaders['Set-Cookie'] ?? $rawHeaders['set-cookie'] ?? [];

        if ($setCookies === []) {
            return null;
        }

        $matchedCookies = [];
        $patterns = ['XSRF-TOKEN', 'csrftoken', 'sessionid', 'JSESSIONID', '_session_id', 'PHPSESSID', 'connect.sid', 'laravel_session'];

        foreach ($setCookies as $line) {
            $first = trim(explode(';', $line, 2)[0]);
            $eq = strpos($first, '=');
            $name = $eq === false ? $first : substr($first, 0, $eq);

            if ($name === '') {
                continue;
            }

            foreach ($patterns as $pattern) {
                if (strcasecmp($name, $pattern) === 0) {
                    $matchedCookies[] = $name;
                    break;
                }
            }
        }

        if ($matchedCookies === []) {
            return null;
        }

        $cookies = implode(', ', array_unique($matchedCookies));

        return "Session cookie observed in Set-Cookie ({$cookies}) — auth_type='session' may be required. Configure session_login_endpoint, session_username, session_password and the matching csrf_* params via register_service.";
    }

    /**
     * Look for a `WWW-Authenticate` challenge in the response. RFC 7235 says
     * a server returning 401 MUST send WWW-Authenticate; the realm / scheme
     * tells us what the server actually wants. "Bearer" + 401 with current
     * oauth2_cc setup means the token died — try password / refresh_token
     * grants if the IdP supports them.
     *
     * @param array<string, array<int, string>> $rawHeaders
     */
    private function detectOAuthFingerprint(array $rawHeaders): ?string
    {
        $challenges = $rawHeaders['WWW-Authenticate'] ?? $rawHeaders['www-authenticate'] ?? [];

        if ($challenges === []) {
            return null;
        }

        $challenge = implode(' ', $challenges)
            |> strtolower(...);

        if (str_contains($challenge, 'bearer')) {
            return "WWW-Authenticate: Bearer challenge present — if 401 persists with auth_type='oauth2_cc', try auth_type='oauth2_password' (Resource Owner grant) or 'oauth2_refresh_token' depending on what the IdP issues.";
        }

        if (str_contains($challenge, 'basic')) {
            return "WWW-Authenticate: Basic challenge present — try auth_type='basic' with api_key='user:password'.";
        }

        return null;
    }

    /**
     * Guess which response preset matches the payload and the mapping keys.
     *
     * @param array<mixed> $body
     *
     * @return array{0: string|null, 1: array<string, bool|string>|null}
     */
    private function guessMapping(array $body): array
    {
        // Flat list — e.g. plain JSON array returned by many public APIs
        if (array_is_list($body)) {
            return [
                'flat',
                [
                    'data_key' => '',
                    'primary_key' => $this->guessPrimaryKey($body[0] ?? null),
                ],
            ];
        }

        // Laravel paginator: data + total + per_page + current_page + last_page
        $laravelKeys = ['data', 'total', 'per_page', 'current_page', 'last_page'];
        if ($this->hasAll($body, $laravelKeys)) {
            $items = $body['data'] ?? [];

            return [
                'laravel',
                [
                    'data_key' => 'data',
                    'total_key' => 'total',
                    'per_page_key' => 'per_page',
                    'current_page_key' => 'current_page',
                    'last_page_key' => 'last_page',
                    'primary_key' => $this->guessPrimaryKey(is_array($items) ? ($items[0] ?? null) : null),
                ],
            ];
        }

        // Django REST Framework paginator: results + count (+ next/previous URLs)
        if (isset($body['results'], $body['count'])) {
            $items = $body['results'];

            return [
                'django',
                [
                    'data_key' => 'results',
                    'total_key' => 'count',
                    'per_page_key' => 'page_size',
                    'next_page_url_key' => 'next',
                    'prev_page_url_key' => 'previous',
                    'per_page_param_name' => 'page_size',
                    'current_page_key' => 'current_page',
                    'last_page_key' => 'last_page',
                    'primary_key' => $this->guessPrimaryKey(is_array($items) ? ($items[0] ?? null) : null),
                ],
            ];
        }

        if (is_array($body['content'] ?? null)
            && $this->hasAll($body, ['totalElements', 'size', 'number', 'totalPages'])) {
            return ['custom', [
                'data_key' => 'content',
                'total_key' => 'totalElements',
                'per_page_key' => 'size',
                'current_page_key' => 'number',
                'last_page_key' => 'totalPages',
                'per_page_param_name' => 'size',
                'zero_based_pages' => true,
                'primary_key' => $this->guessPrimaryKey($body['content'][0] ?? null),
            ]];
        }

        // Plural-wrapped list: one top-level key whose value is a list
        // (e.g. `{"tasks":[...]}`, `{"data":[], "cursor":{...}}`). Very
        // common in CQRS / cursor-paginated APIs that don't pigeonhole
        // into Laravel/Django pagination. Use that key as data_key so
        // the generator + caster find items without extra config.
        //
        // Accepts EMPTY lists — when the probe endpoint currently has no
        // rows, the primary_key falls back to "id". Without this branch,
        // guessMapping used to fall through to single-resource mode and
        // emit `sample_json.resource.{...}` instead of `envelope_keys` +
        // `items_sample`, which confused agents into thinking the shape
        // was nested.
        foreach ($body as $key => $value) {
            if (is_string($key) && is_array($value) && array_is_list($value)) {
                $firstItem = $value[0] ?? null;

                return [
                    'custom',
                    [
                        'data_key' => $key,
                        'primary_key' => $this->guessPrimaryKey(is_array($firstItem) ? $firstItem : null),
                    ],
                ];
            }
        }

        $nestedList = $this->findFirstNestedListPath($body);
        if ($nestedList !== null) {
            [$path, $items] = $nestedList;
            $firstItem = $items[0] ?? null;

            return [
                'custom',
                [
                    'data_key' => $path,
                    'primary_key' => $this->guessPrimaryKey(is_array($firstItem) ? $firstItem : null),
                ],
            ];
        }

        // Single resource (dict with no pagination envelope) — treat as custom/flat
        return [
            'custom',
            [
                'data_key' => '',
                'primary_key' => $this->guessPrimaryKey($body),
            ],
        ];
    }

    /**
     * @param array<mixed> $body
     * @param array<int, string> $keys
     */
    private function hasAll(array $body, array $keys): bool
    {
        return array_all($keys, fn (string $k): bool => array_key_exists($k, $body));
    }

    private function guessPrimaryKey(mixed $item): string
    {
        if (! is_array($item) || $item === []) {
            return 'id';
        }

        foreach (['id', 'uuid', 'pk', 'slug', 'key'] as $candidate) {
            if (array_key_exists($candidate, $item)) {
                return $candidate;
            }
        }

        return 'id';
    }

    /**
     * @param array<mixed> $body
     *
     * @return array{0: string, 1: array<int, mixed>}|null
     */
    private function findFirstNestedListPath(array $body, string $prefix = ''): ?array
    {
        foreach ($body as $key => $value) {
            if (! is_string($key)) {
                continue;
            }

            if (! is_array($value)) {
                continue;
            }

            $path = $prefix === '' ? $key : $prefix.'.'.$key;

            if (array_is_list($value)) {
                return [$path, $value];
            }

            $nested = $this->findFirstNestedListPath($value, $path);
            if ($nested !== null) {
                return $nested;
            }
        }

        return null;
    }

    /**
     * @param array<mixed> $body
     *
     * @return array<mixed>
     */
    private function buildSample(array $body, ?string $dataKey): array
    {
        $data = $dataKey !== null && $dataKey !== '' ? data_get($body, $dataKey) : null;

        if (is_array($data)) {
            $items = $data;

            return [
                'envelope_keys' => array_keys($body),
                'items_sample' => array_slice($items, 0, self::SAMPLE_ITEMS_LIMIT),
                'items_total_in_response' => count($items),
            ];
        }

        if (array_is_list($body)) {
            return [
                'items_sample' => array_slice($body, 0, self::SAMPLE_ITEMS_LIMIT),
                'items_total_in_response' => count($body),
            ];
        }

        // Single-resource response
        return ['resource' => $body];
    }

    /**
     * RFC 5988 Link header with `rel="next"` or `rel="last"` marks a link-header
     * paginator. Presence alone is enough — body shape is orthogonal.
     *
     * @param array<string, array<int, string>> $headers
     */
    private function looksLikeLinkHeader(array $headers): bool
    {
        $link = $headers['Link'] ?? $headers['link'] ?? [];
        $joined = implode(',', $link);

        return stripos($joined, 'rel="next"') !== false
            || stripos($joined, 'rel="last"') !== false;
    }

    /**
     * Pick the total-count header that's actually present. Defaults to X-Total-Count
     * (GitHub); falls back to X-Total (GitLab). Returns '' if neither is set.
     *
     * @param array<string, array<int, string>> $headers
     */
    private function detectTotalHeader(array $headers): string
    {
        foreach (['X-Total-Count', 'x-total-count', 'X-Total', 'x-total'] as $name) {
            if (isset($headers[$name]) && $headers[$name] !== []) {
                return str_starts_with($name, 'X-') ? $name : strtoupper(substr($name, 0, 1)).substr($name, 1);
            }
        }

        return '';
    }
}
