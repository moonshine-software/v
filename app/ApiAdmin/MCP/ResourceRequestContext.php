<?php

declare(strict_types=1);

namespace App\ApiAdmin\MCP;

use App\ApiAdmin\Resources\AbstractRestResource;
use Closure;
use Illuminate\Support\Facades\Log;
use ReflectionObject;
use Throwable;

/**
 * Seed a resource instance with the request-shaped state a tool caller
 * supplies (page / per_page / filter / search / sort / item id / cursor),
 * run a closure against it, then restore the prior state — so the long-
 * running MCP worker doesn't leak state between tool calls.
 *
 * We prefer public setters on the ResourceQuery trait (setItemID,
 * setQueryParams, setPaginatorPage) and fall back to reflection on
 * `itemsPerPage` (protected property, no setter shipped by vendor).
 *
 * Cursor-paginated lists read the incoming cursor from the Laravel
 * Request via `$core->getRequest()->get($mapping->cursorQueryParam)` —
 * for that one case we also mutate `request()->query` and restore it.
 */
final class ResourceRequestContext
{
    /**
     * @param array<string, mixed> $params supports keys: page (int), per_page (int),
     *                                     filter (array), search (string), sort (string),
     *                                     cursor (string), id (int|string).
     *
     * @template T
     *
     * @param Closure(AbstractRestResource): T $run
     *
     * @return T
     */
    public static function seed(AbstractRestResource $resource, array $params, Closure $run): mixed
    {
        $refl = new ReflectionObject($resource);

        // Snapshot prior state so we can restore no matter how $run exits.
        $priorQueryParams = $resource->getQueryParams()->all();
        $priorItemId = self::readProtected($refl, $resource, 'itemID');
        $priorPaginatorPage = self::readProtected($refl, $resource, 'paginatorPage');
        $priorItemsPerPage = self::readProtected($refl, $resource, 'itemsPerPage');
        $priorRequestQuery = request()->query->all();

        Log::debug('ResourceRequestContext::seed — entering', [
            'resource' => $resource::class,
            'seed_keys' => array_keys($params),
        ]);

        try {
            $queryParams = self::buildQueryParams($params);
            $resource->setQueryParams($queryParams);

            if (array_key_exists('id', $params) && $params['id'] !== null) {
                $idValue = $params['id'];

                if (is_int($idValue) || is_string($idValue)) {
                    $resource->setItemID($idValue);
                }
            }

            if (array_key_exists('page', $params) && is_int($params['page'])) {
                $resource->setPaginatorPage($params['page']);
            }

            if (array_key_exists('per_page', $params) && is_int($params['per_page'])) {
                self::writeProtected($refl, $resource, 'itemsPerPage', $params['per_page']);
            }

            // Cursor goes through the Laravel Request — AbstractRestResource
            // reads it directly via `$core->getRequest()->get($cursorParam)`.
            $cursor = $params['cursor'] ?? null;
            if (is_string($cursor) && $cursor !== '') {
                $cursorParam = $resource->getServiceConfig()->responseMapping->cursorQueryParam;
                request()->query->add([$cursorParam => $cursor]);
            }

            return $run($resource);
        } finally {
            $resource->setQueryParams($priorQueryParams);
            self::writeProtected($refl, $resource, 'itemID', $priorItemId);
            self::writeProtected($refl, $resource, 'paginatorPage', $priorPaginatorPage);
            self::writeProtected($refl, $resource, 'itemsPerPage', $priorItemsPerPage);

            try {
                request()->query->replace($priorRequestQuery);
            } catch (Throwable $e) {
                Log::warning('ResourceRequestContext::seed — failed to restore request query', [
                    'error' => $e->getMessage(),
                ]);
            }

            Log::debug('ResourceRequestContext::seed — restored prior state', [
                'resource' => $resource::class,
            ]);
        }
    }

    /**
     * Compose the queryParams map MoonShine's ResourceQuery trait reads from:
     *   - filter[key]=v    → getFilterParams()
     *   - search=term      → getQueryParam('search')
     *   - sort=-col        → prepareOrder()
     *   - page=N           → getPaginatorPage() fallback
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private static function buildQueryParams(array $params): array
    {
        $out = [];

        $filter = $params['filter'] ?? null;
        if (is_array($filter)) {
            $out['filter'] = $filter;
        }

        $search = $params['search'] ?? null;
        if (is_string($search) && $search !== '') {
            $out['search'] = $search;
        }

        $sort = $params['sort'] ?? null;
        if (is_string($sort) && $sort !== '') {
            $out['sort'] = $sort;
        }

        if (array_key_exists('page', $params) && is_int($params['page'])) {
            $out['page'] = $params['page'];
        }

        return $out;
    }

    private static function readProtected(ReflectionObject $refl, object $instance, string $property): mixed
    {
        if (! $refl->hasProperty($property)) {
            return null;
        }

        $prop = $refl->getProperty($property);

        return $prop->getValue($instance);
    }

    private static function writeProtected(ReflectionObject $refl, object $instance, string $property, mixed $value): void
    {
        if (! $refl->hasProperty($property)) {
            return;
        }

        $prop = $refl->getProperty($property);
        $prop->setValue($instance, $value);
    }
}
