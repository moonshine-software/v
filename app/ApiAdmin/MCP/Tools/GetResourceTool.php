<?php

declare(strict_types=1);

namespace App\ApiAdmin\MCP\Tools;

use App\ApiAdmin\MCP\Generator\ResourceGenerator;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * MCP Tool: get_resource
 *
 * Reads back the last submitted spec for a resource — the JSON sidecar
 * that ResourceGenerator writes on every create_resource/update_resource
 * call. Lets agents fetch current state before a partial edit so the
 * (full-replace) update_resource doesn't drop fields the caller didn't
 * intend to touch.
 *
 * Read-only. Returns the exact shape that was last passed to create_/
 * update_resource, minus identity keys (service, name) which are
 * re-supplied from the path on disk.
 */
final class GetResourceTool implements ToolContract
{
    /**
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return [
            'name' => 'get_resource',
            'description' => 'Return the full spec (fields, filters, rules, layout, mutations, default_query, detail_strategy, icon, group, …) that was last used to generate this resource. Use before update_resource when you want to modify ONLY one slice and keep everything else intact — update_resource is a full replace, so without this read agents tend to silently drop unrelated fields. Read-only. Sidecar stored under `.ai-factory/specs/<service>/<resource>.json`; resources created before this tool existed have no sidecar and return SPEC_MISSING with a hint.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'service' => ['type' => 'string', 'description' => 'Service name (as in register_service).'],
                    'name' => ['type' => 'string', 'description' => 'Resource name (as in create_resource, e.g. "post").'],
                ],
                'required' => ['service', 'name'],
            ],
        ];
    }

    /**
     * @param array{service?: string, name?: string} $params
     *
     * @return array<string, mixed>
     */
    public function execute(array $params): array
    {
        $service = $params['service'] ?? '';
        $name = $params['name'] ?? '';

        if ($service === '' || $name === '') {
            return [
                'success' => false,
                'error' => "Missing required 'service' or 'name'",
                'code' => 'VALIDATION',
            ];
        }

        Log::info('GetResourceTool: executing', ['service' => $service, 'name' => $name]);

        $path = ResourceGenerator::specSidecarPath($service, $name);

        if (! File::exists($path)) {
            return [
                'success' => false,
                'error' => "No spec sidecar for '{$service}/{$name}'",
                'code' => 'SPEC_MISSING',
                'hint' => 'Resource was likely created before the sidecar feature shipped. Run update_resource once with the full current spec — the sidecar is then written for subsequent get_resource calls. You can reconstruct the current fields list from app/MoonShine/ApiAdmin/Resources/<Service>/<Name>/Pages/*Page.php.',
            ];
        }

        $raw = File::get($path);
        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            return [
                'success' => false,
                'error' => "Sidecar for '{$service}/{$name}' is corrupt or not JSON",
                'code' => 'SPEC_CORRUPT',
                'hint' => 'Delete the file and run update_resource to regenerate the sidecar.',
            ];
        }

        return [
            'success' => true,
            'service' => $service,
            'name' => $name,
            'spec' => $decoded,
            'sidecar_path' => $path,
        ];
    }
}
