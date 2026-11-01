<?php

declare(strict_types=1);

/**
 * Demo OAuth 2.0 Identity Provider for moonshine-v auth-flow tests.
 *
 * Supports three grants on POST /oauth/token:
 *   - client_credentials  → for auth_type="oauth2_cc"
 *   - password            → for auth_type="oauth2_password"
 *   - refresh_token       → for auth_type="oauth2_refresh_token"
 *
 * Plus protected resource endpoints (GET /me, GET /tasks) that require
 * Authorization: Bearer <access_token>. Tokens are persisted in a JSON
 * file so each php -S worker process sees the same set.
 *
 * Hard-coded credentials (good enough for examples — never use in prod):
 *   client_id      = demo-client
 *   client_secret  = demo-secret
 *   username       = admin
 *   password       = 123456
 *   scope          = read write   (enforced loosely — accepted as-is)
 */

declare(ticks=1);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

const OAUTH_CLIENT_ID = 'demo-client';
const OAUTH_CLIENT_SECRET = 'demo-secret';
const OAUTH_USERNAME = 'admin';
const OAUTH_PASSWORD = '123456';
const OAUTH_ACCESS_TTL = 60;       // seconds — short so refresh-on-401 path is exercisable in demos
const OAUTH_REFRESH_TTL = 60 * 60 * 24; // 1 day

function oauth_json(mixed $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function oauth_storage_path(string $name): string
{
    $dir = __DIR__.'/storage';
    if (! is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    return $dir.'/'.$name;
}

/**
 * @return array<string, array{exp: int, sub: string, scope: string}>
 */
function oauth_load_tokens(string $kind): array
{
    $path = oauth_storage_path($kind.'.json');
    if (! file_exists($path)) {
        return [];
    }

    $raw = (string) file_get_contents($path);
    $decoded = json_decode($raw, true);

    return is_array($decoded) ? $decoded : [];
}

/**
 * @param array<string, array{exp: int, sub: string, scope: string}> $tokens
 */
function oauth_save_tokens(string $kind, array $tokens): void
{
    file_put_contents(oauth_storage_path($kind.'.json'), json_encode($tokens, JSON_PRETTY_PRINT));
}

function oauth_prune_expired(string $kind): void
{
    $tokens = oauth_load_tokens($kind);
    $now = time();
    $changed = false;
    foreach ($tokens as $value => $meta) {
        if (($meta['exp'] ?? 0) < $now) {
            unset($tokens[$value]);
            $changed = true;
        }
    }
    if ($changed) {
        oauth_save_tokens($kind, $tokens);
    }
}

function oauth_mint_token(string $kind, string $sub, string $scope, int $ttl): string
{
    oauth_prune_expired($kind);
    $tokens = oauth_load_tokens($kind);

    $value = bin2hex(random_bytes(16)).'-'.$kind;
    $tokens[$value] = [
        'exp' => time() + $ttl,
        'sub' => $sub,
        'scope' => $scope,
    ];
    oauth_save_tokens($kind, $tokens);

    return $value;
}

function oauth_revoke_token(string $kind, string $value): void
{
    $tokens = oauth_load_tokens($kind);
    if (isset($tokens[$value])) {
        unset($tokens[$value]);
        oauth_save_tokens($kind, $tokens);
    }
}

/**
 * @return array{exp: int, sub: string, scope: string}|null
 */
function oauth_lookup_token(string $kind, string $value): ?array
{
    oauth_prune_expired($kind);
    $tokens = oauth_load_tokens($kind);

    return $tokens[$value] ?? null;
}

function oauth_form_body(): array
{
    if (! empty($_POST)) {
        return $_POST;
    }

    $raw = (string) file_get_contents('php://input');
    parse_str($raw, $parsed);

    return is_array($parsed) ? $parsed : [];
}

function oauth_require_bearer(): array
{
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (! is_string($auth) || ! str_starts_with($auth, 'Bearer ')) {
        header('WWW-Authenticate: Bearer realm="oauth-idp-api"');
        oauth_json(['error' => 'invalid_request', 'message' => 'missing Bearer'], 401);
    }

    $token = trim(substr($auth, 7));
    $meta = oauth_lookup_token('access', $token);
    if ($meta === null) {
        header('WWW-Authenticate: Bearer realm="oauth-idp-api", error="invalid_token"');
        oauth_json(['error' => 'invalid_token', 'message' => 'access token expired or unknown'], 401);
    }

    return $meta;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';

// ─────────────────────────────────────────────────────────────────────
// POST /oauth/token — three grants.
// ─────────────────────────────────────────────────────────────────────
if ($method === 'POST' && $path === '/oauth/token') {
    $body = oauth_form_body();

    $grant = (string) ($body['grant_type'] ?? '');
    $clientId = (string) ($body['client_id'] ?? '');
    $clientSecret = (string) ($body['client_secret'] ?? '');
    $scope = (string) ($body['scope'] ?? 'read write');

    if ($clientId !== OAUTH_CLIENT_ID || $clientSecret !== OAUTH_CLIENT_SECRET) {
        oauth_json(['error' => 'invalid_client'], 401);
    }

    if ($grant === 'client_credentials') {
        $access = oauth_mint_token('access', 'client:'.$clientId, $scope, OAUTH_ACCESS_TTL);
        oauth_json([
            'access_token' => $access,
            'token_type' => 'Bearer',
            'expires_in' => OAUTH_ACCESS_TTL,
            'scope' => $scope,
        ]);
    }

    if ($grant === 'password') {
        $username = (string) ($body['username'] ?? '');
        $password = (string) ($body['password'] ?? '');

        if ($username !== OAUTH_USERNAME || $password !== OAUTH_PASSWORD) {
            oauth_json(['error' => 'invalid_grant', 'error_description' => 'bad username/password'], 400);
        }

        $access = oauth_mint_token('access', 'user:'.$username, $scope, OAUTH_ACCESS_TTL);
        $refresh = oauth_mint_token('refresh', 'user:'.$username, $scope, OAUTH_REFRESH_TTL);

        oauth_json([
            'access_token' => $access,
            'refresh_token' => $refresh,
            'token_type' => 'Bearer',
            'expires_in' => OAUTH_ACCESS_TTL,
            'scope' => $scope,
        ]);
    }

    if ($grant === 'refresh_token') {
        $rtValue = (string) ($body['refresh_token'] ?? '');
        $rtMeta = oauth_lookup_token('refresh', $rtValue);

        if ($rtMeta === null) {
            oauth_json(['error' => 'invalid_grant', 'error_description' => 'refresh token unknown or expired'], 400);
        }

        // Rotate: revoke old refresh, mint a new pair.
        oauth_revoke_token('refresh', $rtValue);
        $access = oauth_mint_token('access', $rtMeta['sub'], $rtMeta['scope'], OAUTH_ACCESS_TTL);
        $refresh = oauth_mint_token('refresh', $rtMeta['sub'], $rtMeta['scope'], OAUTH_REFRESH_TTL);

        oauth_json([
            'access_token' => $access,
            'refresh_token' => $refresh, // rotation
            'token_type' => 'Bearer',
            'expires_in' => OAUTH_ACCESS_TTL,
            'scope' => $rtMeta['scope'],
        ]);
    }

    oauth_json(['error' => 'unsupported_grant_type', 'grant' => $grant], 400);
}

// ─────────────────────────────────────────────────────────────────────
// Protected resource endpoints.
// ─────────────────────────────────────────────────────────────────────
if ($method === 'GET' && $path === '/me') {
    $meta = oauth_require_bearer();
    oauth_json([
        'sub' => $meta['sub'],
        'scope' => $meta['scope'],
        'expires_at' => gmdate('c', $meta['exp']),
    ]);
}

if ($method === 'GET' && $path === '/tasks') {
    oauth_require_bearer();

    $now = gmdate('c');
    oauth_json([
        'data' => [
            ['id' => 1, 'title' => 'Wire OAuth2 password grant', 'status' => 'done', 'created_at' => $now],
            ['id' => 2, 'title' => 'Verify refresh-token rotation', 'status' => 'in_progress', 'created_at' => $now],
            ['id' => 3, 'title' => 'Document scopes in README', 'status' => 'todo', 'created_at' => $now],
        ],
        'total' => 3,
        'per_page' => 25,
        'current_page' => 1,
        'last_page' => 1,
    ]);
}

if ($method === 'GET' && $path === '/health') {
    oauth_json(['ok' => true, 'service' => 'oauth-idp-api']);
}

// ─────────────────────────────────────────────────────────────────────
// Diagnostics — wipe issued tokens (handy when retrying flows).
// Not part of OAuth spec; demo-only.
// ─────────────────────────────────────────────────────────────────────
if ($method === 'POST' && $path === '/__demo/reset') {
    @unlink(oauth_storage_path('access.json'));
    @unlink(oauth_storage_path('refresh.json'));
    oauth_json(['ok' => true, 'message' => 'token store wiped']);
}

oauth_json(['error' => 'not_found', 'path' => $path], 404);
