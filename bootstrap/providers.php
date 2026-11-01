<?php

declare(strict_types=1);

use App\ApiAdmin\Providers\ApiAdminServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\MoonShineServiceProvider;

return [
    AppServiceProvider::class,
    MoonShineServiceProvider::class,
    ApiAdminServiceProvider::class,
];
