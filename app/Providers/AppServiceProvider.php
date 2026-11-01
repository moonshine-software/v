<?php

declare(strict_types=1);

namespace App\Providers;

use App\MoonShine\Http\Requests\DelegatedLoginFormRequest;
use Illuminate\Support\ServiceProvider;
use MoonShine\Laravel\Http\Requests\LoginFormRequest;
use Override;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    #[Override]
    public function register(): void
    {
        $this->app->bind(LoginFormRequest::class, DelegatedLoginFormRequest::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
