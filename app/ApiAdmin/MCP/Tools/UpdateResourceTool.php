<?php

declare(strict_types=1);

namespace App\ApiAdmin\MCP\Tools;

use App\ApiAdmin\MCP\Generator\ResourceGenerator;
use App\ApiAdmin\MCP\Generator\ResourceValidator;
use App\ApiAdmin\MCP\ServiceRegistry;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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
 * effectively does "replace all fields with the new list". Partial deltas
 * (add_field / remove_field) are a future P2 — they need a PHP parser.
 */
final class UpdateResourceTool implements ToolContract
{
    /**
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return [
            'name' => 'update_resource',
            'description' => 'Generation changes are serialized and rolled back on failure. Regenerate an existing resource with a new fields spec. Same shape as create_resource but requires the resource to already exist. Use this to add/remove/reorder fields without touching source files.',
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

        $registry = new ServiceRegistry;
        $resources = $registry->listResources($service);
        $match = array_find($resources, fn ($r): bool => $r['name'] === Str::snake($name) || $r['base_name'] === Str::studly(Str::singular($name)));

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
