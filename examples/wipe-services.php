<?php

declare(strict_types=1);

/**
 * Wipe all locally generated ApiAdmin services so `run-mcp-scenario.php` can regenerate from scratch.
 *
 * Removes:
 *   - app/MoonShine/ApiAdmin/{Configs,Layouts,Resources}/* (generated PHP)
 *   - generated resource specs
 *   - generated service/panel state from storage/api-admin/generated
 *
 * Preserves tracked files: AdminHubLayout and directory placeholders.
 */

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\File;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();

$appBase = realpath(__DIR__.'/..');

echo "Wiping generated ApiAdmin files...\n";

wipeDir("{$appBase}/app/MoonShine/ApiAdmin/Configs", ['.gitkeep']);
wipeDir("{$appBase}/app/MoonShine/ApiAdmin/Layouts", ['AdminHubLayout.php']);
wipeDir("{$appBase}/app/MoonShine/ApiAdmin/Resources", ['.gitkeep']);
wipeDir("{$appBase}/.ai-factory/specs", []);

echo "Removing generated service and panel state...\n";
File::delete(array_filter([
    config('api-admin.generated_services_path'),
    config('api-admin.generated_panels_path'),
], is_string(...)));

echo "Wipe complete.\n";

function wipeDir(string $dir, array $keep): void
{
    if (! is_dir($dir)) {
        return;
    }

    foreach (new DirectoryIterator($dir) as $item) {
        if ($item->isDot()) {
            continue;
        }

        $name = $item->getFilename();
        if (in_array($name, $keep, true)) {
            continue;
        }

        $path = $item->getPathname();
        if ($item->isDir()) {
            File::deleteDirectory($path);
        } else {
            @unlink($path);
        }
    }
}
