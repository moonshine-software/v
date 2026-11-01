<?php

declare(strict_types=1);

namespace App\ApiAdmin\MCP\SpecStore;

use App\ApiAdmin\MCP\AtomicFileWriter;
use App\ApiAdmin\Tenancy\TenantContext;
use App\ApiAdmin\Tenancy\TenantResolver;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Spec sidecar I/O for resource specs.
 *
 * Sidecars at .ai-factory/specs/<service>/<resource>.json are the
 * source of truth round-tripped by every MCP write — create_resource,
 * update_resource, and the partial-delta tools (add_field, remove_field,
 * rename_field, reorder_fields). Keeping read/write/locate logic in one
 * place avoids two divergent JSON shapes between full-overwrite and
 * delta paths.
 */
final class SpecStore
{
    /**
     * Resolve the on-disk path for a resource spec sidecar.
     *
     * Honors `api-admin.specs_path` config override when set — Orchestra
     * Testbench points at the real spec directory rather than the stub
     * project's base_path.
     */
    public static function path(string $service, string $resource): string
    {
        $override = config('api-admin.specs_path');
        $root = is_string($override) && $override !== ''
            ? rtrim($override, '/')
            : base_path('.ai-factory/specs');

        $root = self::tenantScopedRoot($root);

        $safeService = (string) preg_replace('/[^a-zA-Z0-9_-]/', '_', $service);
        $safeResource = (string) preg_replace('/[^a-zA-Z0-9_-]/', '_', $resource);

        return $root.'/'.$safeService.'/'.$safeResource.'.json';
    }

    public static function exists(string $service, string $resource): bool
    {
        return File::exists(self::path($service, $resource));
    }

    /**
     * Decode the spec for a resource. Returns null when the sidecar is
     * missing or contains invalid JSON.
     *
     * @return array<string, mixed>|null
     */
    public static function read(string $service, string $resource): ?array
    {
        $path = self::path($service, $resource);

        if (! File::exists($path)) {
            Log::debug('spec_store: read miss', [
                'tenant_id' => self::logTenantId(),
                'service' => $service,
                'resource' => $resource,
                'path' => $path,
            ]);

            return null;
        }

        $raw = File::get($path);
        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            Log::warning('spec_store: corrupt sidecar', [
                'tenant_id' => self::logTenantId(),
                'service' => $service,
                'resource' => $resource,
                'path' => $path,
            ]);

            return null;
        }

        Log::debug('spec_store: read', [
            'tenant_id' => self::logTenantId(),
            'service' => $service,
            'resource' => $resource,
            'fields' => is_array($decoded['fields'] ?? null) ? count($decoded['fields']) : 0,
        ]);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * Persist the submitted spec alongside the generated code.
     *
     * Strips identity keys (service/name) since they're implied by the
     * sidecar's path on disk; duplicating them would drift if the file
     * were ever moved.
     *
     * @param array<string, mixed> $params
     */
    public static function write(string $service, string $resource, array $params): string
    {
        $path = self::path($service, $resource);
        AtomicFileWriter::ensureDirectoryExists(dirname($path));

        $snapshot = $params;
        unset($snapshot['service'], $snapshot['name']);

        $encoded = json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $payload = $encoded === false ? '{}' : $encoded;

        AtomicFileWriter::put($path, $payload);

        Log::info('spec_store: written', [
            'tenant_id' => self::logTenantId(),
            'service' => $service,
            'resource' => $resource,
            'path' => $path,
            'bytes' => strlen($payload),
        ]);

        return $path;
    }

    private static function tenantScopedRoot(string $root): string
    {
        if (config('api-admin.hosted.mode', 'local') !== 'hosted') {
            return $root;
        }

        $tenant = self::currentTenantContext();
        $segment = config('api-admin.hosted.tenant_path_segment', 'Tenants');
        $pathSegment = is_string($segment) && $segment !== '' ? $segment : 'Tenants';

        $path = $root.'/'.$pathSegment.'/'.Str::studly($tenant->slug);

        Log::debug('spec_store: resolved tenant-scoped root', [
            'tenant_id' => $tenant->id,
            'root' => $root,
            'path' => $path,
        ]);

        return $path;
    }

    private static function currentTenantContext(): TenantContext
    {
        if (! app()->bound(TenantResolver::class)) {
            return TenantContext::local();
        }

        $resolver = app()->get(TenantResolver::class);
        $tenant = $resolver->resolve();

        if (! $tenant instanceof TenantContext) {
            throw new RuntimeException('Missing tenant context in hosted mode.');
        }

        return $tenant;
    }

    private static function logTenantId(): string
    {
        return self::currentTenantContext()->id;
    }

    /**
     * Locate a field entry by its column name.
     *
     * @param array<string, mixed> $spec
     *
     * @return array<string, mixed>|null
     */
    public static function fieldByColumn(array $spec, string $column): ?array
    {
        $fields = $spec['fields'] ?? null;

        if (! is_array($fields)) {
            return null;
        }

        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }
            if (($field['column'] ?? null) === $column) {
                /** @var array<string, mixed> $field */
                return $field;
            }
        }

        return null;
    }

    /**
     * Locate the index of a field entry within `fields[]` by column name.
     *
     * @param array<string, mixed> $spec
     */
    public static function fieldIndexByColumn(array $spec, string $column): ?int
    {
        $fields = $spec['fields'] ?? null;

        if (! is_array($fields)) {
            return null;
        }

        foreach (array_values($fields) as $index => $field) {
            if (is_array($field) && ($field['column'] ?? null) === $column) {
                return $index;
            }
        }

        return null;
    }
}
