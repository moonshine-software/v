<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\ApiAdmin\Fields\RestBelongsTo;
use App\ApiAdmin\Fields\RestBelongsToMany;
use App\ApiAdmin\Fields\RestHasMany;
use App\ApiAdmin\Fields\RestHasOne;
use App\ApiAdmin\Pages\RestIndexPage;
use App\ApiAdmin\Providers\ApiAdminServiceProvider;
use App\ApiAdmin\Resources\AbstractRestResource;
use App\ApiAdmin\ServiceConfig\ResponseMapping;
use App\ApiAdmin\ServiceConfig\ServiceConfig;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use MoonShine\Contracts\Core\DependencyInjection\CoreContract;
use MoonShine\Laravel\Models\MoonshineUser;
use MoonShine\UI\Fields\Text;
use Override;
use Tests\TestCase;

final class ResourceRelationMappingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->actingAs(new MoonshineUser(['id' => 1]), 'moonshine');
        app()->instance(ServiceConfig::class, new ServiceConfig(
            name: 'relation_mapping', baseUrl: 'https://relation-mapping.test', apiKey: 'shared-token',
            responseMapping: new ResponseMapping,
        ));
        moonshine()->resources([MappingRelationParentResource::class]);
        RestBelongsToMany::flushCaches();
    }

    public function test_belongs_to_options_and_selected_label_use_the_related_mapping_and_string_key(): void
    {
        Http::fake(['*' => Http::response(['entries' => [
            ['name' => 'timezone', 'label' => 'Timezone'], ['name' => 'site_title', 'label' => 'Site title'],
        ]])]);
        $field = $this->settingField();

        $this->assertSame(['timezone' => 'Timezone', 'site_title' => 'Site title'], $field->loadOptions());
        $field->setValue('site_title');
        $this->assertStringContainsString('Site title', (string) $field);

        Http::assertSent(static fn (Request $request): bool => $request->url() === 'https://relation-mapping.test/settings?name=site_title');
        Http::assertNotSent(static fn (Request $request): bool => str_contains($request->url(), '/settings/site_title'));
        Http::assertSent(static fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer shared-token'));
    }

    public function test_async_search_uses_the_same_mapping_and_custom_search_parameter(): void
    {
        Http::fake(['*' => Http::response(['entries' => [['name' => 'site_title', 'label' => 'Site title']]])]);
        $parent = new MappingRelationParentResource(app(CoreContract::class));

        $this->getJson('/api-admin/async-search?'.http_build_query([
            '_resource' => $parent->getUriKey(), '_relation' => 'setting_name', 'query' => 'Site',
        ]))->assertOk()->assertExactJson([['value' => 'site_title', 'label' => 'Site title']]);

        Http::assertSent(static fn (Request $request): bool => $request['term'] === 'Site'
            && ! isset($request['q']) && ! isset($request['search']));
    }

    public function test_explicit_field_value_and_wrapper_overrides_win_over_the_related_resource(): void
    {
        Http::fake(['*' => Http::response(['alternate' => [['name' => 'site_title', 'code' => 'site', 'label' => 'Site title']]])]);
        $field = $this->settingField()->valueKey('code')->dataWrapper('alternate');

        $this->assertSame(['site' => 'Site title'], $field->loadOptions());
        $this->assertSame('code', $field->getValueKey());
    }

    public function test_async_filter_uses_its_own_related_mapping_instead_of_the_same_named_form_field(): void
    {
        Http::fake(['*' => Http::response([['id' => 2, 'label' => 'Edit projects']])]);
        $parent = new MappingRelationParentResource(app(CoreContract::class));

        $this->getJson('/api-admin/async-search?'.http_build_query([
            '_resource' => $parent->getUriKey(), '_relation' => 'setting_name', '_field_scope' => 'filter', 'query' => 'Edit',
        ]))->assertOk()->assertExactJson([['value' => '2', 'label' => 'Edit projects']]);

        Http::assertSent(static fn (Request $request): bool => str_starts_with($request->url(), 'https://relation-mapping.test/permissions?')
            && $request['search'] === 'Edit');
        Http::assertSentCount(1);
    }

    public function test_parent_resource_overrides_do_not_leak_into_unlinked_endpoint_fields(): void
    {
        Http::fake(['*' => Http::response(['data' => [['id' => 8, 'label' => 'Owner']]])]);
        $field = RestBelongsTo::make('Owner', 'owner_id')->endpoint('/owners')->labelKey('label')
            ->resource(new MappingRelationParentResource(app(CoreContract::class)));

        $this->assertSame([8 => 'Owner'], $field->loadOptions());
        $this->assertSame('id', $field->getValueKey());
    }

    public function test_belongs_to_many_uses_related_root_array_options(): void
    {
        Http::fake(['*' => Http::response([['id' => 2, 'label' => 'Edit projects']])]);
        $field = RestBelongsToMany::make('Permissions', 'permissions')->endpoint('/permissions')->labelKey('label')
            ->relatedResource(MappingPermissionResource::class)
            ->resource(new MappingRelationParentResource(app(CoreContract::class)));

        $this->assertSame([2 => 'Edit projects'], $field->loadOptions());
    }

    public function test_belongs_to_many_inherits_the_related_string_primary_key(): void
    {
        Http::fake(['*' => Http::response(['entries' => [['name' => 'timezone', 'label' => 'Timezone']]])]);
        $field = RestBelongsToMany::make('Settings', 'settings')->endpoint('/settings')->labelKey('label')
            ->relatedResource(MappingSettingResource::class)
            ->resource(new MappingRelationParentResource(app(CoreContract::class)));

        $this->assertSame(['timezone' => 'Timezone'], $field->loadOptions());
        $this->assertSame('name', $field->getValueKey());
    }

    public function test_related_mapping_does_not_replace_cross_service_credentials_or_client(): void
    {
        app()->instance('api-admin.service.mapping_shop', new ServiceConfig(
            name: 'mapping_shop', baseUrl: 'https://mapping-shop.test', apiKey: 'shop-token',
            responseMapping: new ResponseMapping(dataKey: 'results'),
        ));
        Http::fake(['*' => Http::response(['entries' => [['name' => 'timezone', 'label' => 'Timezone']]])]);
        $field = RestBelongsTo::make('Setting', 'setting_name')->endpoint('/settings')->labelKey('label')
            ->targetService('mapping_shop')->relatedResource(MappingShopSettingResource::class)
            ->resource(new MappingRelationParentResource(app(CoreContract::class)));

        $this->assertSame(['timezone' => 'Timezone'], $field->loadOptions());
        Http::assertSentCount(1);
        Http::assertSent(static fn (Request $request): bool => $request->url() === 'https://mapping-shop.test/settings'
            && $request->hasHeader('Authorization', 'Bearer shop-token'));
    }

    public function test_has_many_list_detail_and_row_buttons_use_related_mapping_and_primary_key(): void
    {
        Http::fake(['*' => Http::response(['entries' => [['name' => 'timezone', 'label' => 'Timezone']]])]);
        $field = RestHasMany::make('Settings', 'settings', resource: MappingSettingResource::class)
            ->endpoint('/projects/{parent_id}/settings')->parentId(2)->fullCrud()
            ->resource(new MappingRelationParentResource(app(CoreContract::class)));

        $this->assertSame([['name' => 'timezone', 'label' => 'Timezone']], $field->loadItems());
        $this->assertSame(['name' => 'timezone', 'label' => 'Timezone'], $field->loadSingleItem('timezone'));
        $this->assertStringContainsString('_key=timezone', (string) $field->getComponent());
        Http::assertSent(static fn (Request $request): bool => ($request['name'] ?? null) === 'timezone');
    }

    public function test_has_one_loads_the_related_resource_envelope(): void
    {
        Http::fake(['*' => Http::response(['entries' => ['name' => 'timezone', 'label' => 'Timezone']])]);
        $field = RestHasOne::make('Setting', 'setting', resource: MappingSettingResource::class)
            ->endpoint('/projects/{parent_id}/setting')->parentId(2)
            ->resource(new MappingRelationParentResource(app(CoreContract::class)));

        $this->assertSame(['name' => 'timezone', 'label' => 'Timezone'], $field->loadItem());
    }

    private function settingField(): RestBelongsTo
    {
        return RestBelongsTo::make('Setting', 'setting_name')->endpoint('/settings')->labelKey('label')
            ->relatedResource(MappingSettingResource::class)
            ->resource(new MappingRelationParentResource(app(CoreContract::class)));
    }
}

class MappingRelationServiceResource extends AbstractRestResource
{
    protected function resolveServiceConfig(): ServiceConfig
    {
        return app(ServiceConfig::class);
    }

    protected function endpoint(): string
    {
        return '/projects';
    }
}

class MappingRelationParentResource extends MappingRelationServiceResource
{
    #[Override]
    protected array $responseMapping = ['data_key' => 'payload', 'primary_key' => 'slug', 'search_param_name' => 'q'];

    protected function pages(): array
    {
        return [MappingRelationIndexPage::class];
    }

    protected function formFields(): iterable
    {
        return [RestBelongsTo::make('Setting', 'setting_name')->endpoint('/settings')->labelKey('label')
            ->relatedResource(MappingSettingResource::class)];
    }
}

class MappingRelationIndexPage extends RestIndexPage
{
    protected function filters(): iterable
    {
        return [RestBelongsTo::make('Permission', 'setting_name')->endpoint('/permissions')->labelKey('label')
            ->relatedResource(MappingPermissionResource::class)];
    }
}

class MappingSettingResource extends MappingRelationServiceResource
{
    #[Override]
    protected array $responseMapping = [
        'data_key' => 'entries', 'primary_key' => 'name', 'pagination_type' => 'none',
        'detail_strategy' => 'list', 'search_param_name' => 'term',
    ];

    protected function endpoint(): string
    {
        return '/settings';
    }

    protected function indexFields(): iterable
    {
        return [Text::make('Name', 'name'), Text::make('Label', 'label')];
    }
}

class MappingPermissionResource extends MappingRelationServiceResource
{
    #[Override]
    protected array $responseMapping = ['data_key' => '', 'pagination_type' => 'none'];

    protected function endpoint(): string
    {
        return '/permissions';
    }
}

class MappingShopSettingResource extends MappingSettingResource
{
    protected function resolveServiceConfig(): ServiceConfig
    {
        return ApiAdminServiceProvider::resolveService('mapping_shop');
    }
}
