<?php

declare(strict_types=1);

namespace App\ApiAdmin\MCP;

use Closure;
use DirectoryIterator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/** Serializes generated state changes and restores failed multi-file operations. */
final class GenerationTransaction
{
    private static ?self $active = null;

    /** @var array<string, array{kind: string, content: string, mode: int}> */
    private array $journal = [];

    private bool $rollbackOnly = false;

    /** @param Closure(): array<string, mixed> $operation
     * @return array<string, mixed>
     */
    public static function run(Closure $operation): array
    {
        $active = self::$active;
        if ($active instanceof GenerationTransaction) {
            try {
                $result = $operation();
                $active->rollbackOnly = $active->rollbackOnly || ($result['success'] ?? false) !== true;

                return $result;
            } catch (Throwable $exception) {
                $active->rollbackOnly = true;

                throw $exception;
            }
        }

        $transaction = new self;

        return $transaction->execute($operation);
    }

    public static function capture(string $path, bool $recursive = false): void
    {
        self::$active?->snapshot($path, $recursive);
    }

    /** @param Closure(): array<string, mixed> $operation
     * @return array<string, mixed>
     */
    private function execute(Closure $operation): array
    {
        $lockDirectory = storage_path('api-admin/locks');
        File::ensureDirectoryExists($lockDirectory);
        $lock = fopen($lockDirectory.'/generation.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('Cannot open generation lock.');
        }
        $timeout = config('api-admin.generation_lock_timeout', 10);
        $timeout = is_numeric($timeout) ? max(0, min(60, (float) $timeout)) : 10;
        $deadline = microtime(true) + $timeout;
        while (! flock($lock, LOCK_EX | LOCK_NB)) {
            if (microtime(true) >= $deadline) {
                fclose($lock);

                throw new RuntimeException('Generation is busy; retry after the current operation completes.');
            }
            usleep(20_000);
        }

        $config = config()->all();
        $environment = getenv();
        $env = $_ENV;
        $server = $_SERVER;
        $bindings = [];
        foreach (app()->getBindings() as $abstract => $binding) {
            if (is_string($abstract) && is_array($binding)
                && ($binding['concrete'] ?? null) instanceof Closure
                && is_bool($binding['shared'] ?? null)) {
                $bindings[$abstract] = ['concrete' => $binding['concrete'], 'shared' => $binding['shared']];
            }
        }
        $hosted = config('api-admin.hosted.mode', 'local') === 'hosted';
        $databaseStarted = false;
        self::$active = $this;

        try {
            if ($hosted) {
                DB::beginTransaction();
                $databaseStarted = true;
            }
            $result = $operation();
            if (($result['success'] ?? false) !== true || $this->rollbackOnly) {
                if ($databaseStarted) {
                    DB::rollBack();
                    $databaseStarted = false;
                }
                $this->restore();
                $this->restoreProcess($config, $environment, $env, $server, $bindings);
                if (($result['success'] ?? false) === true) {
                    return ['success' => false, 'code' => 'GENERATION_ROLLED_BACK', 'error' => 'A nested generation operation failed; no changes were saved.'];
                }

                return $result;
            }
            if ($databaseStarted) {
                DB::commit();
                $databaseStarted = false;
            }

            return $result;
        } catch (Throwable $exception) {
            if ($databaseStarted) {
                DB::rollBack();
            }
            $this->restore();
            $this->restoreProcess($config, $environment, $env, $server, $bindings);
            Log::warning('Generation transaction rolled back', ['exception' => $exception::class]);

            throw $exception;
        } finally {
            self::$active = null;
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function snapshot(string $path, bool $recursive): void
    {
        $directory = $path
            |> dirname(...)
            |> realpath(...);
        if ($directory !== false) {
            $path = $directory.'/'.basename($path);
        }
        if (array_key_exists($path, $this->journal)) {
            return;
        }
        $mode = file_exists($path) ? fileperms($path) : 0640;
        $kind = is_link($path) ? 'link' : (is_file($path) ? 'file' : (is_dir($path) ? 'directory' : 'missing'));
        $content = match ($kind) {
            'link' => readlink($path),
            'file' => file_get_contents($path),
            default => '',
        };
        if ($content === false || $mode === false) {
            throw new RuntimeException('Cannot snapshot generated state.');
        }
        $this->journal[$path] = ['kind' => $kind, 'content' => $content, 'mode' => $mode & 0777];
        if ($recursive && $kind === 'directory') {
            foreach (new DirectoryIterator($path) as $entry) {
                if (! $entry->isDot()) {
                    $this->snapshot($entry->getPathname(), true);
                }
            }
        }
    }

    private function restore(): void
    {
        // Stop journaling the rollback itself.
        self::$active = null;
        $paths = array_keys($this->journal);
        usort($paths, fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        foreach ($paths as $path) {
            if (is_link($path) || is_file($path)) {
                if (! unlink($path)) {
                    throw new RuntimeException('Cannot roll back generated file.');
                }
            } elseif (is_dir($path) && $this->journal[$path]['kind'] === 'missing') {
                if (! rmdir($path)) {
                    throw new RuntimeException('Cannot roll back generated directory.');
                }
            }
        }
        foreach (array_reverse($paths) as $path) {
            $entry = $this->journal[$path];
            if ($entry['kind'] === 'directory') {
                AtomicFileWriter::ensureDirectoryExists($path);
                chmod($path, $entry['mode']);
            } elseif ($entry['kind'] === 'file') {
                AtomicFileWriter::put($path, $entry['content']);
                chmod($path, $entry['mode']);
            } elseif ($entry['kind'] === 'link') {
                AtomicFileWriter::ensureDirectoryExists(dirname($path));
                if (! symlink($entry['content'], $path)) {
                    throw new RuntimeException('Cannot restore generated symlink.');
                }
            }
        }
    }

    /**
     * @param array<array-key, mixed> $config
     * @param array<string, string> $environment
     * @param array<array-key, mixed> $env
     * @param array<array-key, mixed> $server
     * @param array<string, array{concrete: Closure, shared: bool}> $bindings
     */
    private function restoreProcess(array $config, array $environment, array $env, array $server, array $bindings): void
    {
        foreach (array_keys(config()->all()) as $key) {
            config()->offsetUnset($key);
        }
        config()->set($config);
        foreach (array_keys(getenv()) as $key) {
            if (! array_key_exists($key, $environment)) {
                putenv($key);
            }
        }
        foreach ($environment as $key => $value) {
            putenv($key.'='.$value);
        }
        $_ENV = $env;
        $_SERVER = $server;
        foreach (array_keys(array_merge(app()->getBindings(), $bindings)) as $abstract) {
            if (! is_string($abstract)) {
                continue;
            }
            if (! str_starts_with($abstract, 'api-admin.service.') && ! str_starts_with($abstract, 'api-admin.tenant.')) {
                continue;
            }
            app()->offsetUnset($abstract);
            if (isset($bindings[$abstract])) {
                app()->bind($abstract, $bindings[$abstract]['concrete'], $bindings[$abstract]['shared']);
            }
        }
    }
}
