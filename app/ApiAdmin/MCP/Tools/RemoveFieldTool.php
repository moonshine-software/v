<?php

declare(strict_types=1);

namespace App\ApiAdmin\MCP\Tools;

use App\ApiAdmin\MCP\Generator\ResourceGenerator;
use App\ApiAdmin\MCP\Generator\ResourceValidator;
use App\ApiAdmin\MCP\ServiceRegistry;
use App\ApiAdmin\MCP\SpecStore\SpecReferenceWalker;
use App\ApiAdmin\MCP\SpecStore\SpecStore;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * MCP Tool: remove_field
 *
 * Drop a single field from an existing resource. Cross-reference scan
 * via SpecReferenceWalker — if the column appears in search/rules/
 * filters/mutations/layout/depends_on/endpoint_query the tool refuses
 * with FIELD_REFERENCED unless `force: true`. With force, every
 * dangling reference is stripped together with the field so the
 * regenerated PHP doesn't carry a pointer to a non-existent column.
 *
 * Destructive — `confirm: true` required.
 */
final class RemoveFieldTool implements ToolContract
{
    /**
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return [
            'name' => 'remove_field',
            'description' => 'Generation changes are serialized and rolled back on failure. Remove a single field from a resource. Refuses with FIELD_REFERENCED if the column appears in search/rules/filters/layout/mutations/depends_on/endpoint_query unless force:true. force:true also strips those references. Requires confirm:true.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'service' => ['type' => 'string'],
                    'resource' => ['type' => 'string'],
                    'column' => ['type' => 'string'],
                    'confirm' => ['type' => 'boolean', 'description' => 'Must be true — destructive operation.'],
                    'force' => [
                        'type' => 'boolean',
                        'description' => 'When true, strip every reference to the column from search/rules/filters/mutations/layout/depends_on/endpoint_query in addition to removing the field. Default false — refuse with FIELD_REFERENCED if any reference exists.',
                    ],
                ],
                'required' => ['service', 'resource', 'column', 'confirm'],
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
        $service = is_string($params['service'] ?? null) ? $params['service'] : '';
        $resource = is_string($params['resource'] ?? null) ? $params['resource'] : '';
        $column = is_string($params['column'] ?? null) ? $params['column'] : '';
        $confirm = (bool) ($params['confirm'] ?? false);
        $force = (bool) ($params['force'] ?? false);

        if ($service === '' || $resource === '' || $column === '') {
            return [
                'success' => false,
                'error' => "Missing required 'service', 'resource' or 'column'",
                'code' => 'VALIDATION',
            ];
        }

        if (! $confirm) {
            return [
                'success' => false,
                'error' => 'remove_field requires confirm:true — destructive operation.',
                'code' => 'CONFIRM_REQUIRED',
            ];
        }

        if (config("api-admin.services.{$service}") === null) {
            return [
                'success' => false,
                'error' => "Service '{$service}' is not registered",
                'code' => 'SERVICE_NOT_REGISTERED',
            ];
        }

        $match = (new ServiceRegistry)->findResource($service, $resource);

        if ($match === null) {
            return [
                'success' => false,
                'error' => "Resource '{$service}/{$resource}' does not exist",
                'code' => 'RESOURCE_NOT_FOUND',
            ];
        }

        $spec = SpecStore::read($service, $resource);
        if ($spec === null) {
            return [
                'success' => false,
                'error' => "No spec sidecar for '{$service}/{$resource}'",
                'code' => 'SPEC_MISSING',
                'hint' => 'Run update_resource once with the full current spec to seed the sidecar.',
            ];
        }

        if (SpecStore::fieldByColumn($spec, $column) === null) {
            return [
                'success' => false,
                'error' => "Field '{$column}' does not exist on '{$service}/{$resource}'",
                'code' => 'FIELD_NOT_FOUND',
            ];
        }

        $references = SpecReferenceWalker::findReferences($spec, $column);

        if ($references !== [] && ! $force) {
            Log::warning('remove_field: refused (referenced)', [
                'service' => $service,
                'resource' => $resource,
                'column' => $column,
                'references' => $references,
            ]);

            return [
                'success' => false,
                'error' => "Field '{$column}' is referenced elsewhere in the spec — pass force:true to remove and strip dangling references.",
                'code' => 'FIELD_REFERENCED',
                'references' => $references,
            ];
        }

        $updated = SpecReferenceWalker::removeReferences($spec, $column);

        $generatorParams = $updated;
        $generatorParams['service'] = $service;
        $generatorParams['name'] = $resource;
        $generatorParams['kind'] ??= $match['kind'] ?? 'main';
        $generatorParams['endpoint'] ??= $match['endpoint'] ?? '/'.$resource;

        Log::info('remove_field: removing', [
            'service' => $service,
            'resource' => $resource,
            'column' => $column,
            'force' => $force,
            'refs_stripped' => count($references),
        ]);

        $result = (new ResourceGenerator)->generate($generatorParams);

        try {
            Artisan::call('api-admin:reload');
        } catch (Throwable $e) {
            Log::warning('remove_field: reload failed (non-blocking)', [
                'error' => $e->getMessage(),
            ]);
        }

        $validation = (new ResourceValidator)->validate($result);

        return [
            'success' => $validation['ok'],
            'service' => $service,
            'resource' => $resource,
            'column' => $column,
            'references_stripped' => $references,
            'file' => $result['file'],
            'validation' => $validation,
            'message' => $validation['ok']
                ? "Field '{$column}' removed."
                : 'Field removed, but validation reported errors — see validation.issues.',
        ];
    }
}
