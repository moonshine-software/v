<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\ApiAdmin\Fields\RestHasMany;
use App\ApiAdmin\Pages\RestFormPage;
use App\ApiAdmin\Resources\AbstractRestResource;
use App\ApiAdmin\ServiceConfig\ResponseMapping;
use App\ApiAdmin\ServiceConfig\ServiceConfig;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use MoonShine\Contracts\Core\DependencyInjection\CoreContract;
use MoonShine\Contracts\UI\ComponentContract;
use MoonShine\Contracts\UI\FieldContract;
use MoonShine\Laravel\Models\MoonshineUser;
use MoonShine\UI\Fields\Json;
use MoonShine\UI\Fields\Select;
use MoonShine\UI\Fields\Text;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class RestNestedMemberFormTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(new MoonshineUser(['id' => 1]), 'moonshine');
        moonshine()->resources([NestedMemberProjectResource::class]);
        Http::preventStrayRequests();
    }

    public function test_modal_uses_the_existing_role_when_the_api_has_no_get_detail_route(): void
    {
        Http::fake(static fn (Request $request) => Http::response(
            str_contains($request->url(), '/members/17')
                ? ['message' => 'Only PUT and DELETE are supported.']
                : ['data' => [['id' => 17, 'project_role' => 'member']]],
            str_contains($request->url(), '/members/17') ? 405 : 200,
        ));

        $response = $this->get($this->url('form'));
        $response->assertOk();

        $document = new DOMDocument;
        $html = $response->getContent();
        $this->assertIsString($html);
        @$document->loadHTML($html);
        $selected = new DOMXPath($document)->query('//select[@name="project_role"]/option[@selected]');

        $this->assertNotFalse($selected);
        $this->assertSame(1, $selected->length);
        $option = $selected->item(0);
        $this->assertInstanceOf(DOMElement::class, $option);
        $this->assertSame('member', $option->getAttribute('value'));
    }

    public function test_missing_member_does_not_render_an_edit_form_with_creation_defaults(): void
    {
        Http::fake(static fn (Request $request) => Http::response(
            str_contains($request->url(), '/members/17') ? [] : ['data' => []],
            str_contains($request->url(), '/members/17') ? 405 : 200,
        ));

        $this->get($this->url('form'))->assertNotFound();
    }

    /** @return iterable<string, array{bool}> */
    public static function formModes(): iterable
    {
        yield 'create' => [false];
        yield 'update' => [true];
    }

    #[DataProvider('formModes')]
    public function test_nested_form_uses_native_create_and_update_field_conditions(bool $isUpdate): void
    {
        Http::fake(['*' => Http::response(['data' => [['id' => 17, 'project_role' => 'member']]])]);
        $response = $this->get($this->url('form', $isUpdate));
        $response->assertOk();
        $html = $response->getContent();
        $this->assertIsString($html);
        $document = new DOMDocument;
        @$document->loadHTML($html);
        $inputs = new DOMXPath($document)->query('//input[@name="user_id"]');
        $this->assertNotFalse($inputs);
        $input = $inputs->item(0);
        $this->assertInstanceOf(DOMElement::class, $input);
        $this->assertSame(! $isUpdate, $input->hasAttribute('required'));
        $this->assertSame($isUpdate, $input->hasAttribute('disabled'));
        Http::assertNotSent(static fn (Request $request): bool => $request->method() !== 'GET');
    }

    /** @return iterable<string, array{string, int}> */
    public static function successfulResponses(): iterable
    {
        yield 'trailing output' => ['{"message":"Member updated."}'."\nUnexpected shutdown output", 200];
        yield 'empty response' => ['', 204];
    }

    #[DataProvider('successfulResponses')]
    public function test_a_successful_update_is_not_reported_as_failed(string $body, int $status): void
    {
        Http::fake(static fn (Request $request) => $request->method() === 'PUT'
            ? Http::response($body, $status)
            : Http::response(['data' => [['id' => 17, 'project_role' => 'member']]]));

        $this->put($this->url('update'), ['project_role' => 'member'])
            ->assertOk()->assertJsonStructure(['message']);

        $writes = Http::recorded(static fn (Request $request): bool => $request->method() === 'PUT');
        $this->assertCount(1, $writes);
        Http::assertSent(static fn (Request $request): bool => $request->method() === 'PUT'
            && $request->url() === 'https://nested-members.example.test/projects/2/members/17'
            && $request['project_role'] === 'member');
    }

    public function test_nested_save_applies_json_only_value_before_calling_the_api(): void
    {
        Http::fake(static fn (Request $request) => Http::response($request->method() === 'PUT'
            ? ['message' => 'Member updated.']
            : ['data' => [['id' => 17, 'project_role' => 'member']]]));

        $this->put($this->url('update'), [
            'project_role' => 'member',
            'model_ids' => [1 => ['value' => 'openai:gpt-4o-mini']],
        ])->assertOk();

        Http::assertSent(static fn (Request $request): bool => $request->method() === 'PUT'
            && $request['model_ids'] === ['openai:gpt-4o-mini']);
    }

    public function test_upstream_validation_stays_a_field_error_instead_of_a_generic_server_error(): void
    {
        Http::fake(static fn (Request $request) => $request->method() === 'PUT'
            ? Http::response([
                'message' => 'The role is invalid.',
                'errors' => ['project_role' => ['Choose an assignable role.']],
            ], 422)
            : Http::response(['data' => [['id' => 17, 'project_role' => 'member']]]));

        $this->putJson($this->url('update'), ['project_role' => 'invalid'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('project_role');

        Http::assertSent(static fn (Request $request): bool => $request->method() === 'PUT');
    }

    private function url(string $action, bool $isUpdate = true): string
    {
        $resource = new NestedMemberProjectResource(app(CoreContract::class));

        return '/rest-has-many/'.$action.'?'.http_build_query([
            '_resource' => $resource->getUriKey(),
            '_relation' => 'members',
            '_parent_id' => 2,
            '_key' => $isUpdate ? 17 : null,
        ]);
    }
}

class NestedMemberProjectResource extends AbstractRestResource
{
    protected function resolveServiceConfig(): ServiceConfig
    {
        return new ServiceConfig(
            name: 'nested_members', baseUrl: 'https://nested-members.example.test', apiKey: '',
            responseMapping: new ResponseMapping,
        );
    }

    protected function endpoint(): string
    {
        return '/projects';
    }

    protected function formFields(): iterable
    {
        return [RestHasMany::make('Members', 'members', resource: NestedMemberResource::class)
            ->endpoint('/projects/{parent_id}/members')
            ->fullCrud()];
    }
}

class NestedMemberResource extends NestedMemberProjectResource
{
    protected function endpoint(): string
    {
        return '/projects/{parent_id}/members';
    }

    protected function getDetailStrategyOverride(): ?string
    {
        return 'list';
    }

    protected function pages(): array
    {
        return [NestedMemberFormPage::class];
    }
}

/** @extends RestFormPage<NestedMemberResource> */
class NestedMemberFormPage extends RestFormPage
{
    /** @return list<ComponentContract|FieldContract> */
    protected function fields(): iterable
    {
        return [
            Text::make('User', 'user_id')
                ->required($this->getItem() === null)
                ->disabled($this->getItem() !== null),
            Select::make('Role', 'project_role')
                ->options(['member' => 'Member', 'contributor' => 'Contributor'])
                ->default('contributor'),
            Json::make('Models', 'model_ids')->onlyValue(),
        ];
    }
}
