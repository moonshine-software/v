<?php

declare(strict_types=1);

namespace App\ApiAdmin\MCP\Tools;

use App\ApiAdmin\MCP\ServiceRegistry;
use App\ApiAdmin\Providers\ApiAdminServiceProvider;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * MCP Tool: remove_service
 *
 * Reverses register_service + create_resource for a whole service:
 *   - deletes Resources/{Service}/**
 *   - deletes Configs/{Service}Config.php
 *   - deletes Layouts/{Service}Layout.php
 *   - drops the entry from config/api-admin.php (services)
 *   - drops the panel block from config/moonshine.php
 *   - triggers api-admin:reload
 *
 * Allows the developer's AI agent to tear down a service without touching
 * moonshine-v source. Irreversible — require `confirm: true` to guard against
 * an AI agent deleting in a loop.
 */
final class RemoveServiceTool implements ToolContract
{
    /**
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return [
            'name' => 'remove_service',
            'description' => 'Generation changes are serialized and rolled back on failure. Delete a registered service and all its generated artifacts (config entry, panel, layout, resources). Destructive — pass confirm=true.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'name' => [
                        'type' => 'string',
                        'description' => 'Service name (same as used in register_service)',
                    ],
                    'confirm' => [
                        'type' => 'boolean',
                        'description' => 'Must be true — destructive operation guard',
                    ],
                ],
                'required' => ['name', 'confirm'],
            ],
        ];
    }

    /**
     * @param array{name?: string, confirm?: bool} $params
     *
     * @return array<string, mixed>
     */
    public function execute(array $params): array
    {
        $name = $params['name'] ?? '';
        $confirm = $params['confirm'] ?? false;

        if ($name === '') {
            return [
                'success' => false,
                'error' => "Missing required 'name'",
                'code' => 'VALIDATION',
            ];
        }

        if (! $confirm) {
            return [
                'success' => false,
                'error' => "Refusing to remove '{$name}' without explicit confirm=true",
                'code' => 'CONFIRM_REQUIRED',
                'hint' => 'Re-run with {"name": "'.$name.'", "confirm": true} once you are sure.',
            ];
        }

        Log::info('RemoveServiceTool: executing', ['name' => $name]);

        $removed = (new ServiceRegistry)->removeService($name);

        // Best-effort reload so the admin drops the panel immediately.
        try {
            Artisan::call('api-admin:reload');
        } catch (Throwable $e) {
            Log::warning('RemoveServiceTool: reload failed (non-blocking)', [
                'error' => $e->getMessage(),
            ]);
        }

        // Drop the stale ServiceConfig singleton bound at boot, so any
        // subsequent resolveService() in this worker fails fast instead
        // of returning an object pointing at deleted files.
        ApiAdminServiceProvider::rebindService($name);

        return [
            'success' => true,
            'service' => $name,
            'panel' => $removed['panel'] ?? null,
            'removed_paths' => $removed['paths'] ?? [],
            'message' => "Service '{$name}' removed. Hot-reload was applied by the tool.",
        ];
    }
}
