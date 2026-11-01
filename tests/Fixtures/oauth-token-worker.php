<?php

declare(strict_types=1);

use App\ApiAdmin\Auth\OAuth2TokenStore;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
if (! $app instanceof Application) {
    throw new RuntimeException('Application bootstrap failed.');
}
$app->make(Kernel::class)->bootstrap();

$arguments = $_SERVER['argv'] ?? [];
$directory = is_array($arguments) ? ($arguments[1] ?? null) : null;
$operation = is_array($arguments) ? ($arguments[2] ?? null) : null;
$worker = is_array($arguments) ? ($arguments[3] ?? null) : null;
if (! is_string($directory) || ! is_string($operation) || ! is_string($worker)) {
    throw new RuntimeException('Supply directory, operation and worker ID.');
}
config(['cache.stores.file.path' => $directory.'/cache', 'cache.stores.file.lock_path' => $directory.'/cache']);
Cache::purge('file');
Http::preventStrayRequests();
Http::fake(static function (Request $request) use ($directory) {
    $handle = fopen($directory.'/upstream.json', 'r+');
    if ($handle === false || ! flock($handle, LOCK_EX)) {
        throw new RuntimeException('Cannot open synthetic IdP state.');
    }

    try {
        $content = stream_get_contents($handle);
        $state = $content === false ? null : json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($state) || $request['refresh_token'] !== $state['refresh']) {
            return Http::response(['error' => 'invalid_grant'], 400);
        }
        $state['refresh'] = 'refresh-next';
        $state['calls'] = is_int($state['calls']) ? $state['calls'] + 1 : 1;
        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, json_encode($state, JSON_THROW_ON_ERROR));
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
    $deadline = microtime(true) + 5;
    while ((! is_file($directory.'/entered-1') || ! is_file($directory.'/entered-2')) && microtime(true) < $deadline) {
        usleep(10_000);
    }
    usleep(250_000);

    return Http::response(['access_token' => 'access-next', 'refresh_token' => 'refresh-next', 'expires_in' => 3600]);
});
$store = new OAuth2TokenStore('rotation', 'https://idp.test/token', 'client', 'secret', seedRefreshToken: 'seed');
touch($directory.'/entered-'.$worker);
echo $operation === 'refresh'
    ? $store->refresh(OAuth2TokenStore::GRANT_REFRESH_TOKEN, 'stale-access')
    : $store->getAccessToken(OAuth2TokenStore::GRANT_REFRESH_TOKEN);
