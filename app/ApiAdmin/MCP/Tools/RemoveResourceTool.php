<?php

declare(strict_types=1);

namespace App\ApiAdmin\MCP\Tools;

use App\ApiAdmin\MCP\ServiceRegistry;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * MCP Tool: remove_resource
 *
 * Deletes one resource inside a service: directory Resources/{Service}/{Resource}
 * (includes Pages/). Leaves the service itself, its panel and all other resources
 * untouched. Destructive — requires `confirm: true`.
 */
final class RemoveResourceTool implements ToolContract
{
    /**
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return [
            'name' => 'remove_resource',
            'description' => 'Generation changes are serialized and rolled back on failure. Delete a generated resource (directory + pages). Leaves the service, panel, and sibling resources. Destructive — pass confirm=true.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'service' => [
                        'type' => 'string',
                        'description' => 'Service name the resource belongs to',
                    ],
                    'name' => [
                        'type' => 'string',
                        'description' => 'Resource name (same as used in create_resource, e.g. "post")',
                    ],
                    'confirm' => [
                        'type' => 'boolean',
                        'description' => 'Must be true — destructive operation guard',
                    ],
                ],
                'required' => ['service', 'name', 'confirm'],
            ],
        ];
    }

    /**
     * @param array{service?: string, name?: string, confirm?: bool} $params
     *
     * @return array<string, mixed>
     */
    public function execute(array $params): array
    {
        $service = $params['service'] ?? '';
        $name = $params['name'] ?? '';
        $confirm = $params['confirm'] ?? false;

        if ($service === '' || $name === '') {
            return [
                'success' => false,
                'error' => "Missing required 'service' or 'name'",
                'code' => 'VALIDATION',
            ];
        }

        if (! $confirm) {
            return [
                'success' => false,
                'error' => "Refusing to remove '{$service}/{$name}' without explicit confirm=true",
                'code' => 'CONFIRM_REQUIRED',
                'hint' => 'Re-run with "confirm": true once you are sure.',
            ];
        }

        Log::info('RemoveResourceTool: executing', ['service' => $service, 'name' => $name]);

        $removed = (new ServiceRegistry)->removeResource($service, $name);
        $paths = is_array($removed['paths'] ?? null) ? $removed['paths'] : [];

        try {
            Artisan::call('api-admin:reload');
        } catch (Throwable $e) {
            Log::warning('RemoveResourceTool: reload failed (non-blocking)', [
                'error' => $e->getMessage(),
            ]);
        }

        return [
            'success' => true,
            'service' => $service,
            'resource' => $name,
            'removed_paths' => $paths,
            'message' => count($paths) === 0
                ? "No files found for '{$service}/{$name}' — nothing to remove."
                : "Resource '{$service}/{$name}' removed.",
        ];
    }
}
