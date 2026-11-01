<?php

declare(strict_types=1);

namespace App\ApiAdmin\Http\Middleware;

use MoonShine\Laravel\Http\Middleware\Authenticate;
use Override;

/**
 * Laravel's middleware priority runs authentication before MultiPanelMiddleware,
 * so the panel's guard has to be applied here. Otherwise a panel with its own
 * guard is checked against the hub guard and redirects to its login page forever.
 */
final class PanelAuthenticate extends Authenticate
{
    /**
     * @param array<string|null> $guards
     */
    #[Override]
    protected function authenticate($request, array $guards): void
    {
        $panelAuth = config('moonshine.panels.'.$request->getPanelName().'.auth');

        if (is_array($panelAuth)) {
            moonshineConfig()->set('auth', $panelAuth);
        }

        parent::authenticate($request, $guards);
    }
}
