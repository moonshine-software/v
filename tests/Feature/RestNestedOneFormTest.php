<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\ApiAdmin\Fields\RestHasOne;
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
use MoonShine\Contracts\Core\TypeCasts\DataWrapperContract;
use MoonShine\Contracts\UI\ComponentContract;
use MoonShine\Contracts\UI\FieldContract;
use MoonShine\Laravel\Models\MoonshineUser;
use MoonShine\UI\Fields\Json;
use MoonShine\UI\Fields\Select;
use MoonShine\UI\Fields\Text;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class RestNestedOneFormTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(new MoonshineUser(['id' => 1]), 'moonshine');
        moonshine()->resources([NestedOneProjectResource::class]);
        Http::preventStrayRequests();
    }

    public function test_update_applies_json_only_value_and_uses_update_validation(): void
    {
        $this->fakeApi();

        $this->putJson($this->url('update'), [
            'role' => 'member',
            'model_ids' => [1 => ['value' => 'openai:gpt-4o-mini']],
        ])->assertOk();

        Http::assertSent(static fn (Request $request): bool => $request->method() === 'PUT'
            && $request->url() === 'https://nested-one.example.test/projects/2/profile'
            && $request['model_ids'] === ['openai:gpt-4o-mini']);
    }

    public function test_creation_validates_required_fields_before_writing(): void
    {
        $this->fakeApi();

        $this->postJson($this->url('store'), ['role' => 'member'])
            ->assertUnprocessable()->assertJsonValidationErrors('name');

        Http::assertNothingSent();
    }

    public function test_existing_embedded_form_uses_update_field_modifiers(): void
    {
        $this->fakeApi();
        $parent = new NestedOneProjectResource(app(CoreContract::class));
        $field = RestHasOne::make('Profile', 'profile', resource: NestedOneProfileResource::class)
            ->endpoint('/projects/{parent_id}/profile')->fullCrud()->resource($parent);
        $field->fillCast(['id' => 2], $parent->getCaster());

        $html = (string) $field->getFormComponent();
        $document = new DOMDocument;
        @$document->loadHTML($html);
        $names = new DOMXPath($document)->query('//input[@name="name"]');
        $this->assertNotFalse($names);
        $name = $names->item(0);
        $this->assertInstanceOf(DOMElement::class, $name);
        $this->assertSame('Profile', $name->getAttribute('value'));
        $this->assertFalse($name->hasAttribute('required'));
    }

    /** @return iterable<string, array{bool}> */
    public static function formModes(): iterable
    {
        yield 'create' => [false];
        yield 'update' => [true];
    }

    #[DataProvider('formModes')]
    public function test_embedded_form_uses_native_create_and_update_field_conditions(bool $isUpdate): void
    {
        Http::fake(['*' => $isUpdate
            ? Http::response(['data' => ['id' => 7, 'name' => 'Profile']])
            : Http::response([], 404)]);
        $parent = new NestedOneProjectResource(app(CoreContract::class));
        $field = RestHasOne::make('Profile', 'profile', resource: NestedOneProfileResource::class)
            ->endpoint('/projects/{parent_id}/profile')->fullCrud()->resource($parent);
        $field->fillCast(['id' => 2], $parent->getCaster());

        $document = new DOMDocument;
        @$document->loadHTML((string) $field->getFormComponent());
        $inputs = new DOMXPath($document)->query('//input[@name="code"]');
        $this->assertNotFalse($inputs);
        $input = $inputs->item(0);
        $this->assertInstanceOf(DOMElement::class, $input);
        $this->assertSame(! $isUpdate, $input->hasAttribute('required'));
        $this->assertSame($isUpdate, $input->hasAttribute('disabled'));
        Http::assertNotSent(static fn (Request $request): bool => $request->method() !== 'GET');
    }

    /** @return iterable<string, array{string, string, int}> */
    public static function successfulWrites(): iterable
    {
        foreach (['store', 'update'] as $action) {
            yield $action.' empty response' => [$action, '', 204];
            yield $action.' trailing output' => [$action, '{"message":"Saved."}'."\nUnexpected shutdown output", 200];
        }
    }

    #[DataProvider('successfulWrites')]
    public function test_successful_writes_do_not_fail_when_the_response_has_no_decodable_item(string $action, string $body, int $status): void
    {
        $this->fakeApi($body, $status);

        $method = $action === 'store' ? 'POST' : 'PUT';
        $this->json($method, $this->url($action), ['name' => 'Profile', 'role' => 'member'])
            ->assertOk()->assertJsonStructure(['message']);

        $this->assertCount(1, Http::recorded(static fn (Request $request): bool => $request->method() === $method));
    }

    /** @return iterable<string, array{string}> */
    public static function writeActions(): iterable
    {
        yield 'create' => ['store'];
        yield 'update' => ['update'];
    }

    #[DataProvider('writeActions')]
    public function test_upstream_validation_errors_are_returned_as_422(string $action): void
    {
        $this->fakeApi(json_encode([
            'message' => 'Invalid profile.',
            'errors' => ['role' => ['Role is not assignable.']],
        ], JSON_THROW_ON_ERROR), 422);

        $this->json($action === 'store' ? 'POST' : 'PUT', $this->url($action), [
            'name' => 'Profile', 'role' => 'member',
        ])->assertUnprocessable()->assertJsonValidationErrors('role');
    }

    private function fakeApi(string $body = '{"message":"Saved."}', int $status = 200): void
    {
        Http::fake(static fn (Request $request) => $request->method() === 'GET'
            ? Http::response(['data' => ['id' => 7, 'name' => 'Profile', 'role' => 'member']])
            : Http::response($body, $status));
    }

    private function url(string $action): string
    {
        $resource = new NestedOneProjectResource(app(CoreContract::class));

        return '/rest-has-one/'.$action.'?'.http_build_query([
            '_resource' => $resource->getUriKey(), '_relation' => 'profile', '_parent_id' => 2,
        ]);
    }
}

class NestedOneProjectResource extends AbstractRestResource
{
    protected function resolveServiceConfig(): ServiceConfig
    {
        return new ServiceConfig(
            name: 'nested_one', baseUrl: 'https://nested-one.example.test', apiKey: '',
            responseMapping: new ResponseMapping,
        );
    }

    protected function endpoint(): string
    {
        return '/projects';
    }

    protected function formFields(): iterable
    {
        return [RestHasOne::make('Profile', 'profile', resource: NestedOneProfileResource::class)
            ->endpoint('/projects/{parent_id}/profile')->fullCrud()];
    }
}

class NestedOneProfileResource extends NestedOneProjectResource
{
    protected function endpoint(): string
    {
        return '/projects/{parent_id}/profile';
    }

    protected function pages(): array
    {
        return [NestedOneProfileFormPage::class];
    }
}

/** @extends RestFormPage<NestedOneProfileResource> */
class NestedOneProfileFormPage extends RestFormPage
{
    /** @return list<ComponentContract|FieldContract> */
    protected function fields(): iterable
    {
        return [
            Text::make('Name', 'name')->required($this->getItem() === null),
            Text::make('Code', 'code')
                ->required($this->getItem() === null)
                ->disabled($this->getItem() !== null),
            Select::make('Role', 'role')->options(['member' => 'Member', 'contributor' => 'Contributor'])->default('contributor'),
            Json::make('Models', 'model_ids')->onlyValue(),
        ];
    }

    /** @return array<string, string> */
    protected function rules(DataWrapperContract $item): array
    {
        return ['name' => $item->getKey() === null ? 'required|string' : 'nullable|string'];
    }
}
