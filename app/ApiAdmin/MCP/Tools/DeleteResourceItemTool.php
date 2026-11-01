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
 * MCP Tool: delete_resource_item
 *
 * Destructive single-item delete. Requires `confirm: true` — same
 * two-phase pattern as `remove_service` / `remove_resource` — so a
 * stray LLM instruction to "clean up old posts" cannot silently drop
 * production rows. Pre-checks existence so upstream 404 is surfaced as
 * `NOT_FOUND` rather than a leaked guzzle RequestException.
 */
final class DeleteResourceItemTool implements ToolContract
{
    /**
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return [
            'name' => 'delete_resource_item',
            'description' => 'Delete a single item by id. Destructive — requires `confirm: true`. Honours the `actions` allowlist and surfaces upstream 404 as NOT_FOUND.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'service' => ['type' => 'string'],
                    'resource' => ['type' => 'string'],
                    'id' => ['type' => ['string', 'integer']],
                    'parent_context' => ['type' => 'object'],
                    'confirm' => [
                        'type' => 'boolean',
                        'description' => 'Must be `true`. Prevents accidental deletes. Without it the tool refuses with CONFIRM_REQUIRED.',
                    ],
                ],
                'required' => ['service', 'resource', 'id', 'confirm'],
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
        $confirm = $params['confirm'] ?? null;

        if ($service === '' || $resourceName === '' || (! is_int($id) && ! is_string($id)) || $id === '') {
            return [
                'success' => false,
                'code' => 'VALIDATION',
                'error' => "Missing required 'service', 'resource', or 'id'",
            ];
        }

        if ($confirm !== true) {
            return [
                'success' => false,
                'code' => 'CONFIRM_REQUIRED',
                'error' => 'Destructive operation requires `confirm: true`. Re-issue the call with {"confirm": true} after verifying the id.',
                'hint' => 'This guard matches remove_service / remove_resource — two-phase by design.',
            ];
        }

        Log::info('DeleteResourceItemTool: executing', [
            'service' => $service,
            'resource' => $resourceName,
            'id' => $id,
        ]);

        if (! in_array('delete', ResourceActionResolver::allowed($service, $resourceName), true)) {
            return [
                'success' => false,
                'code' => 'ACTION_NOT_ALLOWED',
                'error' => "Resource '{$resourceName}' does not allow the 'delete' action.",
                'hint' => 'Check the spec sidecar `actions` allowlist.',
            ];
        }

        try {
            $resource = (new ResourceLocator)->instantiate($service, $resourceName);
        } catch (ResourceNotFoundException $e) {
            return [
                'success' => false,
                'code' => 'RESOURCE_NOT_FOUND',
                'error' => $e->getMessage(),
                'hint' => 'Call list_resources to see what is generated.',
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

        try {
            $resource->deleteItem($id);
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

        $out = [
            'success' => true,
            'service' => $service,
            'resource' => $resourceName,
            'endpoint' => $binding['resolved'],
            'deleted_id' => $id,
        ];

        if ($binding['ignoredParentContext']) {
            $out['ignored_parent_context'] = true;
        }

        return $out;
    }
}
