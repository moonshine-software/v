<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Integration;

use App\ApiAdmin\Fields\RestBelongsTo;
use App\ApiAdmin\Fields\RestHasMany;
use App\ApiAdmin\Fields\RestHasOne;
use App\ApiAdmin\Providers\ApiAdminServiceProvider;
use App\ApiAdmin\Resources\AbstractRestResource;
use App\ApiAdmin\ServiceConfig\ResponseMapping;
use App\ApiAdmin\ServiceConfig\ServiceConfig;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Http;
use MoonShine\Contracts\Core\DependencyInjection\CoreContract;
use MoonShine\Contracts\UI\ActionButtonContract;
use MoonShine\Core\TypeCasts\MixedDataWrapper;
use MoonShine\Laravel\Providers\MoonShineServiceProvider;
use MoonShine\UI\Fields\Text;
use Orchestra\Testbench\TestCase;
use ReflectionMethod;

class HostedRelationUrlsTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [MoonShineServiceProvider::class, ApiAdminServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('api-admin.hosted.mode', 'hosted');
    }

    public function test_relation_components_render_tenant_scoped_urls(): void
    {
        Http::preventStrayRequests();
        $route = new Route('GET', 't/{tenant}/secure', fn (): string => '');
        $route->bind(Request::create('/t/acme/secure'));
        request()->setRouteResolver(fn (): Route => $route);
        $resource = new HostedUrlResource(app(CoreContract::class));
        $resource->setItemID('5');
        $resource->setItem(['id' => 5]);

        $belongs = RestBelongsTo::make('Status', 'status_id')->resource($resource)->endpoint('/statuses');
        $url = new ReflectionMethod($belongs, 'buildAsyncSearchUrl')->invoke($belongs);
        $this->assertIsString($url);
        $this->assertStringContainsString('/t/acme/api-admin/async-search?', $url);
        $this->assertStringNotContainsString('endpoint=', $url);

        $many = RestHasMany::make('Children', 'children')->resource($resource)->parentId(5)
            ->endpoint('/parents/{parent_id}/children')->fields([Text::make('Name', 'name')])->fullCrud()->setValue([]);
        $manyHtml = str_replace('\\/', '/', (string) $many->getComponent());
        $this->assertStringContainsString('/t/acme/rest-has-many/list?', $manyHtml);
        $createButton = new ReflectionMethod($many, 'getCreateButton')->invoke($many);
        $this->assertInstanceOf(ActionButtonContract::class, $createButton);
        $this->assertStringContainsString('/t/acme/rest-has-many/form?', str_replace('\\/', '/', (string) $createButton));

        $one = RestHasOne::make('Profile', 'profile')->resource($resource)->parentId(5)
            ->endpoint('/parents/{parent_id}/profile')->fields([Text::make('Name', 'name')])->fullCrud()->setValue([]);
        $this->assertStringContainsString('/t/acme/rest-has-one/store?', str_replace('\\/', '/', (string) $one->getFormComponent()));
        $one = RestHasOne::make('Profile', 'profile')->resource($resource)->parentId(5)
            ->endpoint('/parents/{parent_id}/profile')->fields([Text::make('Name', 'name')])->fullCrud();
        $one->fillData(new MixedDataWrapper(['id' => 5, 'profile' => ['id' => 8, 'name' => 'Profile']], 5));
        $this->assertStringContainsString('/t/acme/rest-has-one/update?', str_replace('\\/', '/', (string) $one->getFormComponent()));
        $this->assertStringContainsString('/t/acme/rest-has-one/destroy?', str_replace('\\/', '/', (string) $one->getFormComponent()));
        Http::assertNothingSent();
    }

    public function test_hosted_routes_do_not_leave_global_relation_aliases(): void
    {
        $this->get('/rest-has-many/list')->assertNotFound();
        $this->post('/rest-has-one/store')->assertNotFound();
        $this->get('/api-admin/async-search')->assertNotFound();
    }
}

class HostedUrlResource extends AbstractRestResource
{
    protected function resolveServiceConfig(): ServiceConfig
    {
        return new ServiceConfig(name: 'secure', baseUrl: 'https://api.secure.test', apiKey: 'synthetic', responseMapping: new ResponseMapping, tenantId: '1');
    }

    protected function endpoint(): string
    {
        return '/parents';
    }
}
