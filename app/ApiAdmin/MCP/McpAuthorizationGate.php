<?php

declare(strict_types=1);

namespace App\ApiAdmin\MCP;

use App\ApiAdmin\Tenancy\TenantContext;
use App\ApiAdmin\Tenancy\TenantResolver;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class McpAuthorizationGate
{
    /**
     * @var array<string, string>
     */
    private const array TOOL_GROUPS = [
        'list_services' => 'read',
        'list_resources' => 'read',
        'get_resource' => 'read',
        'list_fields' => 'read',
        'describe_field_type' => 'read',
        'list_field_methods' => 'read',
        'list_components' => 'read',
        'probe_endpoint' => 'read',
        'health_check' => 'read',
        'instructor_status' => 'read',

        'register_service' => 'generate',
        'initialize_local_service' => 'generate',
        'create_resource' => 'generate',
        'create_model_resource' => 'generate',
        'configure_layout' => 'generate',
        'update_resource' => 'generate',
        'add_field' => 'generate',
        'remove_field' => 'generate',
        'rename_field' => 'generate',
        'reorder_fields' => 'generate',
        'create_field' => 'generate',
        'update_field' => 'generate',
        'delete_field' => 'generate',
        'create_component' => 'generate',
        'update_component' => 'generate',
        'delete_component' => 'generate',

        'fetch_resource_items' => 'data:read',
        'fetch_resource_item' => 'data:read',
        'count_resource' => 'data:read',
        'aggregate_resource' => 'data:read',

        'create_resource_item' => 'data:write',
        'update_resource_item' => 'data:write',
        'delete_resource_item' => 'data:write',

        'remove_service' => 'destructive',
        'remove_resource' => 'destructive',
    ];

    private const array MUTATING_TOOLS = [
        'register_service' => true,
        'initialize_local_service' => true,
        'create_resource' => true,
        'create_model_resource' => true,
        'configure_layout' => true,
        'update_resource' => true,
        'add_field' => true,
        'remove_field' => true,
        'rename_field' => true,
        'reorder_fields' => true,
        'create_field' => true,
        'update_field' => true,
        'delete_field' => true,
        'create_component' => true,
        'update_component' => true,
        'delete_component' => true,
        'create_resource_item' => true,
        'update_resource_item' => true,
        'delete_resource_item' => true,
        'remove_service' => true,
        'remove_resource' => true,
    ];

    /**
     * @param array<string, mixed> $params
     * @param list<string>|null $scopes Null means local stdio/full internal access.
     */
    public function authorize(string $toolName, array $params, ?array $scopes = null): McpAuthorizationResult
    {
        $tenant = $this->currentTenantContext();
        if (! $tenant instanceof TenantContext) {
            Log::error('McpAuthorizationGate: missing tenant context', [
                'tool' => $toolName,
            ]);

            return McpAuthorizationResult::deny('MCP_TENANT_REQUIRED', 'Missing tenant context.');
        }

        if ($scopes !== null && ! $this->scopeAllows($toolName, $scopes)) {
            Log::warning('McpAuthorizationGate: missing tool scope', [
                'tenant_id' => $tenant->id,
                'tool' => $toolName,
                'group' => $this->toolGroup($toolName),
            ]);

            return McpAuthorizationResult::deny('MCP_SCOPE_DENIED', "Token scope does not allow tool: {$toolName}");
        }

        if (! $tenant->isLocal && in_array($toolName, [
            'create_field', 'update_field', 'delete_field',
            'create_component', 'update_component', 'delete_component',
            'initialize_local_service', 'create_model_resource',
        ], true)) {
            Log::warning('Rejected privileged code generation', ['tool' => $toolName, 'tenant_id' => $tenant->id]);

            return McpAuthorizationResult::deny('MCP_HOSTED_TOOL_DISABLED', 'Custom PHP/Blade and local model generation are available only in trusted local mode.');
        }

        if (in_array($this->toolGroup($toolName), ['generate', 'destructive'], true)) {
            foreach (['name', 'service', 'panel'] as $key) {
                if (isset($params[$key]) && (! is_string($params[$key]) || preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,79}$/D', $params[$key]) !== 1)) {
                    return McpAuthorizationResult::deny('VALIDATION', "Invalid {$key}: use letters, digits, underscores or hyphens, starting with a letter.");
                }
            }
        }

        if ($toolName === 'register_service') {
            $collision = $this->registrationCollision($params);
            if ($collision !== null) {
                return McpAuthorizationResult::deny('MCP_NAME_CONFLICT', $collision);
            }
        }

        $service = $this->serviceNameFor($toolName, $params);
        $resource = $this->resourceNameFor($params);

        if ($tenant->isLocal || $service === null) {
            $this->logDecision($tenant, $toolName, $service, $resource, true);

            return McpAuthorizationResult::allow();
        }

        $serviceTenant = $this->configuredServiceTenant($service);
        if ($serviceTenant !== null && $serviceTenant !== $tenant->id) {
            Log::warning('McpAuthorizationGate: cross-tenant access denied', [
                'tenant_id' => $tenant->id,
                'tool' => $toolName,
                'service' => $service,
                'resource' => $resource,
                'service_tenant_id' => $serviceTenant,
            ]);

            return McpAuthorizationResult::deny(
                'MCP_TENANT_DENIED',
                "Service '{$service}' is not available for tenant '{$tenant->id}'.",
            );
        }

        $this->logDecision($tenant, $toolName, $service, $resource, true);

        if (isset(self::MUTATING_TOOLS[$toolName])) {
            Log::info('McpAuthorizationGate: tenant-scoped mutating tool authorized', [
                'tenant_id' => $tenant->id,
                'tool' => $toolName,
                'service' => $service,
                'resource' => $resource,
            ]);
        }

        return McpAuthorizationResult::allow();
    }

    /**
     * @param list<string> $scopes
     */
    public function scopeAllows(string $toolName, array $scopes): bool
    {
        if (in_array('*', $scopes, true) || in_array('tools:*', $scopes, true)) {
            return true;
        }

        if (in_array("tool:{$toolName}", $scopes, true)) {
            return true;
        }

        $group = $this->toolGroup($toolName);

        return $group !== null && in_array("tools:{$group}", $scopes, true);
    }

    public function toolGroup(string $toolName): ?string
    {
        return self::TOOL_GROUPS[$toolName] ?? null;
    }

    public function mutates(string $toolName): bool
    {
        return isset(self::MUTATING_TOOLS[$toolName]);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function serviceNameFor(string $toolName, array $params): ?string
    {
        $key = match ($toolName) {
            'register_service', 'initialize_local_service', 'remove_service' => 'name',
            default => 'service',
        };

        $value = $params[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function resourceNameFor(array $params): ?string
    {
        foreach (['resource', 'name'] as $key) {
            $value = $params[$key] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /** @return array<array-key, mixed> */
    private function currentServices(): array
    {
        $configured = config('api-admin.services', []);
        $configured = is_array($configured) ? $configured : [];
        $path = config('api-admin.generated_services_path', storage_path('api-admin/generated/services.php'));
        if (! is_string($path) || ! is_file($path)) {
            return $configured;
        }
        // Recheck ownership from persistent state inside the generation lock,
        // including workers whose config/opcache predates another tenant's write.
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($path, true);
        }
        $loaded = include $path;

        return is_array($loaded) && is_array($loaded['services'] ?? null)
            ? array_replace($configured, $loaded['services']) : $configured;
    }

    /** @param array<string, mixed> $params */
    private function registrationCollision(array $params): ?string
    {
        $name = $params['name'] ?? null;
        if (! is_string($name)) {
            return null;
        }
        $panel = $params['panel'] ?? $name.'-admin';
        $services = $this->currentServices();
        foreach ($services as $otherName => $service) {
            if (! is_string($otherName)) {
                continue;
            }
            if (! is_array($service)) {
                continue;
            }
            if ($otherName === $name) {
                continue;
            }
            if (Str::studly($otherName) === Str::studly($name) || ($service['panel'] ?? null) === $panel) {
                return 'Service names and panel identifiers must be unique across the installation.';
            }
        }
        $owned = $services[$name] ?? null;
        if (is_array($owned) && ($owned['panel'] ?? null) === $panel) {
            return null;
        }
        $panels = config('moonshine.panels', []);
        $path = config('api-admin.generated_panels_path', storage_path('api-admin/generated/panels.php'));
        if (is_string($path) && is_file($path)) {
            if (function_exists('opcache_invalidate')) {
                opcache_invalidate($path, true);
            }
            $loaded = include $path;
            if (is_array($loaded)) {
                $panels = array_replace(is_array($panels) ? $panels : [], $loaded);
            }
        }

        return is_string($panel) && is_array($panels) && array_key_exists($panel, $panels)
            ? 'The requested panel identifier is reserved by an existing panel.' : null;
    }

    private function configuredServiceTenant(string $service): ?string
    {
        $config = $this->currentServices()[$service] ?? null;
        if (! is_array($config)) {
            return null;
        }

        $tenantId = $config['tenant_id'] ?? null;

        return is_string($tenantId) && $tenantId !== '' ? $tenantId : TenantContext::LOCAL_ID;
    }

    private function currentTenantContext(): ?TenantContext
    {
        if (! app()->bound(TenantResolver::class)) {
            return TenantContext::local();
        }

        $resolver = app()->get(TenantResolver::class);
        $tenant = $resolver->resolve();

        return $tenant instanceof TenantContext ? $tenant : null;
    }

    private function logDecision(
        TenantContext $tenant,
        string $toolName,
        ?string $service,
        ?string $resource,
        bool $allowed,
    ): void {
        Log::debug('McpAuthorizationGate: authorization decision', [
            'tenant_id' => $tenant->id,
            'tool' => $toolName,
            'service' => $service,
            'resource' => $resource,
            'group' => $this->toolGroup($toolName),
            'allowed' => $allowed,
        ]);
    }
}
