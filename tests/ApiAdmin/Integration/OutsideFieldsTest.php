<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Integration;

use App\ApiAdmin\Fields\RestBelongsTo;
use App\ApiAdmin\Fields\RestHasMany;
use App\ApiAdmin\Fields\RestHasOne;
use App\ApiAdmin\Pages\RestIndexPage;
use App\ApiAdmin\Providers\ApiAdminServiceProvider;
use App\ApiAdmin\Resources\AbstractRestResource;
use App\ApiAdmin\ServiceConfig\ResponseMapping;
use App\ApiAdmin\ServiceConfig\ServiceConfig;
use MoonShine\Contracts\Core\DependencyInjection\CoreContract;
use MoonShine\Laravel\Providers\MoonShineServiceProvider;
use MoonShine\UI\Fields\ID;
use MoonShine\UI\Fields\Text;
use Orchestra\Testbench\TestCase;
use ReflectionClass;
use ReflectionProperty;

/**
 * Verifies that outside REST relation fields are correctly separated from
 * the main form fields — MoonShine's `formFields(withOutside: false)` filter
 * only works when fields implement RelationFieldContract.
 */
class OutsideFieldsTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            MoonShineServiceProvider::class,
            ApiAdminServiceProvider::class,
        ];
    }

    public function test_get_form_fields_excludes_outside_relation_fields_by_default(): void
    {
        $resource = $this->resourceWithRelations();

        $columns = [];
        foreach ($resource->getFormFields() as $field) {
            $columns[] = $field->getColumn();
        }

        $this->assertContains('title', $columns);
        $this->assertNotContains('author', $columns, 'RestHasOne should be outside of main form');
        $this->assertNotContains('comments', $columns, 'RestHasMany should be outside of main form');
    }

    public function test_get_form_fields_with_outside_includes_relation_fields(): void
    {
        $resource = $this->resourceWithRelations();

        $columns = [];
        foreach ($resource->getFormFields(withOutside: true) as $field) {
            $columns[] = $field->getColumn();
        }

        $this->assertContains('title', $columns);
        $this->assertContains('author', $columns);
        $this->assertContains('comments', $columns);
    }

    public function test_get_detail_fields_includes_outside_relations_by_default(): void
    {
        // REST resources include outside relation fields on detail pages so
        // RestHasOne / RestHasMany can render their inline preview alongside
        // the regular fields, instead of disappearing as native HasOne does.
        // We exercise the same code path the framework uses: a resource that
        // declares fields via the legacy detailFields() entry point.
        $resource = $this->resourceWithDetailRelations();

        $columns = [];
        foreach ($resource->getDetailFields() as $field) {
            $columns[] = $field->getColumn();
        }

        $this->assertContains('title', $columns);
        $this->assertContains('author', $columns, 'RestHasOne must appear on detail page');
        $this->assertContains('comments', $columns, 'RestHasMany must appear on detail page');
    }

    public function test_get_detail_fields_with_only_outside_returns_only_rest_relations(): void
    {
        $resource = $this->resourceWithDetailRelations();

        $columns = [];
        foreach ($resource->getDetailFields(withOutside: true, onlyOutside: true) as $field) {
            $columns[] = $field->getColumn();
        }

        $this->assertContains('author', $columns);
        $this->assertContains('comments', $columns);
        $this->assertNotContains('title', $columns);
    }

    private function resourceWithDetailRelations(): AbstractRestResource
    {
        $core = app(CoreContract::class);

        return new class($core) extends AbstractRestResource
        {
            protected function resolveServiceConfig(): ServiceConfig
            {
                return new ServiceConfig(
                    name: 'blog',
                    baseUrl: 'https://api.blog.test/v1',
                    apiKey: 'key',
                    responseMapping: new ResponseMapping,
                );
            }

            protected function endpoint(): string
            {
                return '/posts';
            }

            protected function detailFields(): iterable
            {
                return [
                    ID::make('Id', 'id'),
                    Text::make('Title', 'title'),
                    RestHasOne::make('Author', 'author')
                        ->endpoint('/posts/{parent_id}/author'),
                    RestHasMany::make('Comments', 'comments')
                        ->endpoint('/posts/{parent_id}/comments'),
                ];
            }
        };
    }

    public function test_get_outside_fields_returns_only_rest_relations(): void
    {
        $resource = $this->resourceWithRelations();

        $columns = [];
        foreach ($resource->getOutsideFields() as $field) {
            $columns[] = $field->getColumn();
        }

        $this->assertContains('author', $columns);
        $this->assertContains('comments', $columns);
        $this->assertNotContains('title', $columns);
        $this->assertNotContains('id', $columns);
    }

    public function test_get_outside_fields_attaches_parent_resource(): void
    {
        $resource = $this->resourceWithRelations();

        foreach ($resource->getOutsideFields() as $field) {
            $reflection = new ReflectionClass($field);
            $prop = $reflection->getProperty('parentResource');

            $this->assertSame($resource, $prop->getValue($field), "Parent not attached to {$field->getColumn()}");
        }
    }

    public function test_outside_relation_fields_respect_disable_outside(): void
    {
        $core = app(CoreContract::class);

        $resource = new class($core) extends AbstractRestResource
        {
            protected function resolveServiceConfig(): ServiceConfig
            {
                return new ServiceConfig(
                    name: 'blog',
                    baseUrl: 'https://api.blog.test/v1',
                    apiKey: 'key',
                    responseMapping: new ResponseMapping,
                );
            }

            protected function endpoint(): string
            {
                return '/posts';
            }

            protected function formFields(): iterable
            {
                return [
                    ID::make('Id', 'id'),
                    Text::make('Title', 'title'),
                    RestHasOne::make('Author', 'author')
                        ->endpoint('/posts/{parent_id}/author')
                        ->disableOutside(),
                ];
            }
        };

        $columns = [];
        foreach ($resource->getFormFields() as $field) {
            $columns[] = $field->getColumn();
        }

        $this->assertContains('author', $columns, 'disableOutside() should include field back in main form');
    }

    public function test_get_filters_attaches_parent_resource_to_relation_fields(): void
    {
        // MoonShine 5 reads filters from the index page, including relation wiring.
        $core = app(CoreContract::class);

        $resource = new class($core) extends AbstractRestResource
        {
            protected function resolveServiceConfig(): ServiceConfig
            {
                return new ServiceConfig(
                    name: 'tracker',
                    baseUrl: 'https://api.tracker.test',
                    apiKey: 'k',
                    responseMapping: new ResponseMapping,
                );
            }

            protected function endpoint(): string
            {
                return '/tasks';
            }
        };

        $page = new class($core) extends RestIndexPage
        {
            protected function filters(): iterable
            {
                return [
                    RestBelongsTo::make('Project', 'project_id')
                        ->endpoint('/projects'),
                ];
            }
        };

        $page->setResource($resource);
        $this->assertTrue($page->hasFilters());
        $filters = $page->getFilters();

        $projectFilter = null;
        foreach ($filters->onlyFields() as $field) {
            if ($field->getColumn() === 'filter.project_id' || $field->getColumn() === 'project_id') {
                $projectFilter = $field;
                break;
            }
        }

        $this->assertNotNull($projectFilter, 'Project filter must be present among getFilters() output');
        $this->assertInstanceOf(RestBelongsTo::class, $projectFilter);

        // parentResource is protected — verify via reflection that it's
        // wired, since without it loadOptions() early-returns silently.
        $ref = new ReflectionProperty($projectFilter, 'parentResource');
        $parent = $ref->getValue($projectFilter);

        $this->assertSame($resource, $parent, 'RestBelongsTo filter must carry the parent resource — otherwise the options dropdown renders empty');
    }

    private function resourceWithRelations(): AbstractRestResource
    {
        $core = app(CoreContract::class);

        return new class($core) extends AbstractRestResource
        {
            protected function resolveServiceConfig(): ServiceConfig
            {
                return new ServiceConfig(
                    name: 'blog',
                    baseUrl: 'https://api.blog.test/v1',
                    apiKey: 'key',
                    responseMapping: new ResponseMapping,
                );
            }

            protected function endpoint(): string
            {
                return '/posts';
            }

            protected function formFields(): iterable
            {
                return [
                    ID::make('Id', 'id'),
                    Text::make('Title', 'title'),
                    RestHasOne::make('Author', 'author')
                        ->endpoint('/posts/{parent_id}/author'),
                    RestHasMany::make('Comments', 'comments')
                        ->endpoint('/posts/{parent_id}/comments'),
                ];
            }
        };
    }
}
