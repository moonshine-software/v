<?php

declare(strict_types=1);

namespace App\MoonShine\Layouts;

use MoonShine\ColorManager\ColorManager;
use MoonShine\ColorManager\Palettes\PurplePalette;
use MoonShine\Contracts\ColorManager\ColorManagerContract;
use MoonShine\Contracts\ColorManager\PaletteContract;
use MoonShine\Laravel\Layouts\AppLayout;
use Override;

final class MoonShineLayout extends AppLayout
{
    /**
     * @var null|class-string<PaletteContract>
     */
    #[Override]
    protected ?string $palette = PurplePalette::class;

    #[Override]
    protected function assets(): array
    {
        return [
            ...parent::assets(),
        ];
    }

    #[Override]
    protected function menu(): array
    {
        return [
            ...$this->autoloadMenu(),
        ];
    }

    /**
     * @param ColorManager $colorManager
     */
    #[Override]
    protected function colors(ColorManagerContract $colorManager): void
    {
        parent::colors($colorManager);

        // $colorManager->primary('#00000');
    }
}
