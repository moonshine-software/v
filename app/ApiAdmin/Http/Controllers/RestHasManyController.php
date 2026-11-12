<?php

declare(strict_types=1);

namespace App\ApiAdmin\Http\Controllers;

use App\ApiAdmin\Fields\RestHasMany;
use App\ApiAdmin\Http\RestEndpointAuthorization;
use App\ApiAdmin\Resources\AbstractRestResource;
use App\ApiAdmin\Tenancy\TenantRoutes;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use MoonShine\Contracts\UI\ComponentContract;
use MoonShine\Contracts\UI\FieldContract;
use MoonShine\Contracts\UI\FormBuilderContract;
use MoonShine\Crud\Contracts\HasFormValidationContract;
use MoonShine\Support\AlpineJs;
use MoonShine\Support\Enums\Ability;
use MoonShine\Support\Enums\JsEvent;
use MoonShine\UI\Components\FormBuilder;
use MoonShine\UI\Fields\Hidden;

/**
 * Handles form rendering and CRUD for RestHasMany fields.
 *
 * Serves the same role as MoonShine's HasManyController but for REST-based resources.
 */
final class RestHasManyController extends Controller
{
    /**
     * Render the create/edit form for a related item.
     *
     * GET /rest-has-many/form?_resource={parentResourceUri}&_relation={column}&_parent_id={id}&_key={itemId}
     */
    public function formComponent(Request $request): string
    {
        $field = $this->resolveField($request, $request->filled('_key') ? Ability::UPDATE : Ability::CREATE);
        $resource = $field->getRelatedResource();
        if (! $resource instanceof AbstractRestResource) {
            abort(404, 'Related resource not resolvable from request');
        }
        $parentIdRaw = $request->input('_parent_id');
        $parentId = is_string($parentIdRaw) || is_int($parentIdRaw) ? (string) $parentIdRaw : null;
        $keyRaw = $request->input('_key');
        $key = is_string($keyRaw) || is_int($keyRaw) ? (string) $keyRaw : null;
        $isUpdate = $key !== null && $key !== '';
        $resourceUriRaw = $request->input('_resource');
        $resourceUri = is_string($resourceUriRaw) ? $resourceUriRaw : '';

        // Load existing item for edit
        $item = [];
        if ($isUpdate) {
            $item = $field->loadSingleItem((string) $key);
            abort_if($item === null, 404, 'Related item could not be loaded');
        }

        $resource->setItemID($isUpdate ? $key : null);
        $resource->setItem($isUpdate ? $item : null);

        // Build form fields from the related resource
        $formFields = $this->buildFormFields($resource, $isUpdate);

        $formName = 'rest-has-many-'.$field->getColumn().'-'.($key ?? 'create');

        $actionUrl = route('moonshine.rest-has-many.store', [
            ...TenantRoutes::routeParams(),
            '_resource' => $resourceUri,
            '_relation' => $field->getColumn(),
            '_parent_id' => $parentId,
        ]);

        if ($isUpdate) {
            $actionUrl = route('moonshine.rest-has-many.update', [
                ...TenantRoutes::routeParams(),
                '_resource' => $resourceUri,
                '_relation' => $field->getColumn(),
                '_parent_id' => $parentId,
                '_key' => $key,
            ]);
        }

        $form = FormBuilder::make($actionUrl)
            ->fields($isUpdate
                ? array_merge($formFields, [Hidden::make('_method')->setValue('PUT')])
                : $formFields
            )
            ->name($formName)
            ->switchFormMode(
                true,
                array_filter([
                    $field->getListEventName(),
                    $isUpdate ? null : AlpineJs::event(JsEvent::FORM_RESET, $formName),
                ]),
            )
            ->when(
                $isUpdate,
                function (FormBuilderContract $form) use ($item, $resource): void {
                    $form->fillCast($item, $resource->getCaster());
                },
                function (FormBuilderContract $form) use ($resource): void {
                    $form->fillCast([], $resource->getCaster());
                },
            )
            ->submit(__('moonshine::ui.save'), ['class' => 'btn-primary'])
            ->withoutRedirect();

        return (string) $form;
    }

    /**
     * Render the table list component (for async refresh).
     *
     * GET /rest-has-many/list?_resource={parentResourceUri}&_relation={column}&_parent_id={id}
     */
    public function listComponent(Request $request): string
    {
        $field = $this->resolveField($request, Ability::VIEW_ANY);

        $rendered = $field->getComponent()->render();
        if ($rendered instanceof Renderable) {
            return (string) $rendered->render();
        }

        return is_string($rendered) ? $rendered : '';
    }

    /**
     * Create a new related item.
     *
     * POST /rest-has-many/store
     */
    public function store(Request $request): JsonResponse
    {
        $field = $this->resolveField($request, Ability::CREATE);
        $parentIdRaw = $request->input('_parent_id');
        $parentId = is_string($parentIdRaw) || is_int($parentIdRaw) ? $parentIdRaw : null;

        /** @var array<string, mixed> $data */
        $data = $request->except(['_resource', '_relation', '_parent_id', '_token', '_method']);
        $data = $this->prepareData($request, $field, $data);

        $result = $field->createItemForParent($data, $parentId);

        if ($result === null) {
            return response()->json(['message' => 'Failed to create item'], 500);
        }

        return response()->json([
            'message' => __('moonshine::ui.saved'),
        ]);
    }

    /**
     * Update an existing related item.
     *
     * PUT /rest-has-many/update
     */
    public function update(Request $request): JsonResponse
    {
        $field = $this->resolveField($request, Ability::UPDATE);
        $parentIdRaw = $request->input('_parent_id');
        $parentId = is_string($parentIdRaw) || is_int($parentIdRaw) ? $parentIdRaw : null;
        $keyRaw = $request->input('_key');
        if (! is_string($keyRaw) && ! is_int($keyRaw)) {
            return response()->json(['message' => 'Missing _key'], 422);
        }
        $key = $keyRaw;

        /** @var array<string, mixed> $data */
        $data = $request->except(['_resource', '_relation', '_parent_id', '_key', '_token', '_method']);
        $data = $this->prepareData($request, $field, $data, $key);

        $result = $field->updateItemForParent($data, $key, $parentId);

        if ($result === null) {
            return response()->json(['message' => 'Failed to update item'], 500);
        }

        return response()->json([
            'message' => __('moonshine::ui.saved'),
        ]);
    }

    /**
     * Delete a related item.
     *
     * DELETE /rest-has-many/delete
     */
    public function destroy(Request $request): JsonResponse
    {
        $field = $this->resolveField($request, Ability::DELETE);
        $parentIdRaw = $request->input('_parent_id');
        $parentId = is_string($parentIdRaw) || is_int($parentIdRaw) ? $parentIdRaw : null;
        $keyRaw = $request->input('_key');
        if (! is_string($keyRaw) && ! is_int($keyRaw)) {
            return response()->json(['message' => 'Missing _key'], 422);
        }
        $key = $keyRaw;

        $success = $field->deleteItemForParent($key, $parentId);

        if (! $success) {
            return response()->json(['message' => 'Failed to delete item'], 500);
        }

        return response()->json([
            'message' => __('moonshine::ui.deleted'),
        ]);
    }

    /**
     * Resolve the RestHasMany field from request parameters.
     */
    private function resolveField(Request $request, Ability $ability): RestHasMany
    {
        $resourceUriRaw = $request->input('_resource');
        $resourceUri = is_string($resourceUriRaw) ? $resourceUriRaw : '';
        $relationColumnRaw = $request->input('_relation');
        $relationColumn = is_string($relationColumnRaw) ? $relationColumnRaw : '';

        $resource = moonshine()->getResources()->first(
            fn ($r): bool => $r->getUriKey() === $resourceUri,
        );

        if (! $resource instanceof AbstractRestResource) {
            abort(404, "Resource '{$resourceUri}' not found");
        }

        // Find the RestHasMany field in the resource's form fields (include outside)
        $authorization = app(RestEndpointAuthorization::class);
        $authorization->authenticate($request, $resource->getServiceConfig());

        $fields = $resource->getFormFields(withOutside: true)->onlyFields();

        $field = null;
        foreach ($fields as $f) {
            if ($f instanceof RestHasMany && $f->getColumn() === $relationColumn) {
                $field = $f;
                break;
            }
        }

        if (! $field instanceof RestHasMany) {
            abort(404, "RestHasMany field '{$relationColumn}' not found on resource '{$resourceUri}'");
        }

        // Ensure parent resource is attached
        $authorization->relation($request, $resource, $field, $ability);
        $field->resource($resource);
        $this->logResolvedField($request, $resource, $field);

        $parentIdRaw = $request->input('_parent_id');
        if (is_string($parentIdRaw) || is_int($parentIdRaw)) {
            $field->parentId($parentIdRaw);
        }

        /** @var array<string, mixed> $queryParams */
        $queryParams = $request->query();
        $field->queryParams($queryParams);

        return $field;
    }

    /**
     * Build form fields array from the related resource.
     *
     * @return list<ComponentContract>
     */
    private function buildFormFields(
        AbstractRestResource $resource,
        bool $isUpdate,
    ): array {
        $fields = iterator_to_array($resource->getFormFields(withOutside: true)->onlyFields());

        // Filter out ID field for create mode
        if (! $isUpdate) {
            $fields = array_filter(
                $fields,
                fn (FieldContract $f): bool => $f->getColumn() !== 'id',
            )
                |> array_values(...);
        }

        return array_values($fields);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function prepareData(Request $request, RestHasMany $field, array $data, string|int|null $key = null): array
    {
        $resource = $field->getRelatedResource();
        if (! $resource instanceof AbstractRestResource) {
            return $data;
        }

        $item = $key === null ? [] : $field->loadSingleItem($key);
        abort_if($item === null, 404, 'Related item could not be loaded');
        $resource->setItemID($key);
        $resource->setItem($key === null ? null : $item);

        $page = $resource->getFormPage();
        if ($page instanceof HasFormValidationContract) {
            $page->prepareForValidation();
            $request->validate($page->getRules(), $page->validationMessages());
        }

        $fields = $resource->getFormFields()->onlyFields(withApplyWrappers: true);
        $fields->fill($item, $resource->getCaster()->cast($item));

        return $resource->applyFields($data, $fields);
    }

    private function logResolvedField(Request $request, AbstractRestResource $resource, RestHasMany $field): void
    {
        $targetService = $field->getTargetService() ?? $resource->getServiceConfig()->name;
        $requestedService = $request->input('service', $request->query('service'));

        if (is_string($requestedService) && $requestedService !== '' && $requestedService !== $targetService) {
            Log::warning('RestHasManyController: request service differs from field target', [
                'source_resource' => $resource->getUriKey(),
                'relation' => $field->getColumn(),
                'requested_service' => $requestedService,
                'resolved_target_service' => $targetService,
            ]);
        }

        Log::debug('RestHasManyController: resolved relation field', [
            'source_resource' => $resource->getUriKey(),
            'relation' => $field->getColumn(),
            'target_service' => $targetService,
        ]);
    }
}
