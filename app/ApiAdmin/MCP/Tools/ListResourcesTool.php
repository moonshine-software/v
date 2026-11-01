<?php

declare(strict_types=1);

namespace App\ApiAdmin\MCP\Tools;

use App\ApiAdmin\MCP\ResourceLocator;
use Illuminate\Support\Facades\Log;

/**
 * MCP Tool: list_resources
 *
 * Lists generated resources under app/MoonShine/ApiAdmin/Resources/{Service}/{Resource}.
 * Optionally filter by service. Useful for the AI agent to see existing resources
 * before creating new ones or to pick one for update/remove.
 */
final class ListResourcesTool implements ToolContract
{
    /**
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return [
            'name' => 'list_resources',
            'description' => 'List generated REST resources. Optionally filter by service. Read-only.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'service' => [
                        'type' => 'string',
                        'description' => 'Optional — restrict listing to one service (e.g. "blog")',
                    ],
                ],
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
        $service = isset($params['service']) && is_string($params['service']) && $params['service'] !== ''
            ? $params['service']
            : null;

        Log::info('ListResourcesTool: executing', ['service' => $service]);

        $resources = (new ResourceLocator)->listResources($service);

        return [
            'success' => true,
            'service' => $service,
            'count' => count($resources),
            'resources' => $resources,
        ];
    }
}
