<?php

declare(strict_types=1);

namespace App\ApiAdmin\MCP;

use App\ApiAdmin\Resources\AbstractRestResource;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Resolves a (service, resource) pair to the generated AbstractRestResource
 * class on disk and hands back either its FQN or an instantiated object.
 *
 * Central source of truth for the "where do generated resources live"
 * convention — app/MoonShine/ApiAdmin/Resources/{StudlyService}/{StudlyResource}/{StudlyResource}Resource.
 * MCP tools that need to touch a resource's data (fetch_resource_items,
 * fetch_resource_item) go through here instead of repeating the class-name
 * derivation on each call site.
 *
 * Instantiation goes through Laravel's container (`app()->make`) so the
 * resource's `CoreContract` ctor dependency resolves — `new` would break.
 */
final readonly class ResourceLocator
{
    public function __construct(
        private ServiceRegistry $registry = new ServiceRegistry,
    ) {}

    /**
     * Derive the fully-qualified resource class name from a service+resource pair.
     *
     * Throws ResourceNotFoundException if the resource directory / file is
     * absent on disk (so we don't surface a class_exists() race and instead
     * provide the list of siblings the caller can choose from).
     */
    public function resolveClass(string $service, string $resource): string
    {
        $studlyService = Str::studly($service);
        $studlyResource = $this->registry->resourceBaseName($service, $resource);
        $namespace = config('api-admin.resources_namespace', 'App\\MoonShine\\ApiAdmin\\Resources');
        $namespace = is_string($namespace) ? $namespace : 'App\\MoonShine\\ApiAdmin\\Resources';
        $fqn = "{$namespace}\\{$studlyService}\\{$studlyResource}\\{$studlyResource}Resource";

        $resourceFile = $this->registry->resourcesPath()
            ."/{$studlyService}/{$studlyResource}/{$studlyResource}Resource.php";

        if (! is_file($resourceFile)) {
            Log::error('ResourceLocator::resolveClass — resource file missing', [
                'service' => $service,
                'resource' => $resource,
                'expected_file' => $resourceFile,
            ]);

            throw new ResourceNotFoundException(
                "Resource '{$resource}' not found in service '{$service}' (expected {$resourceFile}).",
                array_values($this->registry->listResources($service)),
            );
        }

        if (! class_exists($fqn)) {
            Log::error('ResourceLocator::resolveClass — class missing after file exists', [
                'service' => $service,
                'resource' => $resource,
                'fqn' => $fqn,
                'file' => $resourceFile,
            ]);

            throw new ResourceNotFoundException(
                "Resource class '{$fqn}' could not be autoloaded (file {$resourceFile} exists but class does not). Run composer dump-autoload.",
                array_values($this->registry->listResources($service)),
            );
        }

        Log::debug('ResourceLocator::resolveClass', [
            'service' => $service,
            'resource' => $resource,
            'fqn' => $fqn,
        ]);

        return $fqn;
    }

    /**
     * Instantiate a concrete resource via the Laravel container.
     *
     * Container injects CoreContract automatically — `new $fqn()` would skip
     * that and leave the resource without a configured Core.
     */
    public function instantiate(string $service, string $resource): AbstractRestResource
    {
        $fqn = $this->resolveClass($service, $resource);
        $instance = app()->make($fqn);

        if (! $instance instanceof AbstractRestResource) {
            throw new ResourceNotFoundException(
                "Resolved class '{$fqn}' does not extend AbstractRestResource.",
                array_values($this->registry->listResources($service)),
            );
        }

        return $instance;
    }

    /**
     * Unified listing used by ListResourcesTool and the error-hint output
     * of the data tools. Delegates filesystem scan to ServiceRegistry.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listResources(?string $service = null): array
    {
        return $this->registry->listResources($service);
    }
}
