<?php

declare(strict_types=1);

namespace App\MoonShine\Resources\MoonShineUserRole\Pages;

use App\MoonShine\Resources\MoonShineUserRole\MoonShineUserRoleResource;
use MoonShine\Contracts\UI\FieldContract;
use MoonShine\Laravel\Pages\Crud\IndexPage;
use MoonShine\UI\Fields\ID;
use MoonShine\UI\Fields\Text;
use Override;

/**
 * @extends IndexPage<MoonShineUserRoleResource>
 */
final class MoonShineUserRoleIndexPage extends IndexPage
{
    /**
     * @return list<FieldContract>
     */
    #[Override]
    protected function fields(): iterable
    {
        return [
            ID::make()->sortable(),
            Text::make(__('moonshine::ui.resource.role_name'), 'name'),
        ];
    }
}
