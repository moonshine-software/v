<?php

declare(strict_types=1);

return [
    'http' => [
        'allow_private_addresses' => (bool) env('API_ADMIN_ALLOW_PRIVATE_UPSTREAM', false),
    ],
    // Services are generated per installation and stored in generated_services_path.
    'services' => [],
    'hosted' => [
        'mode' => env('API_ADMIN_HOSTED_MODE', 'local'),
        'tenant_header' => env('API_ADMIN_TENANT_HEADER', 'X-ApiAdmin-Tenant'),
        'route_prefix' => env('API_ADMIN_TENANT_ROUTE_PREFIX', 't/{tenant}'),
        'tenant_path_segment' => env('API_ADMIN_TENANT_PATH_SEGMENT', 'Tenants'),
        'operations' => [
            'recommended_cache_store' => env('API_ADMIN_HOSTED_CACHE_STORE', 'redis'),
            'recommended_session_driver' => env('API_ADMIN_HOSTED_SESSION_DRIVER', 'redis'),
            'recommended_queue_connection' => env('API_ADMIN_HOSTED_QUEUE_CONNECTION', 'redis'),
            'recommended_broadcast_connection' => env('API_ADMIN_HOSTED_BROADCAST_CONNECTION', 'reverb'),
            'smoke_timeout_seconds' => env('API_ADMIN_HOSTED_SMOKE_TIMEOUT', 30),
        ],
        'mcp' => [
            'enabled' => env('API_ADMIN_HOSTED_MCP_ENABLED', false),
            'route' => env('API_ADMIN_HOSTED_MCP_ROUTE', 'api-admin/mcp'),
            'token_abilities' => [
                '*' => 'All hosted MCP tools',
                'tools:read' => 'Service, resource, field catalog, health, and probe tools',
                'tools:generate' => 'Service/resource/code-generation tools',
                'tools:data:read' => 'Runtime item fetch and analytics tools',
                'tools:data:write' => 'Runtime item mutation tools',
                'tools:destructive' => 'Service/resource removal tools',
            ],
        ],
    ],
    'resources_path' => env('API_ADMIN_RESOURCES_PATH', app_path('MoonShine/ApiAdmin/Resources')),
    'resources_namespace' => env('API_ADMIN_RESOURCES_NAMESPACE', 'App\\MoonShine\\ApiAdmin\\Resources'),
    'local_resources_path' => env('API_ADMIN_LOCAL_RESOURCES_PATH', app_path('MoonShine/Resources')),
    'local_resources_namespace' => env('API_ADMIN_LOCAL_RESOURCES_NAMESPACE', 'App\\MoonShine\\Resources'),
    'configs_path' => env('API_ADMIN_CONFIGS_PATH', app_path('MoonShine/ApiAdmin/Configs')),
    'layouts_path' => env('API_ADMIN_LAYOUTS_PATH', app_path('MoonShine/ApiAdmin/Layouts')),
    'specs_path' => env('API_ADMIN_SPECS_PATH', base_path('.ai-factory/specs')),
    'generated_services_path' => env('API_ADMIN_GENERATED_SERVICES_PATH', storage_path('api-admin/generated/services.php')),
    'generated_panels_path' => env('API_ADMIN_GENERATED_PANELS_PATH', storage_path('api-admin/generated/panels.php')),
    'log_channel' => env('API_ADMIN_LOG_CHANNEL', 'stack'),
    'log_level' => env('API_ADMIN_LOG_LEVEL', 'warning'),
    'instructor' => [
        'session_ttl' => 3600,
        'fallback_probes' => true,
    ],
    'realtime' => [
        'enabled' => false,
        'webhook_signature_header' => 'X-ApiAdmin-Signature',
        'webhook_signature_algo' => 'sha256',
        'refresh_debounce_ms' => 750,
        'refresh_strategy' => 'auto',
    ],
    'custom_fields' => [],
    'custom_components' => [],
];
