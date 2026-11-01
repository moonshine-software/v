<?php

declare(strict_types=1);

namespace App\ApiAdmin\MCP\Tools;

use App\ApiAdmin\MCP\EndpointBinder;
use App\ApiAdmin\MCP\ResourceActionResolver;
use App\ApiAdmin\MCP\ResourceLocator;
use App\ApiAdmin\MCP\ResourceNotFoundException;
use App\ApiAdmin\MCP\UnresolvedPlaceholdersException;
use App\ApiAdmin\MCP\UpstreamErrorResponder;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * MCP Tool: update_resource_item
 *
 * Writes a partial or full update to an existing item via the same
 * `performUpdate()` hook the UI form uses — so declarative
 * `mutations.update` routing (multi-endpoint CQRS, `when_present`,
 * `ignore_status`) fires uniformly. A pre-check via
 * `AbstractRestResource::findItem()` surfaces upstream 404 as
 * `NOT_FOUND` before the mutation request hits the wire.
 */
final class UpdateResourceItemTool implements ToolContract
{
    /**
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return [
            'name' => 'update_resource_item',
            'description' => 'Update an existing item by id. Honours mutations.update routing (per-field CQRS splits, when_present, ignore_status) and the `actions` allowlist. Surfaces upstream 404 as NOT_FOUND before writing.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'service' => ['type' => 'string'],
                    'resource' => ['type' => 'string'],
                    'id' => [
                        'type' => ['string', 'integer'],
                        'description' => 'Item id matching the resource\'s primary_key.',
                    ],
                    'payload' => [
                        'type' => 'object',
                        'description' => 'Column/value map. Keys outside the resource schema are passed through — upstream decides.',
                    ],
                    'parent_context' => [
                        'type' => 'object',
                        'description' => 'Required when the resource endpoint has {placeholders}.',
                    ],
                ],
                'required' => ['service', 'resource', 'id', 'payload'],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public function execute(array $params): array
    {
        $service = isset($params['service']) && is_string($params['service']) ? $params['service'] : '';
        $resourceName = isset($params['resource']) && is_string($params['resource']) ? $params['resource'] : '';
        $id = $params['id'] ?? null;
        $payload = $params['payload'] ?? null;

        if ($service === '' || $resourceName === '' || ! is_array($payload) || (! is_int($id) && ! is_string($id)) || $id === '') {
            return [
                'success' => false,
                'code' => 'VALIDATION',
                'error' => "Missing required 'service', 'resource', 'id', or 'payload' (object)",
            ];
        }

        Log::info('UpdateResourceItemTool: executing', [
            'service' => $service,
            'resource' => $resourceName,
            'id' => $id,
            'payload_keys' => array_keys($payload),
        ]);

        if (! in_array('update', ResourceActionResolver::allowed($service, $resourceName), true)) {
            return [
                'success' => false,
                'code' => 'ACTION_NOT_ALLOWED',
                'error' => "Resource '{$resourceName}' does not allow the 'update' action.",
                'hint' => 'Check the spec sidecar `actions` allowlist or the generated activeActions() override.',
            ];
        }

        try {
            $resource = (new ResourceLocator)->instantiate($service, $resourceName);
        } catch (ResourceNotFoundException $e) {
            return [
                'success' => false,
                'code' => 'RESOURCE_NOT_FOUND',
                'error' => $e->getMessage(),
                'hint' => 'Call list_resources to see what is generated, or create_resource first.',
            ];
        }

        $parentContextRaw = $params['parent_context'] ?? [];
        /** @var array<string, scalar|null> $parentContext */
        $parentContext = is_array($parentContextRaw) ? $parentContextRaw : [];

        try {
            $binding = EndpointBinder::bind($resource, $parentContext);
        } catch (UnresolvedPlaceholdersException $e) {
            return [
                'success' => false,
                'code' => 'RELATION_RESOURCE_REQUIRES_PARENT',
                'error' => $e->getMessage(),
                'placeholders' => $e->placeholders,
                'hint' => 'Pass parent_context with keys matching the placeholders.',
            ];
        }

        /** @var array<string, mixed> $payloadMap */
        $payloadMap = $payload;

        try {
            $item = $resource->updateItem($id, $payloadMap);
        } catch (NotFoundHttpException $e) {
            return [
                'success' => false,
                'code' => 'NOT_FOUND',
                'error' => $e->getMessage(),
                'service' => $service,
                'resource' => $resourceName,
                'id' => $id,
            ];
        } catch (Throwable $e) {
            return UpstreamErrorResponder::fromThrowable($e);
        } finally {
            $resource->setEndpointOverride(null);
        }

        $mapping = $resource->getServiceConfig()->responseMapping;
        $primaryKey = $mapping->primaryKey !== '' ? $mapping->primaryKey : 'id';
        $primaryKeyValue = $item[$primaryKey] ?? $id;

        $out = [
            'success' => true,
            'service' => $service,
            'resource' => $resourceName,
            'endpoint' => $binding['resolved'],
            'item' => $item,
            'primary_key_value' => $primaryKeyValue,
        ];

        if ($binding['ignoredParentContext']) {
            $out['ignored_parent_context'] = true;
        }

        return $out;
    }
}
