<?php

declare(strict_types=1);

namespace App\ApiAdmin\Http\Controllers;

use App\ApiAdmin\Fields\RestBelongsTo;
use App\ApiAdmin\Fields\RestRelationTarget;
use App\ApiAdmin\Http\AsyncSearchContext;
use App\ApiAdmin\Http\RestEndpointAuthorization;
use App\ApiAdmin\Resources\AbstractRestResource;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use MoonShine\Crud\Contracts\HasFiltersContract;
use MoonShine\Support\Enums\Ability;
use Throwable;

/**
 * Proxies async search requests to REST API endpoints and filters results server-side.
 *
 * Fetches records from the REST API, filters by search query against the label field,
 * and returns options in MoonShine's TomSelect-compatible format:
 * [{"value": "1", "label": "Option Name"}, ...]
 */
class RestAsyncSearchController
{
    private const int LIMIT = 20;

    public function __invoke(Request $request): JsonResponse
    {
        $field = $this->resolveField($request);
        $resource = $field->getParentResource();
        abort_unless($resource instanceof AbstractRestResource, 404);
        $authorization = app(RestEndpointAuthorization::class);
        $authorization->authenticate($request, $resource->getServiceConfig());
        $parentId = $request->query('_parent_id');
        $parentId = is_string($parentId) ? $parentId : null;
        $authorization->authorize($resource, $parentId === null ? Ability::VIEW_ANY : Ability::VIEW, $parentId);
        foreach (['service', 'endpoint', 'endpoint_query', 'value_key', 'label_key', 'data_wrapper'] as $override) {
            abort_if($request->query->has($override), 422, 'Search configuration is server controlled');
        }
        $context = AsyncSearchContext::decode($field, $request->query('_context'));
        $target = $field->getRelationTarget();
        abort_unless($target instanceof RestRelationTarget, 404, 'Related service is unavailable');
        $serviceConfig = $target->serviceConfig();
        $serviceName = $target->targetService();
        $authorization->authenticate($request, $serviceConfig);
        $endpoint = $field->resolveSearchEndpoint($context);
        abort_unless(is_string($endpoint) && $endpoint !== '', 422, 'Missing search scope');
        $valueKey = $field->getValueKey();
        $labelKey = $field->getLabelKey();
        foreach ($field->getEndpointQuery() as $scope) {
            if (is_string($scope) && preg_match('/^\{([a-zA-Z_]\w*)\}$/', $scope, $match) === 1) {
                abort_unless(isset($context[$match[1]]) && $context[$match[1]] !== '', 422, 'Missing search scope');
            }
        }
        $endpointQuery = $field->resolveEndpointQuery($context);
        $query = $request->query('query', '');
        abort_unless(is_string($query), 422);
        $searchQuery = $query;
        if ($searchQuery !== '') {
            $endpointQuery += [$serviceConfig->responseMapping->searchParamName => $searchQuery];
        }

        Log::debug('RestAsyncSearchController: searching', [
            'service' => $serviceName,
            'endpoint' => $endpoint,
            'query' => $searchQuery,
            'endpoint_query_keys' => array_keys($endpointQuery),
        ]);

        try {
            $response = $target->httpClient()
                ->timeout(15)
                ->get($endpoint, $endpointQuery);

            $response->throw();

            $json = $response->json();

            $wrapperKey = $target->dataWrapper();
            $items = data_get($json, $wrapperKey, $json);

            if (! is_array($items)) {
                return response()->json([]);
            }

            $options = [];

            foreach ($items as $item) {
                $rawLabel = data_get($item, $labelKey);
                $label = is_scalar($rawLabel) ? (string) $rawLabel : '';

                // Server-side filtering by label
                if ($searchQuery !== '' && ! Str::contains($label, $searchQuery, ignoreCase: true)) {
                    continue;
                }

                $rawValue = data_get($item, $valueKey);
                $value = is_scalar($rawValue) ? (string) $rawValue : '';
                if ($value === '') {
                    continue;
                }

                $options[] = [
                    'value' => $value,
                    'label' => $label,
                ];

                if (count($options) >= self::LIMIT) {
                    break;
                }
            }

            Log::debug('RestAsyncSearchController: found options', [
                'service' => $serviceName,
                'count' => count($options),
                'query' => $searchQuery,
            ]);

            return response()->json($options);
        } catch (Throwable $e) {
            Log::error('RestAsyncSearchController: failed', [
                'service' => $serviceName,
                'exception' => $e::class,
                'status' => $e instanceof RequestException ? $e->response->status() : null,
            ]);

            return response()->json([]);
        }
    }

    private function resolveField(Request $request): RestBelongsTo
    {
        $resourceUriRaw = $request->query('_resource');
        $resourceUri = is_string($resourceUriRaw) ? $resourceUriRaw : '';
        $relationRaw = $request->query('_relation');
        $relation = is_string($relationRaw) ? $relationRaw : '';

        if ($resourceUri === '' || $relation === '') {
            abort(404, 'Unknown search field');
        }

        $resource = moonshine()->getResources()->first(
            fn ($r): bool => $r->getUriKey() === $resourceUri,
        );

        if (! $resource instanceof AbstractRestResource) {
            Log::warning('RestAsyncSearchController: resource not found for relation search', [
                'resource' => $resourceUri,
                'relation' => $relation,
            ]);

            abort(404, 'Unknown search field');
        }

        $scope = $request->query('_field_scope', 'form');
        abort_unless(in_array($scope, ['form', 'filter'], true), 422, 'Unknown field scope');
        if ($scope === 'filter') {
            $page = $resource->getIndexPage();
            abort_unless($page instanceof HasFiltersContract, 404, 'Unknown search filter');
            $fields = $page->getFilters()->onlyFields();
        } else {
            $fields = $resource->getFormFields(withOutside: true)->onlyFields();
        }
        foreach ($fields as $field) {
            if ($field instanceof RestBelongsTo && $field->getColumn() === $relation) {
                $field->resource($resource);

                return $field;
            }
        }

        Log::warning('RestAsyncSearchController: relation field not found', [
            'resource' => $resourceUri,
            'relation' => $relation,
        ]);

        abort(404, 'Unknown search field');
    }
}
