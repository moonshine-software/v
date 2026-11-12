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

function session_posts_db(): PDO
{
    $db = new PDO('sqlite:'.dirname(session_storage_dir()).'/posts.sqlite');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec('PRAGMA busy_timeout = 5000');
    $db->exec('BEGIN IMMEDIATE');

    // Seed only when creating the table: deleting every post must leave an empty list.
    if ($db->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'posts'")->fetchColumn() === false) {
        $db->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL, status TEXT NOT NULL, created_at TEXT NOT NULL)');
        $insert = $db->prepare('INSERT INTO posts (title, status, created_at) VALUES (?, ?, ?)');
        foreach (['Sanctum SPA login', 'CSRF rotation', 'Session re-establish on 419'] as $index => $title) {
            $insert->execute([$title, $index === 1 ? 'draft' : 'published', gmdate('c')]);
        }
    }

    $db->exec('COMMIT');

    return $db;
}

function session_post_values(array $body): array
{
    $errors = [];
    if (! is_string($body['title'] ?? null) || trim($body['title']) === '' || mb_strlen($body['title']) > 255) {
        $errors['title'] = ['The title must be a non-empty string of at most 255 characters.'];
    }
    if (! in_array($body['status'] ?? null, ['draft', 'published'], true)) {
        $errors['status'] = ['The selected status is invalid.'];
    }
    if ($errors !== []) {
        error_log('Session API: post validation failed');
        session_json(['message' => 'The given data was invalid.', 'errors' => $errors], 422);
    }

    return [$body['title'], $body['status']];
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

if ($path === '/posts' || preg_match('#^/posts/(\d+)$#', $path, $match) === 1) {
    session_require_authenticated();
    session_validate_csrf();

    $db = session_posts_db();
    $id = isset($match[1]) ? (int) $match[1] : null;

    if ($method === 'GET' && $id === null) {
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($_GET['per_page'] ?? 25)));
        $total = (int) $db->query('SELECT COUNT(*) FROM posts')->fetchColumn();
        $offset = ($page - 1) * $perPage;
        session_json([
            'data' => $db->query("SELECT * FROM posts ORDER BY id LIMIT {$perPage} OFFSET {$offset}")->fetchAll(),
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page,
            'last_page' => max(1, (int) ceil($total / $perPage)),
        ]);
    }

    if ($method === 'POST' && $id === null) {
        [$title, $status] = session_post_values(session_body() + ['status' => 'draft']);
        $db->prepare('INSERT INTO posts (title, status, created_at) VALUES (?, ?, ?)')->execute([$title, $status, gmdate('c')]);
        $id = (int) $db->lastInsertId();
        $post = $db->query("SELECT * FROM posts WHERE id = {$id}")->fetch();
        error_log('Session API: post created');
        session_json(['data' => $post], 201);
    }

    if ($id !== null) {
        $post = $db->query("SELECT * FROM posts WHERE id = {$id}")->fetch();
        if ($post === false) {
            session_json(['message' => 'Post not found'], 404);
        }
        if ($method === 'GET') {
            session_json(['data' => $post]);
        }
        if (in_array($method, ['PUT', 'PATCH'], true)) {
            [$title, $status] = session_post_values(session_body() + $post);
            $db->prepare('UPDATE posts SET title = ?, status = ? WHERE id = ?')->execute([$title, $status, $id]);
            $post = $db->query("SELECT * FROM posts WHERE id = {$id}")->fetch();
            error_log('Session API: post updated');
            session_json(['data' => $post]);
        }
        if ($method === 'DELETE') {
            $db->prepare('DELETE FROM posts WHERE id = ?')->execute([$id]);
            error_log('Session API: post deleted');
            http_response_code(204);
            exit;
        }
    }
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
