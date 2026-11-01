<?php

declare(strict_types=1);

namespace App\ApiAdmin\MCP;

use App\ApiAdmin\MCP\Generator\ResourceGenerator;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use MoonShine\Support\Enums\Action;
use MoonShine\Support\ListOf;
use ReflectionClass;
use ReflectionException;
use Throwable;

/**
 * Source of truth for "can the MCP agent do X on this resource?"
 *
 * CUD MCP tools (`create_resource_item`, `update_resource_item`,
 * `delete_resource_item`) guard against resources whose upstream API
 * doesn't support that verb. The generator already reflects this at
 * emission time via `activeActions()`, but the emitted override is
 * code-side only — runtime tools need a separate read path.
 *
 * Resolution order (first match wins):
 *   1. Spec sidecar at `.ai-factory/specs/<service>/<resource>.json`
 *      — primary source, stays in sync with the generator input.
 *   2. Reflection on the generated Resource class's `activeActions()`
 *      method — fallback when the sidecar is missing or the resource
 *      was hand-written.
 *   3. Defaults — `['view','create','update','delete']` (match UI
 *      default for standalone resources; `mass_delete` is excluded
 *      because MCP CUD tools operate on a single item).
 */
final class ResourceActionResolver
{
    private const array DEFAULT_ACTIONS = ['view', 'create', 'update', 'delete'];

    /**
     * @return list<string>
     */
    public static function allowed(string $service, string $resource): array
    {
        $fromSidecar = self::readSidecar($service, $resource);
        if ($fromSidecar !== null) {
            return $fromSidecar;
        }

        $fromReflection = self::readFromReflection($service, $resource);
        if ($fromReflection !== null) {
            return $fromReflection;
        }

        Log::warning('ResourceActionResolver: no sidecar and no class — defaulting to all actions', [
            'service' => $service,
            'resource' => $resource,
        ]);

        return self::DEFAULT_ACTIONS;
    }

    /**
     * @return list<string>|null null if sidecar missing, otherwise normalised list
     *                           (empty `actions: []` means "none allowed" and we
     *                           honour that; absent key means "use default").
     */
    private static function readSidecar(string $service, string $resource): ?array
    {
        $path = ResourceGenerator::specSidecarPath($service, $resource);
        if (! File::exists($path)) {
            return null;
        }

        $raw = File::get($path);
        /** @var mixed $decoded */
        $decoded = json_decode($raw, associative: true);
        if (! is_array($decoded)) {
            Log::warning('ResourceActionResolver: malformed sidecar JSON', [
                'service' => $service,
                'resource' => $resource,
                'path' => $path,
            ]);

            return null;
        }

        if (! array_key_exists('actions', $decoded)) {
            // Key absent → explicit "inherit defaults", not "deny all".
            return self::DEFAULT_ACTIONS;
        }

        $actions = $decoded['actions'];
        if (! is_array($actions)) {
            return self::DEFAULT_ACTIONS;
        }

        return self::normalize($actions);
    }

    /**
     * @return list<string>|null
     */
    private static function readFromReflection(string $service, string $resource): ?array
    {
        try {
            $class = (new ResourceLocator)->resolveClass($service, $resource);
        } catch (Throwable) {
            return null;
        }

        if (! class_exists($class)) {
            return null;
        }

        try {
            $reflection = new ReflectionClass($class);
            $method = $reflection->getMethod('activeActions');
        } catch (ReflectionException) {
            // No activeActions() override on the resource — fall through
            // to defaults. (The parent may still define one, but we only
            // trust overrides that were emitted by the generator.)
            return null;
        }

        if ($method->getDeclaringClass()->getName() !== $class) {
            // Method is inherited (e.g. from a base Resource that just
            // returns all actions). No signal here; defer to defaults.
            return null;
        }

        try {
            $instance = (new ResourceLocator)->instantiate($service, $resource);
            $listOf = $method->invoke($instance);
        } catch (Throwable $e) {
            Log::warning('ResourceActionResolver: reflection invoke failed', [
                'service' => $service,
                'resource' => $resource,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $listOf instanceof ListOf) {
            return null;
        }

        $cases = [];
        foreach ($listOf->toArray() as $item) {
            if ($item instanceof Action) {
                $cases[] = $item->value;
            }
        }

        return self::normalize($cases);
    }

    /**
     * @param array<mixed> $raw
     *
     * @return list<string>
     */
    private static function normalize(array $raw): array
    {
        $canonical = [];
        $seen = [];
        foreach ($raw as $entry) {
            if (! is_string($entry)) {
                continue;
            }
            if ($entry === '') {
                continue;
            }
            $normalized = match (strtolower($entry)) {
                'create' => 'create',
                'view', 'read' => 'view',
                'update', 'edit' => 'update',
                'delete', 'remove' => 'delete',
                'mass_delete', 'massdelete' => 'mass_delete',
                default => null,
            };
            if ($normalized === null) {
                continue;
            }
            if (isset($seen[$normalized])) {
                continue;
            }
            $seen[$normalized] = true;
            $canonical[] = $normalized;
        }

        return $canonical;
    }
}
