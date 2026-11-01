<?php

declare(strict_types=1);

namespace App\MoonShine\Resources\MoonShineUser;

use App\MoonShine\Resources\MoonShineUser\Pages\MoonShineUserFormPage;
use App\MoonShine\Resources\MoonShineUser\Pages\MoonShineUserIndexPage;
use MoonShine\Laravel\Models\MoonshineUser;
use MoonShine\MenuManager\Attributes\Group;
use MoonShine\MenuManager\Attributes\Order;
use MoonShine\MultiPanels\MultiPanelResource;
use MoonShine\Support\Attributes\Icon;
use MoonShine\Support\Enums\Action;
use MoonShine\Support\ListOf;
use Override;

/**
 * @extends MultiPanelResource<MoonshineUser, MoonShineUserIndexPage, MoonShineUserFormPage, null>
 */
#[Icon('users')]
#[Group('moonshine::ui.resource.system', 'users', translatable: true)]
#[Order(0)]
class MoonShineUserResource extends MultiPanelResource
{
    #[Override]
    protected string $model = MoonshineUser::class;

    #[Override]
    protected string $column = 'name';

    #[Override]
    protected array $with = ['moonshineUserRole'];

    #[Override]
    protected bool $simplePaginate = true;

    #[Override]
    public function getTitle(): string
    {
        return __('moonshine::ui.resource.admins_title');
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
            MoonShineUserIndexPage::class,
            MoonShineUserFormPage::class,
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
