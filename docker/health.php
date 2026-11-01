<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

require dirname(__DIR__).'/vendor/autoload.php';

try {
    $app = require dirname(__DIR__).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    DB::select('SELECT 1');
    Redis::connection()->ping();
    echo 'api-admin-ready';
} catch (Throwable $exception) {
    http_response_code(503);
    error_log('Readiness check failed: '.$exception::class);
    echo 'unavailable';
    exit(1);
}
