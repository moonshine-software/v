<?php

declare(strict_types=1);

namespace App\ApiAdmin\MCP\Tools;

use NoDiscard;

/**
 * Common shape every MCP tool must satisfy so the dispatcher can hand a
 * JSON-RPC payload to it uniformly. The tool class is also expected to
 * expose a static `schema()` method — that half is verified by McpServer
 * directly since static contracts don't fit a PHP interface.
 */
interface ToolContract
{
    /**
     * Execute the tool and return a response payload for JSON-RPC.
     *
     * Marked `#[\NoDiscard]` (PHP 8.5): MCP tool results are always consumed by the
     * JSON-RPC dispatcher — discarding one is a silent bug. Runtime emits a warning
     * if a caller ignores the return array.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    #[NoDiscard]
    public function execute(array $params): array;
}
