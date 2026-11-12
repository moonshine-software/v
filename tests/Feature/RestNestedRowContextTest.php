<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\ApiAdmin\Fields\RestBelongsTo;
use App\ApiAdmin\Resources\AbstractRestResource;
use App\ApiAdmin\ServiceConfig\ResponseMapping;
use App\ApiAdmin\ServiceConfig\ServiceConfig;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use MoonShine\Contracts\Core\DependencyInjection\CoreContract;
use Tests\TestCase;

final class RestNestedRowContextTest extends TestCase
{
    public function test_rendering_a_relation_in_a_child_row_uses_that_row_without_fetching_the_unbound_resource(): void
    {
        Http::preventStrayRequests();
        $resource = new NestedRowMemberResource(app(CoreContract::class));
        $resource->setItemID(2);
        $field = RestBelongsTo::make('Assignee', 'assignee_id')
            ->endpoint('/owners')->endpointQuery(['project_id' => '{project_id}'])->resource($resource);
        $field->fillCast(['id' => 17, 'project_id' => 2, 'assignee_id' => null], $resource->getCaster());

        $document = new DOMDocument;
        @$document->loadHTML((string) $field);
        $selects = new DOMXPath($document)->query('//select[@name="assignee_id"]');
        $this->assertNotFalse($selects);
        $select = $selects->item(0);
        $this->assertInstanceOf(DOMElement::class, $select);
        $this->assertSame(['project_id' => '2'], $field->resolveEndpointQuery());
        Http::assertNothingSent();
    }
}

class NestedRowMemberResource extends AbstractRestResource
{
    protected function resolveServiceConfig(): ServiceConfig
    {
        return new ServiceConfig(
            name: 'nested_row', baseUrl: 'https://nested-row.example.test', apiKey: '',
            responseMapping: new ResponseMapping,
        );
    }

    protected function endpoint(): string
    {
        return '/projects/{parent_id}/members';
    }
}
