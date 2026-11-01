<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Integration;

use App\ApiAdmin\Auth\UpstreamUserProvider;
use App\ApiAdmin\Fields\RestBelongsTo;
use App\ApiAdmin\Fields\RestHasMany;
use App\ApiAdmin\Fields\RestHasOne;
use App\ApiAdmin\Http\AsyncSearchContext;
use App\ApiAdmin\Providers\ApiAdminServiceProvider;
use App\ApiAdmin\Resources\AbstractRestResource;
use App\ApiAdmin\ServiceConfig\AdminAuthConfig;
use App\ApiAdmin\ServiceConfig\ResponseMapping;
use App\ApiAdmin\ServiceConfig\ServiceConfig;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use MoonShine\Contracts\Core\DependencyInjection\CoreContract;
use MoonShine\Laravel\Models\MoonshineUser;
use MoonShine\Laravel\Providers\MoonShineServiceProvider;
use MoonShine\Support\Enums\Ability;
use MoonShine\Support\Enums\Action;
use MoonShine\Support\ListOf;
use Orchestra\Testbench\TestCase;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;

class RestEndpointSecurityTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [MoonShineServiceProvider::class, ApiAdminServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('auth.guards.'.UpstreamUserProvider::guardName('secure'), ['driver' => 'session', 'provider' => 'moonshine']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        moonshine()->resources([SecureRelationResource::class]);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['data' => [['id' => 1, 'name' => 'Open']]])]);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function endpoints(): iterable
    {
        yield 'search' => ['GET', '/api-admin/async-search', 'status_id'];
        yield 'list' => ['GET', '/rest-has-many/list', 'children'];
        yield 'form' => ['GET', '/rest-has-many/form', 'children'];
        foreach (['many' => 'children', 'one' => 'profile'] as $type => $relation) {
            yield $type.' create' => ['POST', '/rest-has-'.$type.'/store', $relation];
            yield $type.' update' => ['PUT', '/rest-has-'.$type.'/update', $relation];
            yield $type.' delete' => ['DELETE', '/rest-has-'.$type.'/destroy', $relation];
        }
    }

    #[DataProvider('endpoints')]
    public function test_guests_cannot_contact_upstream(string $method, string $url, string $relation): void
    {
        $this->call($method, $url, $this->parameters($relation))->assertUnauthorized();
        Http::assertNothingSent();
    }

    #[DataProvider('endpoints')]
    public function test_resource_policy_denial_never_contacts_upstream(string $method, string $url, string $relation): void
    {
        $this->actingAs(new MoonshineUser(['id' => 1]), 'moonshine');
        config()->set('security-test.deny', true);
        $this->call($method, $url, $this->parameters($relation))->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_search_rejects_url_and_query_substitution(): void
    {
        $this->actingAs(new MoonshineUser(['id' => 1]), 'moonshine');
        foreach (['endpoint' => 'https://sink.invalid/collect', 'endpoint_query' => ['scope' => 'all'], 'service' => 'other'] as $key => $value) {
            $this->getJson('/api-admin/async-search?'.http_build_query([...$this->parameters('status_id'), $key => $value]))->assertUnprocessable();
        }
        Http::assertNothingSent();
    }

    public function test_search_uses_server_field_scope_and_ignores_raw_placeholder_values(): void
    {
        $this->actingAs(new MoonshineUser(['id' => 1]), 'moonshine');
        $this->getJson('/api-admin/async-search?'.http_build_query([...$this->parameters('status_id'), 'for_entity' => 'admin']))
            ->assertOk()->assertExactJson([['value' => '1', 'label' => 'Open']]);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.secure.test/statuses?for_entity=project');
    }

    public function test_encrypted_cascade_context_cannot_be_replaced_or_tampered_with(): void
    {
        $this->actingAs(new MoonshineUser(['id' => 1]), 'moonshine');
        $resource = new SecureRelationResource(app(CoreContract::class));
        $resource->setItemID('5');
        $field = RestBelongsTo::make('Scoped', 'scoped_status')->resource($resource);
        $token = AsyncSearchContext::encode($field, ['project_id' => '42']);
        $params = [...$this->parameters('scoped_status'), '_context' => $token, 'project_id' => '999'];
        $this->getJson('/api-admin/async-search?'.http_build_query($params))->assertOk();
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.secure.test/projects/42/statuses?project_id=42');
        Http::fake();
        $params['_context'] = $token.'corrupt';
        $this->getJson('/api-admin/async-search?'.http_build_query($params))->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_global_login_does_not_authorize_a_delegated_panel(): void
    {
        config()->set('security-test.delegated', true);
        $this->actingAs(new MoonshineUser(['id' => 1]), 'moonshine');
        $this->getJson('/api-admin/async-search?'.http_build_query($this->parameters('status_id')))->assertUnauthorized();
        Http::assertNothingSent();
    }

    public function test_panel_guard_without_delegated_credentials_is_rejected(): void
    {
        config()->set('security-test.delegated', true);
        $this->actingAs(new MoonshineUser(['id' => 1]), UpstreamUserProvider::guardName('secure'));
        $this->getJson('/api-admin/async-search?'.http_build_query($this->parameters('status_id')))->assertUnauthorized();
        Http::assertNothingSent();
    }

    public function test_custom_panel_guard_is_required_in_moonshine_login_mode(): void
    {
        config()->set('moonshine.panels.secure.auth.guard', UpstreamUserProvider::guardName('secure'));
        $this->actingAs(new MoonshineUser(['id' => 1]), 'moonshine');
        $this->getJson('/api-admin/async-search?'.http_build_query($this->parameters('status_id')))->assertUnauthorized();
        Http::assertNothingSent();
    }

    public function test_legacy_admin_login_requires_its_own_panel_session_state(): void
    {
        config()->set('security-test.legacy', true);
        $this->actingAs(new MoonshineUser(['id' => 1]), 'moonshine');
        $this->getJson('/api-admin/async-search?'.http_build_query($this->parameters('status_id')))->assertUnauthorized();
        Http::assertNothingSent();
    }

    public function test_disabled_field_and_resource_actions_are_enforced(): void
    {
        $this->actingAs(new MoonshineUser(['id' => 1]), 'moonshine');
        foreach (['POST' => 'store', 'PUT' => 'update', 'DELETE' => 'destroy'] as $method => $action) {
            foreach (['many' => 'readonly_children', 'one' => 'readonly_profile'] as $type => $relation) {
                $this->call($method, '/rest-has-'.$type.'/'.$action, $this->parameters($relation))->assertForbidden();
            }
        }
        config()->set('security-test.readonly', true);
        $this->post('/rest-has-many/store', $this->parameters('children'))->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_upstream_relation_forbidden_never_falls_back_to_global_child_endpoint(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response([], 403)]);
        $resource = new SecureRelationResource(app(CoreContract::class));
        $field = new RestHasMany('Children', 'children', resource: SecureChildResource::class)
            ->resource($resource)->parentId(5)->endpoint('/parents/{parent_id}/children');
        $this->assertNull($field->loadSingleItem(8));
        $this->assertNull($field->updateItemForParent(['name' => 'changed'], 8, 5));
        $this->assertFalse($field->deleteItemForParent(8, 5));
        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://api.secure.test/children/8');
        Http::assertSentCount(3);
    }

    public function test_missing_child_relation_cannot_be_mutated_through_global_fallback(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([
            'https://api.secure.test/parents/5/children/8' => Http::response([], 404),
            'https://api.secure.test/parents/5/children*' => Http::response(['data' => [['id' => 9]]]),
            'https://api.secure.test/children/8' => Http::response(['data' => ['id' => 8]]),
        ]);
        $resource = new SecureRelationResource(app(CoreContract::class));
        $field = new RestHasMany('Children', 'children', resource: SecureChildResource::class)
            ->resource($resource)->parentId(5)->endpoint('/parents/{parent_id}/children');
        $this->assertNull($field->loadSingleItem(8));
        $this->assertNull($field->updateItemForParent(['name' => 'changed'], 8, 5));
        $this->assertFalse($field->deleteItemForParent(8, 5));
        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://api.secure.test/children/8');
    }

    /** @return array<string, string> */
    private function parameters(string $relation): array
    {
        return ['_resource' => 'secure-relations', '_relation' => $relation, '_parent_id' => '5', '_key' => '8'];
    }
}

class SecureRelationResource extends AbstractRestResource
{
    #[Override]
    public function getUriKey(): string
    {
        return 'secure-relations';
    }

    protected function resolveServiceConfig(): ServiceConfig
    {
        return new ServiceConfig(name: 'secure', baseUrl: 'https://api.secure.test', apiKey: 'synthetic-key', responseMapping: new ResponseMapping, panel: 'secure', authType: config('security-test.legacy') === true ? 'oauth2_password' : 'bearer', adminLogin: config('security-test.legacy') === true, adminAuth: new AdminAuthConfig(mode: config('security-test.delegated') === true ? AdminAuthConfig::MODE_DELEGATED_TOKEN : AdminAuthConfig::MODE_MOONSHINE));
    }

    protected function endpoint(): string
    {
        return '/parents';
    }

    #[Override]
    protected function isCan(Ability $ability): bool
    {
        return config('security-test.deny') !== true;
    }

    #[Override]
    protected function activeActions(): ListOf
    {
        return config('security-test.readonly') === true ? new ListOf(Action::class, [Action::VIEW]) : parent::activeActions();
    }

    #[Override]
    protected function formFields(): iterable
    {
        return [
            RestBelongsTo::make('Status', 'status_id')->endpoint('/statuses')->endpointQuery(['for_entity' => 'project']),
            RestBelongsTo::make('Scoped', 'scoped_status')->endpoint('/projects/{project_id}/statuses')->endpointQuery(['project_id' => '{project_id}']),
            RestHasMany::make('Children', 'children')->endpoint('/parents/{parent_id}/children')->fullCrud(),
            RestHasOne::make('Profile', 'profile')->endpoint('/parents/{parent_id}/profile')->fullCrud(),
            RestHasMany::make('Read only children', 'readonly_children')->endpoint('/parents/{parent_id}/children'),
            RestHasOne::make('Read only profile', 'readonly_profile')->endpoint('/parents/{parent_id}/profile'),
        ];
    }
}

class SecureChildResource extends SecureRelationResource
{
    #[Override]
    protected function endpoint(): string
    {
        return '/children';
    }
}
