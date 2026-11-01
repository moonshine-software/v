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
use Illuminate\Support\Str;
use Throwable;

/**
 * MCP Tool: rename_field
 *
 * Rename a single column on an existing resource. Because `column` is
 * the only stable identity in a spec, every place that references the
 * field by name (search, rules, filters, layout-tree leaves, layout
 * groups, mutation routes, sibling depends_on, endpoint_query
 * placeholders) is rewritten in one pass via SpecReferenceWalker. The
 * agent never has to chase down stale references manually.
 *
 * Returns a per-location counts map so the caller can audit what was
 * touched. update_mutations:false opts out of mutation rewrites for
 * the rare case where mutation paths are intentionally indexed by the
 * old column name (e.g. legacy upstream that still expects /by-name).
 */
final class RenameFieldTool implements ToolContract
{
    /**
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return [
            'name' => 'rename_field',
            'description' => 'Generation changes are serialized and rolled back on failure. Rename a column on an existing resource. Cross-references in search, rules, filters, layout-tree, mutations, sibling depends_on, and endpoint_query placeholders are rewritten automatically. Set update_mutations:false to skip the mutation rewrite.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'service' => ['type' => 'string'],
                    'resource' => ['type' => 'string'],
                    'from' => ['type' => 'string', 'description' => 'Current column name.'],
                    'to' => ['type' => 'string', 'description' => 'New column name.'],
                    'update_mutations' => [
                        'type' => 'boolean',
                        'description' => 'When true (default) rewrite body_from/when_present/body keys/path placeholders that mention `from`. Set false to leave mutations untouched.',
                    ],
                ],
                'required' => ['service', 'resource', 'from', 'to'],
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
        $from = is_string($params['from'] ?? null) ? $params['from'] : '';
        $to = is_string($params['to'] ?? null) ? $params['to'] : '';
        $rewriteMutations = ! array_key_exists('update_mutations', $params) || (bool) $params['update_mutations'];

        if ($service === '' || $resource === '' || $from === '' || $to === '') {
            return [
                'success' => false,
                'error' => "Missing required 'service', 'resource', 'from' or 'to'",
                'code' => 'VALIDATION',
            ];
        }

        if ($from === $to) {
            return [
                'success' => false,
                'error' => "'from' and 'to' must differ.",
                'code' => 'VALIDATION',
            ];
        }

        if (config("api-admin.services.{$service}") === null) {
            return [
                'success' => false,
                'error' => "Service '{$service}' is not registered",
                'code' => 'SERVICE_NOT_REGISTERED',
            ];
        }

        $registry = new ServiceRegistry;
        $resources = $registry->listResources($service);
        $match = array_find(
            $resources,
            fn ($r): bool => $r['name'] === Str::snake($resource)
                || $r['base_name'] === Str::studly(Str::singular($resource)),
        );

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

        if (SpecStore::fieldByColumn($spec, $from) === null) {
            return [
                'success' => false,
                'error' => "Field '{$from}' does not exist on '{$service}/{$resource}'",
                'code' => 'FIELD_NOT_FOUND',
            ];
        }

        if (SpecStore::fieldByColumn($spec, $to) !== null) {
            return [
                'success' => false,
                'error' => "Field '{$to}' already exists on '{$service}/{$resource}' — pick a different target name or remove_field first.",
                'code' => 'FIELD_ALREADY_EXISTS',
            ];
        }

        $renamed = SpecReferenceWalker::renameReferences($spec, $from, $to, $rewriteMutations);

        $generatorParams = $renamed['spec'];
        $generatorParams['service'] = $service;
        $generatorParams['name'] = $resource;
        $generatorParams['kind'] ??= $match['kind'] ?? 'main';
        $generatorParams['endpoint'] ??= $match['endpoint'] ?? '/'.$resource;

        Log::info('rename_field: renaming', [
            'service' => $service,
            'resource' => $resource,
            'from' => $from,
            'to' => $to,
            'counts' => $renamed['counts'],
        ]);

        $result = (new ResourceGenerator)->generate($generatorParams);

        try {
            Artisan::call('api-admin:reload');
        } catch (Throwable $e) {
            Log::warning('rename_field: reload failed (non-blocking)', [
                'error' => $e->getMessage(),
            ]);
        }

        $validation = (new ResourceValidator)->validate($result);

        return [
            'success' => $validation['ok'],
            'service' => $service,
            'resource' => $resource,
            'from' => $from,
            'to' => $to,
            'references_updated' => $renamed['counts'],
            'file' => $result['file'],
            'validation' => $validation,
            'message' => $validation['ok']
                ? "Field renamed: '{$from}' -> '{$to}'."
                : 'Field renamed, but validation reported errors — see validation.issues.',
        ];
    }
}
