<?php

declare(strict_types=1);

use App\ApiAdmin\MCP\McpServer;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

require dirname(__DIR__).'/vendor/autoload.php';

try {
    $app = require dirname(__DIR__).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $mode = $argv[1] ?? 'check';
    $paths = ['resources', 'local-resources', 'configs', 'layouts', 'components', 'fields', 'views', 'specs'];

    if ($mode === 'queue') {
        Redis::del('release-worker-smoke');
        dispatch(static function (): void {
            Redis::set('release-worker-smoke', 'processed');
        });
        $deadline = time() + 20;
        while (Redis::get('release-worker-smoke') !== 'processed') {
            if (time() >= $deadline) {
                throw new RuntimeException('Queue worker did not process the smoke job');
            }
            usleep(250000);
        }
    } elseif ($mode === 'write') {
        $server = new McpServer;
        foreach ([
            ['create_field', ['name' => 'SmokeKept', 'extends' => 'text']],
            ['create_field', ['name' => 'SmokeRemoved', 'extends' => 'text']],
            ['update_field', ['name' => 'SmokeKept', 'extends' => 'textarea']],
            ['delete_field', ['name' => 'SmokeRemoved', 'confirm' => true]],
        ] as [$tool, $params]) {
            $result = $server->executeTool($tool, $params);
            if (($result['success'] ?? false) !== true) {
                throw new RuntimeException($tool.' failed: '.json_encode($result));
            }
        }
        file_put_contents(storage_path('app/public/release-smoke.txt'), 'public upload');
        file_put_contents(storage_path('app/public/.env'), 'private smoke fixture');
        file_put_contents(storage_path('app/public/release-smoke.php'), '<?php echo "must never run";');
        file_put_contents(storage_path('app/public/release-smoke.png'), base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aX1sAAAAASUVORK5CYII=', true));
        foreach ($paths as $path) {
            file_put_contents(storage_path('runtime/'.$path.'/.release-smoke'), 'persistent');
        }
        foreach (['api-admin.php', 'moonshine.php', '.env'] as $path) {
            file_put_contents(storage_path('runtime/'.$path), "\n# release-smoke\n", FILE_APPEND);
        }
        DB::statement('CREATE TABLE IF NOT EXISTS release_smoke (id INTEGER PRIMARY KEY, secret TEXT NOT NULL)');
        DB::table('release_smoke')->updateOrInsert(['id' => 1], ['secret' => Crypt::encryptString('restore-verification')]);
        Redis::set('release-smoke', 'persistent');
    } elseif ($mode === 'damage') {
        unlink(storage_path('runtime/resources/.release-smoke'));
        DB::table('release_smoke')->delete();
        Redis::del('release-smoke');
    } else {
        foreach ($paths as $path) {
            if (file_get_contents(storage_path('runtime/'.$path.'/.release-smoke')) !== 'persistent') {
                throw new RuntimeException('Generated state did not persist: '.$path);
            }
        }
        foreach (['api-admin.php', 'moonshine.php', '.env'] as $path) {
            if (! str_contains(file_get_contents(storage_path('runtime/'.$path)), '# release-smoke')) {
                throw new RuntimeException('Generated config did not persist: '.$path);
            }
        }
        // Every process bootstraps this file, so reaching this line also proves it still parses.
        if (array_keys(config('api-admin.custom_fields')) !== ['smoke_kept'] || ! is_file(storage_path('runtime/fields/SmokeKept.php'))) {
            throw new RuntimeException('Custom field tools did not leave the expected state');
        }
        if (Crypt::decryptString(DB::table('release_smoke')->where('id', 1)->value('secret')) !== 'restore-verification') {
            throw new RuntimeException('Database/APP_KEY restoration failed');
        }
        if (Redis::get('release-smoke') !== 'persistent') {
            throw new RuntimeException('Redis restoration failed');
        }
        if (is_writable(app_path('ApiAdmin/Providers/ApiAdminServiceProvider.php'))) {
            throw new RuntimeException('Application source must be immutable');
        }
    }
    echo 'State smoke '.$mode." passed\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'State smoke failed: '.$exception->getMessage().PHP_EOL);
    exit(1);
}
