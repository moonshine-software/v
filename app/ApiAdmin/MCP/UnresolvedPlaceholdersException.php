<?php

declare(strict_types=1);

namespace App\ApiAdmin\MCP;

use RuntimeException;

/**
 * Thrown by EndpointBinder when a relation-resource endpoint carries
 * `{placeholders}` that the caller did not supply in `parent_context`.
 *
 * Carries the unresolved names so the tool's error response can list them
 * for the agent without re-parsing the endpoint string.
 */
final class UnresolvedPlaceholdersException extends RuntimeException
{
    /**
     * @param list<string> $placeholders names still unresolved after binding
     */
    public function __construct(
        string $message,
        public readonly array $placeholders = [],
    ) {
        parent::__construct($message);
    }
}
