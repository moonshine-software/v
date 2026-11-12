<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\ApiAdmin\Resources\MultiPanelRestResource;
use App\ApiAdmin\ServiceConfig\ServiceConfig;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use MoonShine\Laravel\Models\MoonshineUser;
use MoonShine\UI\Fields\Text;
use Override;
use Tests\TestCase;

final class ResourceMappingSaveRouteTest extends TestCase
{
    public function test_browser_crud_routes_use_the_resource_string_primary_key_and_detail_strategy(): void
    {
        $this->actingAs(new MoonshineUser(['id' => 1]), 'moonshine');
        app()->instance(ServiceConfig::class, ServiceConfig::fromArray([
            'name' => 'mapping-route', 'base_url' => 'https://mapping-route.test', 'api_key' => '', 'auth_type' => 'none',
            'response_mapping' => ['primary_key' => 'id', 'pagination_type' => 'cursor'],
        ]));
        moonshine()->resources([ResourceMappingSaveRouteResource::class]);
        Http::preventStrayRequests();
        $state = ['site_title' => ['name' => 'site_title', 'value' => 'Original']];
        Http::fake(static function (Request $request) use (&$state) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($request->method() === 'GET' && $path === '/settings') {
                return Http::response(['data' => array_values($state)]);
            }
            if ($request->method() === 'POST' && $path === '/settings') {
                $data = $request->data();
                $name = $data['name'] ?? null;
                if (! is_string($name)) {
                    return Http::response(['errors' => ['name' => ['Required']]], 422);
                }
                $state[$name] = $data;

                return Http::response(['data' => $data], 201);
            }
            if ($request->method() === 'PUT' && $path === '/settings/site_title') {
                $state['site_title'] = ['name' => 'site_title', ...$request->data()];

                return Http::response(null, 204);
            }
            if ($request->method() === 'DELETE' && $path === '/settings/site_title') {
                unset($state['site_title']);

                return Http::response(null, 204);
            }

            return Http::response(null, 405);
        });
        $this->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->postJson('/admin/resource/resource-mapping-save-route-resource/crud', ['name' => 'site_subtitle', 'value' => 'Subtitle', '_without-redirect' => true])
            ->assertSuccessful();
        $this->assertSame('Subtitle', $state['site_subtitle']['value']);

        $this->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->putJson('/admin/resource/resource-mapping-save-route-resource/crud/site_title', ['name' => 'site_title', 'value' => 'Updated', '_without-redirect' => true])
            ->assertSuccessful();
        $this->assertSame('Updated', $state['site_title']['value']);

        $this->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->deleteJson('/admin/resource/resource-mapping-save-route-resource/crud/site_title')
            ->assertSuccessful();
        $this->assertArrayNotHasKey('site_title', $state);
        Http::assertNotSent(static fn (Request $request): bool => $request->method() === 'GET'
            && str_contains($request->url(), '/settings/'));
    }
}

class ResourceMappingSaveRouteResource extends MultiPanelRestResource
{
    #[Override]
    protected string $panel = 'admin';

    #[Override]
    protected array $responseMapping = ['primary_key' => 'name', 'pagination_type' => 'none', 'detail_strategy' => 'list'];

    #[Override]
    public function getRedirectAfterDelete(): string
    {
        return '/admin';
    }

    protected function resolveServiceConfig(): ServiceConfig
    {
        return app(ServiceConfig::class);
    }

    protected function endpoint(): string
    {
        return '/settings';
    }

    protected function formFields(): iterable
    {
        return [Text::make('Name', 'name'), Text::make('Value', 'value')];
    }
}
