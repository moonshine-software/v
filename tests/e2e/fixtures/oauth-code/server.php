<?php

declare(strict_types=1);

// Test-only Authorization Code + PKCE server. Never run it as a public identity provider.
$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$path = is_string($requestUri) ? parse_url($requestUri, PHP_URL_PATH) : '';
$method = $_SERVER['REQUEST_METHOD'];
$redirectUri = (string) getenv('E2E_OAUTH_REDIRECT_URI');
$stateDirectory = dirname(__DIR__, 2).'/.artifacts';
if (! is_dir($stateDirectory)) {
    mkdir($stateDirectory, 0700, true);
}
$database = new PDO('sqlite:'.$stateDirectory.'/oauth-code.sqlite');
$database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$database->exec('CREATE TABLE IF NOT EXISTS codes (code TEXT PRIMARY KEY, challenge TEXT NOT NULL, expires_at INTEGER NOT NULL)');

/** @param array<string, mixed> $payload */
function jsonResponse(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_THROW_ON_ERROR);
    exit;
}

if ($path === '/health') {
    jsonResponse(['ok' => true]);
}

if ($path === '/authorize') {
    $params = $method === 'POST' ? $_POST : $_GET;
    if (($params['client_id'] ?? '') !== 'e2e-code-client'
        || ($params['redirect_uri'] ?? '') !== $redirectUri
        || ($params['response_type'] ?? '') !== 'code'
        || ($params['code_challenge_method'] ?? '') !== 'S256'
        || ! is_string($params['code_challenge'] ?? null)
        || preg_match('/^[A-Za-z0-9_-]{43}$/D', $params['code_challenge']) !== 1
        || ! is_string($params['state'] ?? null)
        || $params['state'] === '') {
        jsonResponse(['error' => 'invalid_request'], 400);
    }
    if ($method === 'POST') {
        if (($params['username'] ?? '') !== 'upstream' || ($params['password'] ?? '') !== 'upstream-password') {
            jsonResponse(['error' => 'access_denied'], 401);
        }
        $code = bin2hex(random_bytes(24));
        $database->prepare('INSERT INTO codes (code, challenge, expires_at) VALUES (?, ?, ?)')
            ->execute([$code, $params['code_challenge'], time() + 60]);
        header('Location: '.$redirectUri.'?'.http_build_query(['code' => $code, 'state' => $params['state']]));
        exit;
    }
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="en"><title>E2E Identity Provider</title><form method="post"><h1>E2E Identity Provider</h1>';
    foreach (['client_id', 'redirect_uri', 'response_type', 'code_challenge_method', 'code_challenge', 'state'] as $key) {
        $value = $params[$key];
        echo '<input type="hidden" name="'.$key.'" value="'.htmlspecialchars(is_string($value) ? $value : '', ENT_QUOTES).'">';
    }
    echo '<label>Username<input name="username" autocomplete="username"></label><label>Password<input name="password" type="password" autocomplete="current-password"></label><button>Authorize</button></form></html>';
    exit;
}

if ($method === 'POST' && $path === '/token') {
    if (($_POST['grant_type'] ?? '') !== 'authorization_code'
        || ($_POST['client_id'] ?? '') !== 'e2e-code-client'
        || ($_POST['client_secret'] ?? '') !== 'e2e-code-secret'
        || ($_POST['redirect_uri'] ?? '') !== $redirectUri) {
        jsonResponse(['error' => 'invalid_client'], 400);
    }
    $statement = $database->prepare('SELECT * FROM codes WHERE code = ?');
    $statement->execute([$_POST['code'] ?? '']);
    $code = $statement->fetch(PDO::FETCH_ASSOC);
    $verifier = $_POST['code_verifier'] ?? '';
    $challenge = is_string($verifier) ? rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=') : '';
    if (! is_array($code) || $code['expires_at'] < time() || ! is_string($code['challenge']) || ! hash_equals($code['challenge'], $challenge)) {
        jsonResponse(['error' => 'invalid_grant'], 400);
    }
    $database->prepare('DELETE FROM codes WHERE code = ?')->execute([$_POST['code']]);
    jsonResponse(['access_token' => 'e2e-code-access', 'token_type' => 'Bearer', 'expires_in' => 600]);
}

$authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$pat = $authorization === 'Bearer Case-Sensitive-PAT_AbC';
if ($authorization !== 'Bearer e2e-code-access' && ! $pat) {
    jsonResponse(['error' => 'unauthorized'], 401);
}
if ($method === 'GET' && $path === '/me') {
    jsonResponse([
        'id' => $pat ? 'pat-member-1' : 'oauth-member-1',
        'email' => 'upstream@example.test', 'name' => $pat ? 'PAT Member' : 'OAuth Member',
    ]);
}
if ($method === 'GET' && $path === '/tasks') {
    jsonResponse([
        'data' => [['id' => 1, 'title' => $pat ? 'Hosted PAT task' : 'Hosted OAuth task']],
        'total' => 1, 'per_page' => 25, 'current_page' => 1, 'last_page' => 1,
    ]);
}
jsonResponse(['error' => 'not_found'], 404);
