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
use Throwable;

/**
 * MCP Tool: create_resource_item
 *
 * Writes a new item to an upstream REST resource via the same
 * `performCreate()` hook the UI form pipeline uses — so declarative
 * `mutations.create` routing is honoured for free. Guards against
 * resources whose spec disables the `create` action.
 */
final class CreateResourceItemTool implements ToolContract
{
    /**
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return [
            'name' => 'create_resource_item',
            'description' => 'Create a new item on a REST resource. Honours mutations.create routing and the resource-level `actions` allowlist. Relation resources require `parent_context` with {placeholder} keys.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'service' => ['type' => 'string', 'description' => 'Registered service name.'],
                    'resource' => ['type' => 'string', 'description' => 'Resource name in snake_case.'],
                    'payload' => [
                        'type' => 'object',
                        'description' => 'Item payload — column/value map matching the resource schema. Sent verbatim through performCreate().',
                    ],
                    'parent_context' => [
                        'type' => 'object',
                        'description' => 'Required when the resource endpoint has {placeholders}. Keys match placeholder names (e.g. {"post_id": 7}).',
                    ],
                ],
                'required' => ['service', 'resource', 'payload'],
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
        $payload = $params['payload'] ?? null;

        if ($service === '' || $resourceName === '' || ! is_array($payload)) {
            return [
                'success' => false,
                'code' => 'VALIDATION',
                'error' => "Missing required 'service', 'resource', or 'payload' (object)",
            ];
        }

        Log::info('CreateResourceItemTool: executing', [
            'service' => $service,
            'resource' => $resourceName,
            'payload_keys' => array_keys($payload),
        ]);

        if (! in_array('create', ResourceActionResolver::allowed($service, $resourceName), true)) {
            return [
                'success' => false,
                'code' => 'ACTION_NOT_ALLOWED',
                'error' => "Resource '{$resourceName}' does not allow the 'create' action.",
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
                'hint' => 'Pass parent_context with keys matching the placeholders (e.g. {"post_id": 7}).',
            ];
        }

        /** @var array<string, mixed> $payloadMap */
        $payloadMap = $payload;

        try {
            $item = $resource->createItem($payloadMap);
        } catch (Throwable $e) {
            return UpstreamErrorResponder::fromThrowable($e);
        } finally {
            $resource->setEndpointOverride(null);
        }

        $mapping = $resource->getServiceConfig()->responseMapping;
        $primaryKey = $mapping->primaryKey !== '' ? $mapping->primaryKey : 'id';
        $primaryKeyValue = $item[$primaryKey] ?? null;

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
