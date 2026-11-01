<?php

declare(strict_types=1);

namespace App\ApiAdmin\Layouts;

use MoonShine\Laravel\Layouts\AppLayout;
use Override;

/**
 * Layout for local Eloquent panels: the panel's own resources, the panel
 * switcher shared with REST panels, and the system resources AppLayout provides.
 */
class LocalPanelLayout extends AppLayout
{
    use HasPanelSwitcher;

    #[Override]
    protected function menu(): array
    {
        return [
            ...$this->panelMenu(),
            ...parent::menu(),
        ];
    }

    #[Override]
    protected function sidebarSlot(): array
    {
        return $this->apiAdminSidebarSlot();
    }
}
