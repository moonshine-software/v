<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;

use function Orchestra\Testbench\default_skeleton_path;

require __DIR__.'/../vendor/autoload.php';

/*
 * Every PHPUnit process gets a private Testbench skeleton and spec root.
 * Tests write config files, .env, logs and spec sidecars under base_path();
 * a shared skeleton in vendor/ or the checkout's .ai-factory/specs would let
 * simultaneous runs overwrite and delete each other's files.
 */
$files = new Filesystem;
$root = sys_get_temp_dir().'/moonshine-v-phpunit-'.bin2hex(random_bytes(8));
$skeleton = default_skeleton_path();

foreach ($files->directories($skeleton) as $directory) {
    $name = is_string($directory) ? basename($directory) : '';
    if ($name !== '' && ! in_array($name, ['storage', '.ai-factory'], true)) {
        $files->copyDirectory($skeleton.'/'.$name, $root.'/laravel/'.$name);
    }
}

foreach ($files->files($skeleton, true) as $file) {
    $files->copy($file->getPathname(), $root.'/laravel/'.$file->getFilename());
}

foreach (['app/public', 'framework/cache/data', 'framework/sessions', 'framework/testing', 'framework/views', 'logs'] as $directory) {
    $files->ensureDirectoryExists($root.'/laravel/storage/'.$directory);
}

$environment = [
    // Testbench's application base path. Testbench unsets it after each test
    // class, so Tests\PrivateTestbenchSkeleton restores it before every test.
    'TESTBENCH_APP_BASE_PATH' => $root.'/laravel',
    // Read by config/api-admin.php in tests that boot the real application.
    'API_ADMIN_SPECS_PATH' => $root.'/app/.ai-factory/specs',
];

foreach ($environment as $name => $value) {
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
    putenv($name.'='.$value);
}

$pid = getmypid();
register_shutdown_function(static function () use ($files, $root, $pid): void {
    // Child processes started by tests inherit the handler; only the owner cleans up.
    if (getmypid() === $pid) {
        $files->deleteDirectory($root);
    }
});
