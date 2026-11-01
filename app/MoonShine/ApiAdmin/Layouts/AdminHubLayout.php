<?php

declare(strict_types=1);

namespace App\MoonShine\ApiAdmin\Layouts;

use MoonShine\Laravel\Layouts\AppLayout;
use App\ApiAdmin\Layouts\HasPanelSwitcher;

class AdminHubLayout extends AppLayout
{
    use HasPanelSwitcher;

    protected function menu(): array
    {
        return [];
    }

    protected function sidebarSlot(): array
    {
        return $this->apiAdminSidebarSlot();
    }

    protected function getLogo(bool $small = false): string
    {
        return $small ? '/vendor/moonshine/logo-small.svg' : '/vendor/moonshine/logo.svg';
    }
}
