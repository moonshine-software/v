<?php

declare(strict_types=1);

namespace App\MoonShine\Resources\MoonShineUserRole;

use App\MoonShine\Resources\MoonShineUserRole\Pages\MoonShineUserRoleFormPage;
use App\MoonShine\Resources\MoonShineUserRole\Pages\MoonShineUserRoleIndexPage;
use MoonShine\Laravel\Models\MoonshineUserRole;
use MoonShine\MenuManager\Attributes\Group;
use MoonShine\MenuManager\Attributes\Order;
use MoonShine\MultiPanels\MultiPanelResource;
use MoonShine\Support\Attributes\Icon;
use MoonShine\Support\Enums\Action;
use MoonShine\Support\ListOf;
use Override;

/**
 * @extends MultiPanelResource<MoonshineUserRole, MoonShineUserRoleIndexPage, MoonShineUserRoleFormPage, null>
 */
#[Icon('bookmark')]
#[Group('moonshine::ui.resource.system', 'users', translatable: true)]
#[Order(1)]
class MoonShineUserRoleResource extends MultiPanelResource
{
    #[Override]
    protected string $model = MoonshineUserRole::class;

    #[Override]
    protected string $column = 'name';

    #[Override]
    protected bool $createInModal = true;

    #[Override]
    protected bool $detailInModal = true;

    #[Override]
    protected bool $editInModal = true;

    #[Override]
    protected bool $cursorPaginate = true;

    #[Override]
    public function getTitle(): string
    {
        return __('moonshine::ui.resource.role');
    }

    #[Override]
    protected function activeActions(): ListOf
    {
        return parent::activeActions()->except(Action::VIEW);
    }

    #[Override]
    protected function pages(): array
    {
        return [
            MoonShineUserRoleIndexPage::class,
            MoonShineUserRoleFormPage::class,
        ];
    }

    #[Override]
    protected function search(): array
    {
        return [
            'id',
            'name',
        ];
    }
}
