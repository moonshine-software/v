<?php

declare(strict_types=1);

namespace App\ApiAdmin\MCP;

use RuntimeException;

/**
 * Thrown by ResourceLocator when a (service, resource) pair does not map to
 * a generated Resource class on disk. Carries the list of sibling resources
 * the service DOES expose so the catch site can build a useful hint without
 * re-scanning the filesystem.
 */
final class ResourceNotFoundException extends RuntimeException
{
    /**
     * @param list<array<string, mixed>> $availableResources output of ServiceRegistry::listResources(service)
     */
    public function __construct(
        string $message,
        public readonly array $availableResources = [],
    ) {
        parent::__construct($message);
    }
}
