<?php

declare(strict_types=1);

namespace App\ApiAdmin\Fields;

use App\ApiAdmin\Fields\Concerns\AsRestRelationField;
use App\ApiAdmin\Http\PathSegment;
use App\ApiAdmin\Resources\AbstractRestResource;
use App\ApiAdmin\Tenancy\TenantRoutes;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use MoonShine\Contracts\Core\TypeCasts\DataWrapperContract;
use MoonShine\Contracts\UI\ActionButtonContract;
use MoonShine\Contracts\UI\ComponentContract;
use MoonShine\Contracts\UI\FieldContract;
use MoonShine\Contracts\UI\RelationFieldContract;
use MoonShine\Support\AlpineJs;
use MoonShine\Support\Enums\Ability;
use MoonShine\Support\Enums\HttpMethod;
use MoonShine\Support\Enums\JsEvent;
use MoonShine\UI\Components\ActionButton;
use MoonShine\UI\Components\Table\TableBuilder;
use MoonShine\UI\Fields\Field;
use Override;
use Throwable;

/**
 * REST-based HasMany field — like MoonShine's native HasMany but over HTTP.
 *
 * Requires a related AbstractRestResource for fields, caster, and CRUD.
 * Renders TableBuilder with create/edit/delete buttons via modal forms.
 *
 * Usage:
 *   RestHasMany::make('Comments', 'comments', resource: CommentResource::class)
 *       ->endpoint('/posts/{parent_id}/comments')
 *       ->creatable()
 *
 * @method static static make(string $label, ?string $column = null, ?Closure $formatted = null, class-string<AbstractRestResource>|null $resource = null)
 */
class RestHasMany extends Field implements RelationFieldContract
{
    use AsRestRelationField;

    #[Override]
    protected string $view = 'api-admin::fields.rest-has-many';

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

    protected int $limit = 25;

    protected int $previewLimit = 3;

    protected ?Closure $previewLabel = null;

    /** @var array<string, mixed> */
    protected array $queryParams = [];

    protected bool $isCreatable = false;

    protected bool $isEditable = false;

    protected bool $isRemovable = false;

    /** @var list<FieldContract> */
    protected array $configuredFields = [];

    protected string|int|null $resolvedParentId = null;

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
        return false;
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

    /**
     * Set the parent resource (called by AbstractRestResource::attachParentResourceToFields).
     */
    public function resource(AbstractRestResource $resource): static
    {
        $this->parentResource = $resource;

        return $this;
    }

    public function limit(int $limit): static
    {
        $this->limit = $limit;

        return $this;
    }

    public function previewLimit(int $limit): static
    {
        $this->previewLimit = max(0, $limit);

        return $this;
    }

    /**
     * Override how an item is rendered in the inline preview on index/detail pages.
     *
     * @param Closure(array<string, mixed>): string $fn
     */
    public function previewLabel(Closure $fn): static
    {
        $this->previewLabel = $fn;

        return $this;
    }

    /**
     * @param array<string, mixed> $params
     */
    public function queryParams(array $params): static
    {
        $this->queryParams = $params;

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

    /**
     * Shorthand: enable all CRUD operations.
     */
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

    /**
     * Get or resolve the related resource instance.
     */
    public function getRelatedResource(): ?AbstractRestResource
    {
        if (! $this->relatedResource instanceof AbstractRestResource && $this->relatedResourceClass !== null) {
            $instance = app($this->relatedResourceClass);
            $this->relatedResource = $instance instanceof AbstractRestResource ? $instance : null;
        }

        return $this->relatedResource;
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
    protected function getTableFields(): array
    {
        if ($this->configuredFields !== []) {
            return $this->configuredFields;
        }

        $resource = $this->getRelatedResource();
        if ($resource instanceof AbstractRestResource) {
            return $resource->getIndexFields()->onlyFields()
                |> iterator_to_array(...)
                |> array_values(...);
        }

        return [];
    }

    // ── HTTP operations ──────────────────────────────────────────────

    /**
     * @return list<array<string, mixed>>
     */
    public function loadItems(?DataWrapperContract $parentItem = null): array
    {
        if (! $this->parentResource instanceof AbstractRestResource || $this->apiEndpoint === '') {
            return [];
        }

        $parentId = $parentItem?->getKey() ?? $this->getParentId();
        $endpoint = $this->resolveEndpoint((string) $parentId, $this->relationEndpointContextFromItem($parentItem));
        if ($endpoint === null) {
            return [];
        }
        $target = $this->resolveRelationTarget($this->dataWrapper);
        if (! $target instanceof RestRelationTarget) {
            return [];
        }
        // endpointQuery carries scope params (e.g. `?for_entity=project`,
        // or `?project_id={project_id}` templated against the parent form);
        // user pagination/search from buildListQuery layers on top — scope
        // params are the floor. `{col}` placeholders are substituted via
        // resolveEndpointQuery() against the parent resource's current
        // item + request state.
        $query = array_merge($this->resolveEndpointQuery(), $this->buildListQuery());

        try {
            Log::debug('RestHasMany::loadItems', [
                'field' => $this->getColumn(),
                'source_service' => $target->sourceService(),
                'target_service' => $target->targetService(),
                'endpoint' => $endpoint,
                'query_keys' => array_keys($query),
            ]);

            $response = $target->httpClient()->get($endpoint, $query);

            $response->throw();
            $json = $response->json();

            $items = data_get($json, $target->dataWrapper(), $json);

            if (! is_array($items)) {
                return [];
            }

            /** @var list<array<string, mixed>> $normalized */
            $normalized = [];
            foreach (array_values($items) as $item) {
                if (is_array($item)) {
                    /** @var array<string, mixed> $itemAssoc */
                    $itemAssoc = $item;
                    $normalized[] = $itemAssoc;
                }
            }

            return $normalized;
        } catch (Throwable $e) {
            Log::error('RestHasMany::loadItems — failed', [
                'field' => $this->getColumn(),
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function loadSingleItem(string|int $key): ?array
    {
        if (! $this->parentResource instanceof AbstractRestResource || $this->apiEndpoint === '') {
            return null;
        }

        $endpoint = $this->resolveEndpoint((string) $this->getParentId());
        if ($endpoint === null) {
            return null;
        }
        $target = $this->resolveRelationTarget($this->dataWrapper);
        if (! $target instanceof RestRelationTarget) {
            return null;
        }

        $endpoint = rtrim($endpoint, '/').'/'.PathSegment::encode($key);

        try {
            $response = $target->httpClient()->get(
                $endpoint,
                $this->resolveEndpointQuery(),
            );

            if ($response->notFound()) {
                $member = $this->findItemInLoadedCollection($key);
                if ($member === null) {
                    return null;
                }

                return $this->loadSingleItemFromRelatedResource($key) ?? $member;
            }

            $response->throw();

            return $this->extractSingleItemFromResponse($response->json(), $key, $target->dataWrapper());
        } catch (Throwable $e) {
            Log::error('RestHasMany::loadSingleItem — failed', [
                'field' => $this->getColumn(),
                'key' => $key,
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
            Log::error('RestHasMany::createItemForParent — failed', [
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
    public function updateItemForParent(array $data, string|int $key, string|int|null $parentId): ?array
    {
        $target = $this->resolveRelationTarget($this->dataWrapper);
        if (! $target instanceof RestRelationTarget) {
            return null;
        }

        $baseEndpoint = $this->resolveEndpoint((string) $parentId);
        if ($baseEndpoint === null) {
            return null;
        }

        $endpoint = rtrim($baseEndpoint, '/').'/'.PathSegment::encode($key);

        try {
            $response = $target->httpClient()->put($endpoint, $data);
            if ($response->notFound()) {
                $this->parentId($parentId);

                return $this->findItemInLoadedCollection($key) !== null
                    ? $this->updateItemUsingRelatedResource($data, $key)
                    : null;
            }

            $response->throw();

            $body = $response->json();

            if (! is_array($body)) {
                return null;
            }

            /** @var array<string, mixed> $body */
            return $body;
        } catch (Throwable $e) {
            Log::error('RestHasMany::updateItemForParent — failed', [
                'field' => $this->getColumn(),
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function deleteItemForParent(string|int $key, string|int|null $parentId): bool
    {
        $target = $this->resolveRelationTarget($this->dataWrapper);
        if (! $target instanceof RestRelationTarget) {
            return false;
        }

        $baseEndpoint = $this->resolveEndpoint((string) $parentId);
        if ($baseEndpoint === null) {
            return false;
        }

        $endpoint = rtrim($baseEndpoint, '/').'/'.PathSegment::encode($key);

        try {
            $response = $target->httpClient()->delete($endpoint);

            if ($response->notFound()) {
                $this->parentId($parentId);

                return $this->findItemInLoadedCollection($key) !== null
                    && $this->deleteItemUsingRelatedResource($key);
            }

            return $response->successful();
        } catch (Throwable $e) {
            Log::error('RestHasMany::deleteItemForParent — failed', [
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
        $raw = $this->toValue(withDefault: false);
        if (is_array($raw) && $raw !== []) {
            return collect($raw);
        }

        $data = $this->getData();
        $items = $this->loadItems($data instanceof DataWrapperContract ? $data : null);

        return collect($items);
    }

    // ── Buttons ──────────────────────────────────────────────────────

    /**
     * Get the table component name used for async events.
     */
    public function getListComponentName(): string
    {
        return 'rest-has-many-'.$this->getColumn();
    }

    /**
     * Get the event name that triggers table refresh (MoonShine format).
     */
    public function getListEventName(): string
    {
        return AlpineJs::event(JsEvent::TABLE_UPDATED, $this->getListComponentName());
    }

    protected function getParentId(): string|int|null
    {
        return $this->getData()?->getKey() ?? $this->resolvedParentId;
    }

    protected function getParentResourceUri(): string
    {
        return $this->parentResource?->getUriKey() ?? '';
    }

    protected function getCreateButton(): ?ActionButtonContract
    {
        if (! $this->isCreatable || $this->getParentId() === null) {
            return null;
        }

        $formUrl = route('moonshine.rest-has-many.form', [
            ...TenantRoutes::routeParams(),
            '_resource' => $this->getParentResourceUri(),
            '_relation' => $this->getColumn(),
            '_parent_id' => $this->getParentId(),
        ]);

        return ActionButton::make(__('moonshine::ui.add'), url: $formUrl)
            ->icon('plus')
            ->primary()
            ->async()
            ->inModal(
                title: __('moonshine::ui.create'),
                content: '',
                name: 'rest-has-many-create-'.$this->getColumn(),
            );
    }

    /**
     * @return list<ActionButtonContract>
     */
    protected function getRowButtons(): array
    {
        $buttons = [];

        if ($this->isEditable) {
            $buttons[] = ActionButton::make(
                '',
                url: fn (mixed $data): string => route('moonshine.rest-has-many.form', [
                    ...TenantRoutes::routeParams(),
                    '_resource' => $this->getParentResourceUri(),
                    '_relation' => $this->getColumn(),
                    '_parent_id' => $this->getParentId(),
                    '_key' => data_get($data, 'id'),
                ]),
            )
                ->icon('pencil')
                ->square()
                ->async()
                ->inModal(
                    title: __('moonshine::ui.edit'),
                    content: '',
                    name: function (mixed $data): string {
                        $id = data_get($data, 'id');

                        return 'rest-has-many-edit-'.$this->getColumn().'-'.(is_scalar($id) ? (string) $id : '');
                    },
                );
        }

        if ($this->isRemovable) {
            $buttons[] = ActionButton::make(
                '',
                url: fn (mixed $data): string => route('moonshine.rest-has-many.destroy', [
                    ...TenantRoutes::routeParams(),
                    '_resource' => $this->getParentResourceUri(),
                    '_relation' => $this->getColumn(),
                    '_parent_id' => $this->getParentId(),
                    '_key' => data_get($data, 'id'),
                ]),
            )
                ->icon('trash')
                ->square()
                ->error()
                ->async(method: HttpMethod::DELETE, events: [
                    $this->getListEventName(),
                ])
                ->withConfirm();
        }

        return $buttons;
    }

    // ── Component building ───────────────────────────────────────────

    public function getComponent(): ComponentContract
    {
        return $this->getTableComponent();
    }

    protected function getTableComponent(): TableBuilder
    {
        $items = $this->getValue();

        if ($items instanceof Collection) {
            $items = $items->toArray();
        }

        if (! is_array($items)) {
            $items = [];
        }

        $table = TableBuilder::make(items: $items)
            ->name($this->getColumn())
            ->fields($this->getTableFields())
            ->withNotFound();

        $rowButtons = $this->getRowButtons();
        if ($rowButtons !== []) {
            $table->buttons($rowButtons);
        }

        // Async refresh support
        if ($this->getParentId() !== null) {
            $listUrl = route('moonshine.rest-has-many.list', [
                ...TenantRoutes::routeParams(),
                '_resource' => $this->getParentResourceUri(),
                '_relation' => $this->getColumn(),
                '_parent_id' => $this->getParentId(),
            ]);

            $table->async($listUrl)->name($this->getListComponentName());
        }

        return $table;
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function viewData(): array
    {
        return [
            'component' => $this->getTableComponent(),
            'label' => $this->getLabel(),
            'isCreatable' => $this->isCreatable,
            'createButton' => $this->getCreateButton(),
        ];
    }

    #[Override]
    protected function resolvePreview(): string
    {
        $items = $this->getValue();

        if ($items instanceof Collection) {
            $items = $items->all();
        }

        if (! is_array($items) || $items === []) {
            return '';
        }

        $total = count($items);
        $slice = array_slice(array_values($items), 0, $this->previewLimit);

        $labels = [];
        foreach ($slice as $item) {
            if (! is_array($item)) {
                continue;
            }
            /** @var array<string, mixed> $itemAssoc */
            $itemAssoc = $item;
            $labels[] = e($this->renderPreviewLabel($itemAssoc));
        }

        if ($labels === []) {
            return '';
        }

        $rendered = implode(', ', $labels);
        $overflow = $total - count($slice);

        return $overflow > 0 ? $rendered.', +'.$overflow.' more' : $rendered;
    }

    /**
     * @param array<string, mixed> $item
     */
    protected function renderPreviewLabel(array $item): string
    {
        if ($this->previewLabel instanceof Closure) {
            $custom = ($this->previewLabel)($item);
            if (is_string($custom) && $custom !== '') {
                return $custom;
            }
        }

        foreach (['title', 'name', 'label', 'slug'] as $key) {
            $value = $item[$key] ?? null;
            if (is_scalar($value) && (string) $value !== '') {
                return (string) $value;
            }
        }

        $id = $item['id'] ?? null;
        if (is_scalar($id)) {
            return '#'.$id;
        }

        return '#?';
    }

    #[Override]
    protected function resolveOnApply(): ?Closure
    {
        return static fn (mixed $item): mixed => $item;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findItemInLoadedCollection(string|int $key): ?array
    {
        $items = $this->loadItems();

        foreach ($items as $item) {
            $id = data_get($item, 'id');
            if (is_scalar($id) && (string) $id === (string) $key) {
                return $item;
            }
        }

        return null;
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

    /**
     * @return array<string, mixed>
     */
    private function buildListQuery(): array
    {
        $query = ['per_page' => $this->limit];

        foreach ($this->queryParams as $key => $value) {

            if (str_starts_with($key, '_')) {
                continue;
            }

            if ($key === 'per_page') {
                continue;
            }
            if ($value === null) {
                continue;
            }
            if ($value === '') {
                continue;
            }

            if (is_scalar($value)) {
                $query[$key] = $value;
            }
        }

        return $query;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function extractSingleItemFromResponse(mixed $json, string|int $key, ?string $wrapperKey = null): ?array
    {
        $wrapperKey ??= $this->dataWrapper
            ?? $this->parentResource?->getServiceConfig()->responseMapping->dataKey;

        $item = data_get($json, $wrapperKey, $json);

        if (is_array($item) && $this->isAssocArray($item)) {
            /** @var array<string, mixed> $itemAssoc */
            $itemAssoc = $item;

            return $itemAssoc;
        }

        if (is_array($item)) {
            foreach ($item as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $rowId = data_get($row, 'id');
                if (is_scalar($rowId) && (string) $rowId === (string) $key) {
                    /** @var array<string, mixed> $rowAssoc */
                    $rowAssoc = $row;

                    return $rowAssoc;
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadSingleItemFromRelatedResource(string|int $key): ?array
    {
        $related = $this->getRelatedResource();
        if (! $related instanceof AbstractRestResource) {
            return null;
        }

        try {
            $response = $related->httpClient()->get($related->resolveItemEndpoint($key));

            if ($response->notFound()) {
                return null;
            }

            $response->throw();

            return $this->extractSingleItemFromResponse(
                $response->json(),
                $key,
                $related->getServiceConfig()->responseMapping->dataKey,
            );
        } catch (Throwable $e) {
            Log::debug('RestHasMany::loadSingleItemFromRelatedResource — failed', [
                'field' => $this->getColumn(),
                'key' => $key,
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
    private function updateItemUsingRelatedResource(array $data, string|int $key): ?array
    {
        $related = $this->getRelatedResource();
        if (! $related instanceof AbstractRestResource) {
            return null;
        }

        try {
            $response = $related->httpClient()->put($related->resolveItemEndpoint($key), $data);

            if ($response->notFound()) {
                return null;
            }

            $response->throw();

            $payload = $response->json();
            $item = $this->extractSingleItemFromResponse($payload, $key);

            if ($item !== null) {
                return $item;
            }

            if (! is_array($payload)) {
                return null;
            }

            /** @var array<string, mixed> $payload */
            return $payload;
        } catch (Throwable $e) {
            Log::debug('RestHasMany::updateItemUsingRelatedResource — failed', [
                'field' => $this->getColumn(),
                'key' => $key,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function deleteItemUsingRelatedResource(string|int $key): bool
    {
        $related = $this->getRelatedResource();
        if (! $related instanceof AbstractRestResource) {
            return false;
        }

        try {
            $response = $related->httpClient()->delete($related->resolveItemEndpoint($key));

            if ($response->notFound()) {
                return false;
            }

            return $response->successful();
        } catch (Throwable $e) {
            Log::debug('RestHasMany::deleteItemUsingRelatedResource — failed', [
                'field' => $this->getColumn(),
                'key' => $key,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
