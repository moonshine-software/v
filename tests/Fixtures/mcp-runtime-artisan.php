<?php

declare(strict_types=1);

define('MOONSHINE_V_PROCESS_ENV', getenv());

use App\ApiAdmin\Providers\ApiAdminServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use MoonShine\Laravel\Providers\MoonShineServiceProvider;
use MoonShine\MultiPanels\Providers\MultiPanelServiceProvider;
use Symfony\Component\Console\Input\ArgvInput;

$project = getenv('MOONSHINE_TEST_PROJECT');
if (! is_string($project) || $project === '') {
    exit(1);
}

require $project.'/vendor/autoload.php';

spl_autoload_register(static function (string $class): void {
    if (! str_starts_with($class, 'App\\MoonShine\\ApiAdmin\\')) {
        return;
    }
    $file = __DIR__.'/app/'.str_replace('\\', '/', substr($class, 4)).'.php';
    if (is_file($file)) {
        require_once $file;
    }
}, prepend: true);

$app = Application::configure(basePath: __DIR__)
    ->withProviders([MoonShineServiceProvider::class, MultiPanelServiceProvider::class, ApiAdminServiceProvider::class])
    ->create();
$app->booted(static function (): void {
    Http::fake(['https://*mcp-runtime.test/*' => static function (Request $request) {
        if ($request->method() === 'GET') {
            return parse_url($request->url(), PHP_URL_PATH) === '/settings'
                ? Http::response(['data' => [['name' => 'site_title', 'value' => 'Mapping survived']]])
                : Http::response(['message' => 'No detail endpoint'], 405);
        }

        return Http::response([
            'id' => 17, ...$request->data(), 'origin' => $request->url(), 'process_marker' => getenv('MOONSHINE_TEST_OS_MARKER'),
        ], 201);
    }]);
});

exit($app->handleCommand(new ArgvInput));
