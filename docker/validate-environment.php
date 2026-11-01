<?php

declare(strict_types=1);

$url = getenv('APP_URL');
$key = getenv('APP_KEY');
$validUrl = is_string($url) && filter_var($url, FILTER_VALIDATE_URL) !== false;
$localHttp = $validUrl
    && getenv('APP_ENV') === 'local'
    && parse_url($url, PHP_URL_SCHEME) === 'http'
    && parse_url($url, PHP_URL_HOST) === '127.0.0.1';
if (! $validUrl || (! str_starts_with($url, 'https://') && ! $localHttp)) {
    throw new RuntimeException('APP_URL must use HTTPS, or loopback HTTP with APP_ENV=local.');
}
if (! is_string($key) || ! str_starts_with($key, 'base64:') || strlen((string) base64_decode(substr($key, 7), true)) !== 32) {
    throw new RuntimeException('Production APP_KEY must contain 32 random base64-encoded bytes.');
}
foreach (['DB_PASSWORD', 'REDIS_PASSWORD'] as $name) {
    $value = getenv($name);
    if (! is_string($value) || strlen($value) < 24) {
        throw new RuntimeException($name.' must be an independently generated password of at least 24 characters.');
    }
}
