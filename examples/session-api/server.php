<?php

declare(strict_types=1);

/**
 * Demo session + CSRF API for moonshine-v auth-flow tests.
 *
 * Mirrors the Laravel Sanctum SPA flow:
 *   1. GET /sanctum/csrf-cookie  → sets XSRF-TOKEN + sanctum_session cookies.
 *   2. POST /login {email, password} (with X-XSRF-TOKEN header) → marks session
 *      authenticated; rotates the XSRF-TOKEN.
 *   3. GET /me / GET /posts (with cookie + X-XSRF-TOKEN) → 200 with the user
 *      / a paginated list. CSRF mismatch → 419, no-session → 401.
 *
 * Hard-coded credentials:
 *   email     = admin
 *   password  = 1234567
 *
 * Sessions live in PHP's file-based session store. CSRF tokens are
 * persisted alongside the session under the `_csrf` key.
 */
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, X-XSRF-TOKEN, X-CSRFToken');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Credentials: true');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

const SESSION_EMAIL = 'admin';
const SESSION_PASSWORD = '1234567';
const SESSION_LIFETIME = 60 * 60 * 2; // 2h
const CSRF_LIFETIME = 60 * 30;       // 30 min — short so 419 retries are demo-able

function session_json(mixed $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function session_storage_dir(): string
{
    $dir = __DIR__.'/storage/sessions';
    if (! is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    return $dir;
}

function session_start_named(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_save_path(session_storage_dir());
    session_name('sanctum_session');
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path' => '/',
        'samesite' => 'Lax',
    ]);
    session_start();
}

function session_rotate_csrf(): string
{
    $token = bin2hex(random_bytes(20));
    $_SESSION['_csrf'] = $token;
    $_SESSION['_csrf_exp'] = time() + CSRF_LIFETIME;

    setcookie('XSRF-TOKEN', $token, [
        'expires' => time() + CSRF_LIFETIME,
        'path' => '/',
        'samesite' => 'Lax',
    ]);

    return $token;
}

function session_csrf_value(): ?string
{
    return isset($_SESSION['_csrf']) && is_string($_SESSION['_csrf']) ? $_SESSION['_csrf'] : null;
}

function session_validate_csrf(): void
{
    $expected = session_csrf_value();
    $exp = $_SESSION['_csrf_exp'] ?? 0;

    if ($expected === null) {
        session_json(['error' => 'csrf_missing', 'hint' => 'GET /sanctum/csrf-cookie first'], 419);
    }

    if (! is_int($exp) || $exp < time()) {
        session_json(['error' => 'csrf_expired', 'hint' => 'GET /sanctum/csrf-cookie to refresh'], 419);
    }

    $supplied = $_SERVER['HTTP_X_XSRF_TOKEN'] ?? $_SERVER['HTTP_X_CSRFTOKEN'] ?? '';
    if (! is_string($supplied) || $supplied === '' || ! hash_equals($expected, $supplied)) {
        session_json(['error' => 'csrf_mismatch'], 419);
    }
}

function session_require_authenticated(): void
{
    if (empty($_SESSION['authenticated_user'])) {
        session_json(['error' => 'unauthenticated', 'hint' => 'POST /login first'], 401);
    }
}

function session_body(): array
{
    if (! empty($_POST)) {
        return $_POST;
    }

    $raw = (string) file_get_contents('php://input');
    if ($raw === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        return $decoded;
    }

    parse_str($raw, $parsed);

    return is_array($parsed) ? $parsed : [];
}

session_start_named();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';

// ─────────────────────────────────────────────────────────────────────
// CSRF bootstrap — Sanctum SPA pattern.
// ─────────────────────────────────────────────────────────────────────
if ($method === 'GET' && $path === '/sanctum/csrf-cookie') {
    session_rotate_csrf();
    http_response_code(204);
    exit;
}

// ─────────────────────────────────────────────────────────────────────
// Login flow.
// ─────────────────────────────────────────────────────────────────────
if ($method === 'POST' && $path === '/login') {
    session_validate_csrf();

    $body = session_body();
    $email = (string) ($body['email'] ?? '');
    $password = (string) ($body['password'] ?? '');

    if ($email !== SESSION_EMAIL || $password !== SESSION_PASSWORD) {
        session_json(['error' => 'invalid_credentials'], 422);
    }

    $_SESSION['authenticated_user'] = [
        'id' => 1,
        'email' => SESSION_EMAIL,
        'name' => 'Admin User',
        'logged_in_at' => gmdate('c'),
    ];

    // Rotate the CSRF token after login (good hygiene; matches Laravel).
    session_rotate_csrf();

    session_json([
        'ok' => true,
        'user' => $_SESSION['authenticated_user'],
    ]);
}

if ($method === 'POST' && $path === '/logout') {
    session_validate_csrf();
    $_SESSION = [];
    session_destroy();
    setcookie('sanctum_session', '', time() - 3600, '/');
    setcookie('XSRF-TOKEN', '', time() - 3600, '/');
    session_json(['ok' => true]);
}

// ─────────────────────────────────────────────────────────────────────
// Protected resources.
// ─────────────────────────────────────────────────────────────────────
if ($method === 'GET' && $path === '/me') {
    session_require_authenticated();
    session_validate_csrf();
    session_json(['user' => $_SESSION['authenticated_user']]);
}

if ($method === 'GET' && $path === '/posts') {
    session_require_authenticated();
    session_validate_csrf();

    $now = gmdate('c');
    session_json([
        'data' => [
            ['id' => 1, 'title' => 'Sanctum SPA login', 'status' => 'published', 'created_at' => $now],
            ['id' => 2, 'title' => 'CSRF rotation', 'status' => 'draft', 'created_at' => $now],
            ['id' => 3, 'title' => 'Session re-establish on 419', 'status' => 'published', 'created_at' => $now],
        ],
        'total' => 3,
        'per_page' => 25,
        'current_page' => 1,
        'last_page' => 1,
    ]);
}

if ($method === 'GET' && $path === '/health') {
    session_json(['ok' => true, 'service' => 'session-api']);
}

// ─────────────────────────────────────────────────────────────────────
// Diagnostics — force-expire CSRF / nuke session, for demo of 419 retry.
// ─────────────────────────────────────────────────────────────────────
if ($method === 'POST' && $path === '/__demo/expire-csrf') {
    $_SESSION['_csrf_exp'] = time() - 1;
    session_json(['ok' => true, 'message' => 'next request will see csrf_expired (419)']);
}

if ($method === 'POST' && $path === '/__demo/destroy-session') {
    $_SESSION = [];
    session_destroy();
    session_json(['ok' => true, 'message' => 'session destroyed; next request will 401']);
}

session_json(['error' => 'not_found', 'path' => $path], 404);
