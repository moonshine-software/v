<?php

declare(strict_types=1);

namespace App\ApiAdmin\Pages;

use App\ApiAdmin\Resources\AbstractRestResource;
use MoonShine\Contracts\Core\DependencyInjection\CoreContract;
use MoonShine\Contracts\Core\DependencyInjection\FieldsContract;
use MoonShine\Crud\Collections\Fields;
use MoonShine\Crud\Pages\IndexPage;
use Override;

/**
 * @template TResource of AbstractRestResource = AbstractRestResource
 *
 * @extends IndexPage<TResource, CoreContract, Fields>
 */
abstract class RestIndexPage extends IndexPage
{
    /**
     * The table loads asynchronously, so without this check a relation-only
     * resource opens as an empty 200 page with a failing request inside.
     */
    #[Override]
    protected function onLoad(): void
    {
        $this->getResourceOrFail()->guardAgainstUnresolvedEndpoint();

        parent::onLoad();
    }

    #[Override]
    public function getFilters(): FieldsContract
    {
        return $this->getResourceOrFail()->attachParentResourceToFields(parent::getFilters());
    }
}
