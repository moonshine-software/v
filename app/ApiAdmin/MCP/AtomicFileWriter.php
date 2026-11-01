<?php

declare(strict_types=1);

namespace App\ApiAdmin\MCP;

use Illuminate\Support\Facades\File;
use RuntimeException;

/** All generated-file mutations participate in the current generation journal. */
final class AtomicFileWriter
{
    public static function put(string $path, string $contents): int
    {
        self::ensureDirectoryExists(dirname($path));
        $target = self::writeTarget($path);
        GenerationTransaction::capture($target);
        $temporary = tempnam(dirname($target), '.api-admin-');
        if ($temporary === false) {
            throw new RuntimeException('Cannot allocate atomic generation file.');
        }

        try {
            $mode = is_file($target) ? fileperms($target) : 0640;
            if ($mode === false || ! chmod($temporary, $mode & 0777)) {
                throw new RuntimeException('Cannot preserve generated file permissions.');
            }
            $written = file_put_contents($temporary, $contents);
            if ($written === false || $written !== strlen($contents)) {
                throw new RuntimeException('Incomplete generated file write.');
            }
            if (! rename($temporary, $target)) {
                throw new RuntimeException('Cannot atomically replace generated file.');
            }
            if (function_exists('opcache_invalidate')) {
                opcache_invalidate($target, true);
            }

            return $written;
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    public static function ensureDirectoryExists(string $path): void
    {
        if (is_dir($path)) {
            return;
        }
        self::ensureDirectoryExists(dirname($path));
        GenerationTransaction::capture($path);
        if (! mkdir($path, 0750) && ! is_dir($path)) {
            throw new RuntimeException('Cannot create generated directory.');
        }
    }

    public static function delete(string $path): bool
    {
        GenerationTransaction::capture($path);
        if (! file_exists($path) && ! is_link($path)) {
            return true;
        }
        if (! File::delete($path)) {
            throw new RuntimeException('Cannot delete generated file.');
        }

        return true;
    }

    public static function deleteDirectory(string $path): bool
    {
        GenerationTransaction::capture($path, recursive: true);
        if (! is_dir($path)) {
            return true;
        }
        if (! File::deleteDirectory($path)) {
            throw new RuntimeException('Cannot delete generated directory.');
        }

        return true;
    }

    /** Preserve deployment symlinks, replacing their destination atomically. */
    private static function writeTarget(string $path): string
    {
        for ($depth = 0; is_link($path); $depth++) {
            if ($depth >= 40) {
                throw new RuntimeException('Generated path contains a symlink cycle.');
            }
            $link = readlink($path);
            if ($link === false) {
                throw new RuntimeException('Cannot resolve generated path.');
            }
            $path = str_starts_with($link, '/') ? $link : dirname($path).'/'.$link;
        }
        self::ensureDirectoryExists(dirname($path));
        $directory = $path
            |> dirname(...)
            |> realpath(...);
        if ($directory === false) {
            throw new RuntimeException('Cannot resolve generated directory.');
        }

        return $directory.'/'.basename($path);
    }
}
