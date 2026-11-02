<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Concerns;

use Closure;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;

/**
 * Generators and registration tools edit config files, .env and generated classes
 * under the base path. Each test gets a private copy, so neither the checkout nor
 * the shared Testbench skeleton is modified and concurrent or interrupted runs
 * cannot break each other.
 */
trait UsesIsolatedProjectRoot
{
    private string $isolatedRoot = '';

    /** @var (Closure(string): void)|null */
    private ?Closure $isolatedAutoloader = null;

    protected function useIsolatedProjectRoot(Application $app): void
    {
        $files = new Filesystem;
        $project = dirname(__DIR__, 3);
        $this->isolatedRoot = sys_get_temp_dir().'/moonshine-project-root-'.bin2hex(random_bytes(6));

        foreach ([
            'config',
            'storage/framework/views',
            'storage/framework/cache',
            'storage/logs',
            'app/ApiAdmin/Fields/Custom',
            'app/ApiAdmin/Components/Custom',
            'app/MoonShine/ApiAdmin/Resources',
            'resources/views/api-admin/components/custom',
            '.ai-factory/specs',
        ] as $directory) {
            $files->ensureDirectoryExists($this->isolatedRoot.'/'.$directory);
        }

        $files->copy($project.'/config/api-admin.php', $this->isolatedRoot.'/config/api-admin.php');
        $files->copyDirectory($project.'/stubs', $this->isolatedRoot.'/stubs');

        $app->setBasePath($this->isolatedRoot);
        $app->useConfigPath($this->isolatedRoot.'/config');

        // Composer maps App\ to the checkout; generated classes live in the private copy.
        $root = $this->isolatedRoot;
        $this->isolatedAutoloader = static function (string $class) use ($root): void {
            if (preg_match('/^App\\\\(ApiAdmin\\\\(?:Fields|Components)\\\\Custom|MoonShine\\\\ApiAdmin\\\\Resources)\\\\([\w\\\\]+)$/', $class, $match) !== 1) {
                return;
            }

            $file = $root.'/app/'.str_replace('\\', '/', $match[1].'\\'.$match[2]).'.php';
            if (is_file($file)) {
                require_once $file;
            }
        };
        spl_autoload_register($this->isolatedAutoloader);
    }

    protected function removeIsolatedProjectRoot(): void
    {
        if ($this->isolatedAutoloader !== null) {
            spl_autoload_unregister($this->isolatedAutoloader);
        }

        if ($this->isolatedRoot !== '') {
            (new Filesystem)->deleteDirectory($this->isolatedRoot);
        }
    }

    /**
     * Reads the config file the way a new PHP process does.
     *
     * @return array<string, mixed>
     */
    protected function freshApiAdminConfig(): array
    {
        $path = $this->isolatedRoot.'/config/api-admin.php';
        $copy = $path.'.'.bin2hex(random_bytes(6)).'.php';
        copy($path, $copy);

        try {
            $config = include $copy;
        } finally {
            unlink($copy);
        }

        $this->assertIsArray($config);

        /** @var array<string, mixed> $config */
        return $config;
    }
}
