<?php

declare(strict_types=1);

namespace App\ApiAdmin\Http\Controllers;

use App\ApiAdmin\Fields\RestHasOne;
use App\ApiAdmin\Http\RestEndpointAuthorization;
use App\ApiAdmin\Resources\AbstractRestResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use MoonShine\Crud\Contracts\HasFormValidationContract;
use MoonShine\Support\Enums\Ability;

/**
 * Handles CRUD for RestHasOne fields.
 *
 * Mirrors RestHasManyController but for one-to-one relations over a parent-scoped endpoint.
 */
final class RestHasOneController extends Controller
{
    /**
     * Create a new related item.
     *
     * POST /rest-has-one/store
     */
    public function store(Request $request): JsonResponse
    {
        $field = $this->resolveField($request, Ability::CREATE);
        $parentId = $this->readParentId($request);

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
     * Update the related item.
     *
     * PUT /rest-has-one/update
     */
    public function update(Request $request): JsonResponse
    {
        $field = $this->resolveField($request, Ability::UPDATE);
        $parentId = $this->readParentId($request);

        /** @var array<string, mixed> $data */
        $data = $request->except(['_resource', '_relation', '_parent_id', '_token', '_method']);
        $data = $this->prepareData($request, $field, $data, true);

        $result = $field->updateItemForParent($data, $parentId);

        if ($result === null) {
            return response()->json(['message' => 'Failed to update item'], 500);
        }

        return response()->json([
            'message' => __('moonshine::ui.saved'),
        ]);
    }

    /**
     * Delete the related item.
     *
     * DELETE /rest-has-one/destroy
     */
    public function destroy(Request $request): JsonResponse
    {
        $field = $this->resolveField($request, Ability::DELETE);
        $parentId = $this->readParentId($request);

        $success = $field->deleteItemForParent($parentId);

        if (! $success) {
            return response()->json(['message' => 'Failed to delete item'], 500);
        }

        return response()->json([
            'message' => __('moonshine::ui.deleted'),
        ]);
    }

    /**
     * Resolve the RestHasOne field from request parameters.
     */
    private function resolveField(Request $request, Ability $ability): RestHasOne
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

        $authorization = app(RestEndpointAuthorization::class);
        $authorization->authenticate($request, $resource->getServiceConfig());

        $fields = $resource->getFormFields(withOutside: true)->onlyFields();

        $field = null;
        foreach ($fields as $f) {
            if ($f instanceof RestHasOne && $f->getColumn() === $relationColumn) {
                $field = $f;
                break;
            }
        }

        if (! $field instanceof RestHasOne) {
            abort(404, "RestHasOne field '{$relationColumn}' not found on resource '{$resourceUri}'");
        }

        $authorization->relation($request, $resource, $field, $ability);
        $field->resource($resource);
        $this->logResolvedField($request, $resource, $field);

        $parentIdRaw = $request->input('_parent_id');
        if (is_string($parentIdRaw) || is_int($parentIdRaw)) {
            $field->parentId($parentIdRaw);
        }

        return $field;
    }

    private function readParentId(Request $request): string|int|null
    {
        $raw = $request->input('_parent_id');

        return is_string($raw) || is_int($raw) ? $raw : null;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function prepareData(Request $request, RestHasOne $field, array $data, bool $isUpdate = false): array
    {
        $resource = $field->getRelatedResource();
        if (! $resource instanceof AbstractRestResource) {
            return $data;
        }

        $item = $isUpdate ? $field->loadItem() : [];
        abort_if($item === null, 404, 'Related item could not be loaded');
        $casted = $resource->getCaster()->cast($item);
        $resource->setItemID($casted->getKey());
        $resource->setItem($isUpdate ? $item : null);

        $page = $resource->getFormPage();
        if ($page instanceof HasFormValidationContract) {
            $page->prepareForValidation();
            $request->validate($page->getRules(), $page->validationMessages());
        }

        $fields = $resource->getFormFields()->onlyFields(withApplyWrappers: true);
        $fields->fill($item, $casted);

        return $resource->applyFields($data, $fields);
    }

    private function logResolvedField(Request $request, AbstractRestResource $resource, RestHasOne $field): void
    {
        $targetService = $field->getTargetService() ?? $resource->getServiceConfig()->name;
        $requestedService = $request->input('service', $request->query('service'));

        if (is_string($requestedService) && $requestedService !== '' && $requestedService !== $targetService) {
            Log::warning('RestHasOneController: request service differs from field target', [
                'source_resource' => $resource->getUriKey(),
                'relation' => $field->getColumn(),
                'requested_service' => $requestedService,
                'resolved_target_service' => $targetService,
            ]);
        }

        Log::debug('RestHasOneController: resolved relation field', [
            'source_resource' => $resource->getUriKey(),
            'relation' => $field->getColumn(),
            'target_service' => $targetService,
        ]);
    }
}
