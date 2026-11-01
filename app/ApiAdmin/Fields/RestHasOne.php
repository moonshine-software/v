<?php

declare(strict_types=1);

namespace App\ApiAdmin\Fields;

use App\ApiAdmin\Fields\Concerns\AsRestRelationField;
use App\ApiAdmin\Resources\AbstractRestResource;
use App\ApiAdmin\Tenancy\TenantRoutes;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Log;
use MoonShine\Contracts\Core\TypeCasts\DataCasterContract;
use MoonShine\Contracts\Core\TypeCasts\DataWrapperContract;
use MoonShine\Contracts\UI\ActionButtonContract;
use MoonShine\Contracts\UI\ComponentContract;
use MoonShine\Contracts\UI\FieldContract;
use MoonShine\Contracts\UI\FormBuilderContract;
use MoonShine\Contracts\UI\RelationFieldContract;
use MoonShine\Support\AlpineJs;
use MoonShine\Support\Enums\Ability;
use MoonShine\Support\Enums\HttpMethod;
use MoonShine\Support\Enums\JsEvent;
use MoonShine\UI\Components\ActionButton;
use MoonShine\UI\Components\FormBuilder;
use MoonShine\UI\Components\Layout\Box;
use MoonShine\UI\Components\Table\TableBuilder;
use MoonShine\UI\Fields\Field;
use MoonShine\UI\Fields\Hidden;
use Override;
use Throwable;

/**
 * REST-based HasOne field — like MoonShine's native HasOne but over HTTP.
 *
 * Renders an embedded FormBuilder in the parent resource's form page bottomLayer,
 * allowing create/edit/delete of the related one-to-one item via a parent-scoped
 * endpoint. On preview/detail pages renders a simple vertical preview table.
 *
 * Requires a related AbstractRestResource for form fields and caster.
 *
 * Usage:
 *   RestHasOne::make('Author', 'author', resource: AuthorResource::class)
 *       ->endpoint('/posts/{parent_id}/author')
 *       ->creatable()->editable()->removable()
 *
 * @method static static make(string $label, ?string $column = null, ?Closure $formatted = null, class-string<AbstractRestResource>|null $resource = null)
 */
class RestHasOne extends Field implements RelationFieldContract
{
    use AsRestRelationField;

    #[Override]
    protected string $view = 'api-admin::fields.rest-has-one';

    #[Override]
    protected bool $isGroup = true;

    #[Override]
    protected bool $hasOld = false;

    protected string $apiEndpoint = '';

    protected string $parentKeyParam = 'parent_id';

    protected ?string $dataWrapper = null;

    protected ?AbstractRestResource $parentResource = null;

    protected ?AbstractRestResource $relatedResource = null;

    /** @var class-string<AbstractRestResource>|null */
    protected ?string $relatedResourceClass = null;

    protected bool $isCreatable = false;

    protected bool $isEditable = false;

    protected bool $isRemovable = false;

    /** @var list<FieldContract> */
    protected array $configuredFields = [];

    protected string|int|null $resolvedParentId = null;

    /** @var array<string, mixed>|null */
    protected ?array $loadedItem = null;

    protected bool $itemLoaded = false;

    /**
     * @param class-string<AbstractRestResource>|null $resource
     */
    public function __construct(
        string $label,
        ?string $column = null,
        ?Closure $formatted = null,
        ?string $resource = null,
    ) {
        parent::__construct($label, $column, $formatted);

        if ($resource !== null) {
            $this->relatedResourceClass = $resource;
        }
    }

    public function isToOne(): bool
    {
        return true;
    }

    #[Override]
    public function hasWrapper(): bool
    {
        return false;
    }

    public function endpoint(string $endpoint): static
    {
        $this->apiEndpoint = $endpoint;

        return $this;
    }

    public function parentKeyParam(string $param): static
    {
        $this->parentKeyParam = $param;

        return $this;
    }

    public function dataWrapper(?string $wrapper): static
    {
        $this->dataWrapper = $wrapper;

        return $this;
    }

    public function resource(AbstractRestResource $resource): static
    {
        $this->parentResource = $resource;

        return $this;
    }

    public function parentId(string|int|null $parentId): static
    {
        $this->resolvedParentId = $parentId;

        return $this;
    }

    /**
     * @param list<FieldContract> $fields
     */
    public function fields(array $fields): static
    {
        $this->configuredFields = $fields;

        return $this;
    }

    public function creatable(): static
    {
        $this->isCreatable = true;

        return $this;
    }

    public function editable(): static
    {
        $this->isEditable = true;

        return $this;
    }

    public function removable(): static
    {
        $this->isRemovable = true;

        return $this;
    }

    public function fullCrud(): static
    {
        return $this->creatable()->editable()->removable();
    }

    public function allowsAction(Ability $ability): bool
    {
        return match ($ability) {
            Ability::CREATE => $this->isCreatable,
            Ability::UPDATE => $this->isEditable,
            Ability::DELETE => $this->isRemovable,
            Ability::VIEW, Ability::VIEW_ANY => true,
            default => false,
        };
    }

    public function getApiEndpoint(): string
    {
        return $this->apiEndpoint;
    }

    public function getRelatedResource(): ?AbstractRestResource
    {
        if (! $this->relatedResource instanceof AbstractRestResource && $this->relatedResourceClass !== null) {
            $instance = app($this->relatedResourceClass);
            $this->relatedResource = $instance instanceof AbstractRestResource ? $instance : null;
        }

        return $this->relatedResource;
    }

    public function getParentResource(): ?AbstractRestResource
    {
        return $this->parentResource;
    }

    /**
     * @param array<string, mixed>|null $context
     */
    protected function resolveEndpoint(?string $parentId = null, ?array $context = null): ?string
    {
        return $this->resolveRelationEndpoint($this->apiEndpoint, [
            $this->parentKeyParam => $parentId,
            'parent_id' => $parentId,
        ], $context);
    }

    /**
     * @return list<FieldContract>
     */
    protected function getFormFieldsList(): array
    {
        if ($this->configuredFields !== []) {
            return $this->configuredFields;
        }

        $resource = $this->getRelatedResource();
        if ($resource instanceof AbstractRestResource) {
            return $resource->getFormFields()->onlyFields()
                |> iterator_to_array(...)
                |> array_values(...);
        }

        return [];
    }

    /**
     * @return list<FieldContract>
     */
    protected function getPreviewFieldsList(): array
    {
        if ($this->configuredFields !== []) {
            return $this->configuredFields;
        }

        $resource = $this->getRelatedResource();
        if ($resource instanceof AbstractRestResource) {
            return $resource->getDetailFields()->onlyFields()
                |> iterator_to_array(...)
                |> array_values(...);
        }

        return [];
    }

    // ── HTTP operations ──────────────────────────────────────────────

    /**
     * @return array<string, mixed>|null
     */
    public function loadItem(?DataWrapperContract $parentItem = null): ?array
    {
        if (! $this->parentResource instanceof AbstractRestResource || $this->apiEndpoint === '') {
            return null;
        }

        $parentId = $parentItem?->getKey() ?? $this->getParentId();

        if ($parentId === null || $parentId === '') {
            return null;
        }

        $endpoint = $this->resolveEndpoint((string) $parentId, $this->relationEndpointContextFromItem($parentItem));
        if ($endpoint === null) {
            return null;
        }
        $target = $this->resolveRelationTarget($this->dataWrapper);
        if (! $target instanceof RestRelationTarget) {
            return null;
        }

        try {
            Log::debug('RestHasOne::loadItem', [
                'field' => $this->getColumn(),
                'source_service' => $target->sourceService(),
                'target_service' => $target->targetService(),
                'endpoint' => $endpoint,
                'query_keys' => array_keys($this->resolveEndpointQuery()),
            ]);

            $response = $target->httpClient()->get($endpoint, $this->resolveEndpointQuery());

            if ($response->notFound()) {
                return null;
            }

            $response->throw();

            $json = $response->json();

            $item = data_get($json, $target->dataWrapper(), $json);

            if (! is_array($item) || ! $this->isAssocArray($item)) {
                return null;
            }

            /** @var array<string, mixed> $item */
            return $item;
        } catch (Throwable $e) {
            Log::error('RestHasOne::loadItem — failed', [
                'field' => $this->getColumn(),
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>|null
     */
    public function createItemForParent(array $data, string|int|null $parentId): ?array
    {
        $target = $this->resolveRelationTarget($this->dataWrapper);
        if (! $target instanceof RestRelationTarget) {
            return null;
        }

        $endpoint = $this->resolveEndpoint((string) $parentId);
        if ($endpoint === null) {
            return null;
        }

        try {
            $response = $target->httpClient()->post($endpoint, $data);
            $response->throw();

            $body = $response->json();

            if (! is_array($body)) {
                return null;
            }

            /** @var array<string, mixed> $body */
            return $body;
        } catch (Throwable $e) {
            Log::error('RestHasOne::createItemForParent — failed', [
                'field' => $this->getColumn(),
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>|null
     */
    public function updateItemForParent(array $data, string|int|null $parentId): ?array
    {
        $target = $this->resolveRelationTarget($this->dataWrapper);
        if (! $target instanceof RestRelationTarget) {
            return null;
        }

        $endpoint = $this->resolveEndpoint((string) $parentId);
        if ($endpoint === null) {
            return null;
        }

        try {
            $response = $target->httpClient()->put($endpoint, $data);
            $response->throw();

            $body = $response->json();

            if (! is_array($body)) {
                return null;
            }

            /** @var array<string, mixed> $body */
            return $body;
        } catch (Throwable $e) {
            Log::error('RestHasOne::updateItemForParent — failed', [
                'field' => $this->getColumn(),
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function deleteItemForParent(string|int|null $parentId): bool
    {
        $target = $this->resolveRelationTarget($this->dataWrapper);
        if (! $target instanceof RestRelationTarget) {
            return false;
        }

        $endpoint = $this->resolveEndpoint((string) $parentId);
        if ($endpoint === null) {
            return false;
        }

        try {
            $response = $target->httpClient()->delete($endpoint);
            if ($response->successful()) {
                return true;
            }

            return $response->notFound();
        } catch (Throwable $e) {
            Log::error('RestHasOne::deleteItemForParent — failed', [
                'field' => $this->getColumn(),
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    // ── Value resolution ─────────────────────────────────────────────

    #[Override]
    protected function resolveValue(): mixed
    {
        if ($this->itemLoaded) {
            return $this->loadedItem;
        }

        $data = $this->getData();

        if ($data instanceof DataWrapperContract && $data->getKey() !== null) {
            $existing = data_get($data->toArray(), $this->getColumn());

            if (is_array($existing) && $existing !== []) {
                /** @var array<string, mixed> $existingAssoc */
                $existingAssoc = $existing;
                $this->loadedItem = $existingAssoc;
                $this->itemLoaded = true;

                return $this->loadedItem;
            }

            $this->loadedItem = $this->loadItem($data);
            $this->itemLoaded = true;

            return $this->loadedItem;
        }

        return null;
    }

    protected function getParentId(): string|int|null
    {
        return $this->getData()?->getKey() ?? $this->resolvedParentId;
    }

    protected function getParentResourceUri(): string
    {
        return $this->parentResource?->getUriKey() ?? '';
    }

    public function getFormEventName(): string
    {
        return AlpineJs::event(JsEvent::FRAGMENT_UPDATED, $this->getFormComponentName());
    }

    public function getFormComponentName(): string
    {
        return 'rest-has-one-'.$this->getColumn();
    }

    // ── Components ───────────────────────────────────────────────────

    public function getComponent(): ComponentContract
    {
        return $this->getFormComponent();
    }

    public function getFormComponent(): FormBuilderContract
    {
        $parentId = $this->getParentId();
        $item = $this->getValue();
        $isUpdate = is_array($item) && $item !== [];

        $action = $isUpdate
            ? route('moonshine.rest-has-one.update', [
                ...TenantRoutes::routeParams(),
                '_resource' => $this->getParentResourceUri(),
                '_relation' => $this->getColumn(),
                '_parent_id' => $parentId,
            ])
            : route('moonshine.rest-has-one.store', [
                ...TenantRoutes::routeParams(),
                '_resource' => $this->getParentResourceUri(),
                '_relation' => $this->getColumn(),
                '_parent_id' => $parentId,
            ]);

        $fields = $this->getFormFieldsList();

        if (! $isUpdate) {
            $fields = array_filter(
                $fields,
                fn (FieldContract $f): bool => $f->getColumn() !== 'id',
            )
                |> array_values(...);
        }

        if ($isUpdate) {
            $fields[] = Hidden::make('_method')->setValue('PUT');
        }

        $caster = $this->getRelatedResource()?->getCaster()
            ?? $this->parentResource?->getCaster();

        $form = FormBuilder::make($action)
            ->name($this->getFormComponentName())
            ->fields($fields)
            ->when(
                $caster instanceof DataCasterContract,
                function (FormBuilderContract $f) use ($isUpdate, $item, $caster): void {
                    if ($caster instanceof DataCasterContract) {
                        $f->fillCast($isUpdate ? $item : [], $caster);
                    }
                },
            )
            ->submit(__('moonshine::ui.save'), ['class' => 'btn-primary'])
            ->switchFormMode(true, [$this->getFormEventName()])
            ->withoutRedirect();

        if ($this->isRemovable && $isUpdate && $parentId !== null) {
            $form->buttons([$this->getDeleteButton($parentId)]);
        }

        return $form;
    }

    protected function getDeleteButton(string|int $parentId): ActionButtonContract
    {
        $url = route('moonshine.rest-has-one.destroy', [
            ...TenantRoutes::routeParams(),
            '_resource' => $this->getParentResourceUri(),
            '_relation' => $this->getColumn(),
            '_parent_id' => $parentId,
        ]);

        return ActionButton::make(__('moonshine::ui.delete'), url: $url)
            ->icon('trash')
            ->error()
            ->async(method: HttpMethod::DELETE, events: [
                $this->getFormEventName(),
            ])
            ->withConfirm();
    }

    protected function getTableComponent(): TableBuilder
    {
        $item = $this->getValue();
        $items = is_array($item) && $item !== [] ? [$item] : [];

        return TableBuilder::make(items: $items)
            ->name($this->getColumn())
            ->fields($this->getPreviewFieldsList())
            ->vertical()
            ->simple()
            ->preview()
            ->withNotFound();
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function viewData(): array
    {
        $canCrud = $this->isCreatable || $this->isEditable || $this->isRemovable;

        $inner = (! $canCrud || ! $this->parentResource instanceof AbstractRestResource || $this->apiEndpoint === '')
            ? $this->getTableComponent()
            : $this->getFormComponent();

        return [
            'component' => Box::make($this->getLabel(), [$inner]),
        ];
    }

    #[Override]
    protected function resolvePreview(): string
    {
        // Use getValue() (not toValue()) so resolveValue() runs and lazily fetches
        // the related item from the API when it isn't embedded in the parent payload.
        $item = $this->getValue();

        if (! is_array($item) || $item === []) {
            return '';
        }

        $rendered = $this->getTableComponent()->render();
        if ($rendered instanceof View) {
            return $rendered->render();
        }

        return is_string($rendered) ? $rendered : '';
    }

    #[Override]
    protected function resolveOnApply(): ?Closure
    {
        return static fn (mixed $item): mixed => $item;
    }

    /**
     * @param array<int|string, mixed> $value
     */
    private function isAssocArray(array $value): bool
    {
        if ($value === []) {
            return false;
        }

        return array_keys($value) !== range(0, count($value) - 1);
    }
}
