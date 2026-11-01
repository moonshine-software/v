<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\ValueObject\PhpVersion;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/app',
        __DIR__.'/tests',
        __DIR__.'/config',
        __DIR__.'/database',
        __DIR__.'/routes',
    ])
    ->withSkip([
        __DIR__.'/app/MoonShine/ApiAdmin/Resources',
        __DIR__.'/app/MoonShine/ApiAdmin/Configs',
        __DIR__.'/app/MoonShine/ApiAdmin/Layouts',
        // Locally generated Eloquent resources; only the native user resources are tracked.
        ...array_values(array_filter(
            glob(__DIR__.'/app/MoonShine/Resources/*', GLOB_ONLYDIR) ?: [],
            static fn (string $dir): bool => ! in_array(basename($dir), ['MoonShineUser', 'MoonShineUserRole'], true),
        )),
        __DIR__.'/bootstrap/cache',
        __DIR__.'/storage',
        __DIR__.'/vendor',
        __DIR__.'/node_modules',
        __DIR__.'/public/build',
    ])
    ->withPhpVersion(PhpVersion::PHP_85)
    ->withPhpSets(php85: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
        privatization: true,
        earlyReturn: true,
        instanceOf: true,
    )
    ->withImportNames(removeUnusedImports: true)
    ->withParallel();
