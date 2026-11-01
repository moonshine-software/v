<?php

declare(strict_types=1);

namespace App\ApiAdmin\MCP\Tools;

use App\ApiAdmin\MCP\ServiceRegistry;
use Illuminate\Support\Facades\Log;
use stdClass;

/**
 * MCP Tool: list_services
 *
 * Lists every registered service together with
 * the number of generated resources, base URL, auth type and panel key. The AI
 * agent uses this to see current state before deciding what to create / remove.
 */
final class ListServicesTool implements ToolContract
{
    /**
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return [
            'name' => 'list_services',
            'description' => 'List all registered services with base URL, panel, auth type, and resource counts. Read-only.',
            'inputSchema' => [
                'type' => 'object',
                // stdClass so json_encode emits `{}` — an empty PHP array
                // encodes as `[]` which is invalid JSON Schema (properties
                // must be an object). Claude Code's MCP client rejects the
                // entire tools/list response on schema validation failure.
                'properties' => new stdClass,
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
        Log::info('ListServicesTool: executing');

        $services = (new ServiceRegistry)->listServices();

        return [
            'success' => true,
            'count' => count($services),
            'services' => $services,
        ];
    }
}
