<?php

declare(strict_types=1);

namespace App\ApiAdmin\Auth;

use Illuminate\Support\Facades\Auth;
use MoonShine\Contracts\Core\DependencyInjection\ConfiguratorContract;
use Throwable;

/**
 * Resolves the current admin principal for per-user upstream auth caches.
 */
final class CurrentAdminUser
{
    /**
     * @var list<string>
     */
    private const array GUARDS = [
        'api-admin-upstream',
        'moonshine',
    ];

    public static function id(): ?string
    {
        $panelGuard = app()->bound(ConfiguratorContract::class)
            ? config('moonshine.panels.'.request()->getPanelName().'.auth.guard')
            : null;
        $guards = is_string($panelGuard) ? [$panelGuard, ...self::GUARDS] : self::GUARDS;

        foreach ($guards as $guard) {
            if (! is_array(config("auth.guards.{$guard}"))) {
                continue;
            }

            try {
                $id = Auth::guard($guard)->id();
            } catch (Throwable) {
                continue;
            }

            if (is_int($id) || is_string($id)) {
                $id = (string) $id;

                if ($id !== '') {
                    return $id;
                }
            }
        }

        return null;
    }
}
