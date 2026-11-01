<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;

require dirname(__DIR__).'/vendor/autoload.php';
$files = new Filesystem;

// Build-time layout only: runtime initialization copies these seeds once.
$paths = [
    'app/MoonShine/ApiAdmin/Resources' => 'resources',
    'app/MoonShine/Resources' => 'local-resources',
    'app/MoonShine/ApiAdmin/Configs' => 'configs',
    'app/MoonShine/ApiAdmin/Layouts' => 'layouts',
    'app/ApiAdmin/Components/Custom' => 'components',
    'app/ApiAdmin/Fields/Custom' => 'fields',
    'resources/views/api-admin/components/custom' => 'views',
    '.ai-factory/specs' => 'specs',
    'config/api-admin.php' => 'api-admin.php',
    'config/moonshine.php' => 'moonshine.php',
];
mkdir('/opt/api-admin-seed', 0755, true);
foreach ($paths as $path => $name) {
    if (file_exists($path)) {
        if (is_dir($path)) {
            if (! $files->copyDirectory($path, '/opt/api-admin-seed/'.$name) || ! $files->deleteDirectory($path)) {
                throw new RuntimeException('Cannot prepare runtime directory: '.$path);
            }
        } elseif (! rename($path, '/opt/api-admin-seed/'.$name)) {
            throw new RuntimeException('Cannot prepare runtime file: '.$path);
        }
    } else {
        mkdir('/opt/api-admin-seed/'.$name, 0755, true);
    }
    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }
    if (! symlink('/var/www/html/storage/runtime/'.$name, $path)) {
        throw new RuntimeException('Cannot link runtime path: '.$path);
    }
}
symlink('/var/www/html/storage/runtime/.env', '.env');
