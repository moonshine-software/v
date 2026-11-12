<?php

declare(strict_types=1);

namespace App\ApiAdmin\MCP\Tools;

use App\ApiAdmin\MCP\Generator\ResourceGenerator;
use App\ApiAdmin\MCP\Generator\ResourceValidator;
use App\ApiAdmin\MCP\ServiceRegistry;
use App\ApiAdmin\MCP\SpecStore\SpecStore;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * MCP Tool: update_resource
 *
 * Regenerates an existing resource with a new field spec. Same input shape as
 * `create_resource` but refuses to run if the resource doesn't already exist —
 * this guardrail lets the AI agent distinguish "edit" vs "create" intent and
 * catches typos in `name`/`service` before they silently generate a second
 * directory.
 *
 * The generator is idempotent (stubs get overwritten cleanly), so this tool
 * replaces fields and other submitted generation settings. The saved
 * response_mapping is retained when omitted; an explicit mapping replaces it.
 */
final class UpdateResourceTool implements ToolContract
{
    /**
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        $mapping = CreateResourceTool::schema()['inputSchema']['properties']['response_mapping'] ?? [];
        $mapping = is_array($mapping) ? $mapping : [];
        $mapping['description'] = 'Partial override of the service response mapping. Omit to preserve the resource\'s saved overrides. An explicit empty object {} clears overrides and inherits the service mapping. A non-empty object replaces the saved overrides; omitted keys then inherit from the service.';

        return [
            'name' => 'update_resource',
            'description' => 'Generation changes are serialized and rolled back on failure. Regenerate an existing resource with a new fields spec. Same shape as create_resource but requires the resource to already exist. Omitted response_mapping preserves saved resource overrides; explicit {} clears them. Other fields/settings retain full-replace semantics.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'service' => ['type' => 'string', 'description' => 'Registered service name'],
                    'name' => ['type' => 'string', 'description' => 'Existing resource name (e.g. "post")'],
                    'endpoint' => ['type' => 'string', 'description' => 'API endpoint (must match the current one unless renaming)'],
                    'title' => ['type' => 'string'],
                    'panel' => ['type' => 'string'],
                    'group' => CreateResourceTool::schema()['inputSchema']['properties']['group'] ?? null,
                    'icon' => CreateResourceTool::schema()['inputSchema']['properties']['icon'] ?? null,
                    'fields' => [
                        'type' => 'array',
                        'description' => 'Full field spec. Replaces every field on Index/Form/Detail pages.',
                        'items' => self::schemaItems('fields'),
                    ],
                    'index_fields' => CreateResourceTool::schema()['inputSchema']['properties']['index_fields'] ?? null,
                    'form_fields' => CreateResourceTool::schema()['inputSchema']['properties']['form_fields'] ?? null,
                    'detail_fields' => CreateResourceTool::schema()['inputSchema']['properties']['detail_fields'] ?? null,
                    'search' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'actions' => CreateResourceTool::schema()['inputSchema']['properties']['actions'] ?? null,
                    'default_query' => CreateResourceTool::schema()['inputSchema']['properties']['default_query'] ?? null,
                    'list_request' => CreateResourceTool::schema()['inputSchema']['properties']['list_request'] ?? null,
                    'response_mapping' => $mapping,
                    'mutations' => CreateResourceTool::schema()['inputSchema']['properties']['mutations'] ?? null,
                    'layout' => CreateResourceTool::schema()['inputSchema']['properties']['layout'] ?? null,
                    'detail_strategy' => CreateResourceTool::schema()['inputSchema']['properties']['detail_strategy'] ?? null,
                    'detail_list_key_param' => CreateResourceTool::schema()['inputSchema']['properties']['detail_list_key_param'] ?? null,
                    'rules' => CreateResourceTool::schema()['inputSchema']['properties']['rules'] ?? null,
                    'filters' => [
                        'type' => 'array',
                        'items' => self::schemaItems('filters'),
                    ],
                ],
                'required' => ['service', 'name', 'fields'],
            ],
        ];
    }

    /**
     * Extract `items` spec from CreateResourceTool for `fields` / `filters`
     * without tripping offset-on-mixed at level max.
     *
     * @return array<string, mixed>|null
     */
    private static function schemaItems(string $key): ?array
    {
        $prop = CreateResourceTool::schema()['inputSchema']['properties'][$key] ?? null;
        if (! is_array($prop)) {
            return null;
        }

        $items = $prop['items'] ?? null;
        if (! is_array($items)) {
            return null;
        }

        /** @var array<string, mixed> $normalized */
        $normalized = [];
        foreach ($items as $k => $v) {
            $normalized[(string) $k] = $v;
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $params Raw JSON-RPC payload; required keys are service/name/fields.
     *
     * @return array<string, mixed>
     */
    public function execute(array $params): array
    {
        $rawService = $params['service'] ?? '';
        $rawName = $params['name'] ?? '';
        $service = is_string($rawService) ? $rawService : '';
        $name = is_string($rawName) ? $rawName : '';
        $fields = $params['fields'] ?? null;

        if ($service === '' || $name === '' || ! is_array($fields)) {
            return [
                'success' => false,
                'error' => "Missing required 'service', 'name' or 'fields'",
                'code' => 'VALIDATION',
            ];
        }

        if (config("api-admin.services.$service") === null) {
            return [
                'success' => false,
                'error' => "Service '{$service}' is not registered",
                'code' => 'SERVICE_NOT_REGISTERED',
                'hint' => 'Run list_services to see what exists, or register_service first.',
            ];
        }

        $match = (new ServiceRegistry)->findResource($service, $name);

        if ($match === null) {
            return [
                'success' => false,
                'error' => "Resource '{$service}/{$name}' does not exist — nothing to update",
                'code' => 'RESOURCE_NOT_FOUND',
                'hint' => 'Call list_resources({service:"'.$service.'"}) or use create_resource to create it.',
            ];
        }

        // Keep existing endpoint unless caller provides a new one — reuses the
        // value we parsed out of the resource class file.
        $params['endpoint'] ??= $match['endpoint'] ?? '/'.$name;
        $params['service'] = $service;
        $params['name'] = $name;
        // Preserve the resource kind so the generator writes the correct stub.
        // Regenerating a relation-only resource (endpoint has `{parent_id}`) with the
        // main stub would re-introduce the panel+group + standalone-URL-500 bug.
        $params['kind'] ??= $match['kind'] ?? 'main';

        if (! array_key_exists('response_mapping', $params)) {
            $saved = SpecStore::read($service, $name);
            if (is_array($saved['response_mapping'] ?? null)) {
                $params['response_mapping'] = $saved['response_mapping'];
                Log::debug('[FIX] UpdateResourceTool: preserved response mapping', [
                    'service' => $service,
                    'name' => $name,
                    'mapping_keys' => array_keys($params['response_mapping']),
                ]);
            }
        }

        Log::info('UpdateResourceTool: regenerating', [
            'service' => $service,
            'name' => $name,
            'endpoint' => $params['endpoint'],
            'fields_count' => count($fields),
        ]);

        $result = (new ResourceGenerator)->generate($params);

        try {
            Artisan::call('api-admin:reload');
        } catch (Throwable $e) {
            Log::warning('UpdateResourceTool: reload failed (non-blocking)', [
                'error' => $e->getMessage(),
            ]);
        }

        $validation = (new ResourceValidator)->validate($result);

        return [
            'success' => $validation['ok'],
            'service' => $service,
            'resource' => $result['className'],
            'class' => $result['class'],
            'file' => $result['file'],
            'pages' => $result['pages'],
            'message' => $validation['ok']
                ? "Resource '{$service}/{$name}' regenerated — validation clean."
                : "Resource '{$service}/{$name}' regenerated, but validation reported errors — see `validation.issues`.",
            'validation' => $validation,
        ];
    }
}
