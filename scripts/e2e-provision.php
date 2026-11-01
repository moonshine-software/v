<?php

declare(strict_types=1);

/**
 * Prepares an isolated e2e installation through the same MCP tools agents use.
 * Runs only inside the temporary copy created by tests/e2e/global-setup.mjs.
 */

use App\ApiAdmin\MCP\McpServer;
use App\Models\ApiAdminTenant;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Http;
use MoonShine\Laravel\Models\MoonshineUser;
use MoonShine\Laravel\Models\MoonshineUserRole;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();

$ports = json_decode((string) getenv('E2E_API_PORTS'), true, flags: JSON_THROW_ON_ERROR);

$role = MoonshineUserRole::query()->firstOrCreate(['id' => MoonshineUserRole::DEFAULT_ROLE_ID], ['name' => 'Admin']);
$user = new MoonshineUser;
$user->forceFill([
    'moonshine_user_role_id' => $role->getKey(),
    'email' => (string) getenv('E2E_ADMIN_USER'),
    'name' => 'E2E Admin',
    'password' => bcrypt((string) getenv('E2E_ADMIN_PASSWORD')),
])->save();

$server = new McpServer;
$selectOptions = ['draft' => 'Draft', 'published' => 'Published', 'archived' => 'Archived'];
$rotationToken = Http::asForm()->post("http://127.0.0.1:{$ports['oauth']}/oauth/token", [
    'grant_type' => 'password', 'client_id' => 'demo-client', 'client_secret' => 'demo-secret',
    'username' => 'admin', 'password' => '123456',
])->throw()->json('refresh_token');
if (! is_string($rotationToken) || $rotationToken === '') {
    throw new RuntimeException('The isolated OAuth fixture did not issue a refresh token.');
}

$steps = [
    ['register_service', [
        'name' => 'links', 'panel' => 'links-admin', 'title' => 'Link Pagination',
        'base_url' => "http://127.0.0.1:{$ports['links']}", 'api_key' => 'link-pagination-token',
        'health_path' => '/health', 'response_format' => 'custom',
        'response_mapping' => ['data_key' => 'data', 'pagination_type' => 'link_header', 'total_header' => 'X-Total-Count'],
    ]],
    ['register_service', [
        'name' => 'blog',
        'base_url' => "http://127.0.0.1:{$ports['blog']}/v1",
        'panel' => 'blog-admin',
        'title' => 'Blog Admin',
        'health_path' => '/health',
    ]],
    ['register_service', [
        'name' => 'shop',
        'base_url' => "http://127.0.0.1:{$ports['shop']}/api",
        'panel' => 'shop-admin',
        'title' => 'Shop Admin',
        'health_path' => '/health',
        'response_format' => 'django',
    ]],
    ['register_service', [
        'name' => 'oauth',
        'base_url' => "http://127.0.0.1:{$ports['oauth']}",
        'panel' => 'oauth-admin',
        'title' => 'OAuth Admin',
        'auth_type' => 'oauth2_password',
        'token_url' => "http://127.0.0.1:{$ports['oauth']}/oauth/token",
        'oauth_client_id' => 'demo-client',
        'oauth_client_secret' => 'demo-secret',
        'admin_login' => true,
        'health_path' => '/health',
        'response_format' => 'laravel',
    ]],
    ['register_service', [
        'name' => 'rotation',
        'base_url' => "http://127.0.0.1:{$ports['oauth']}",
        'panel' => 'rotation-admin',
        'title' => 'Rotation Admin',
        'auth_type' => 'oauth2_refresh_token',
        'token_url' => "http://127.0.0.1:{$ports['oauth']}/oauth/token",
        'oauth_client_id' => 'demo-client',
        'oauth_client_secret' => 'demo-secret',
        'oauth_refresh_token' => $rotationToken,
        'health_path' => '/health',
        'response_format' => 'laravel',
    ]],
    ['create_resource', [
        'service' => 'rotation', 'name' => 'task', 'endpoint' => '/tasks', 'title' => 'Rotation Tasks',
        'actions' => ['view'],
        'fields' => [['column' => 'id', 'type' => 'id'], ['column' => 'title', 'type' => 'text']],
    ]],
    ['register_service', [
        'name' => 'spa',
        'base_url' => "http://127.0.0.1:{$ports['session']}",
        'panel' => 'spa-admin',
        'title' => 'Session Admin',
        'auth_type' => 'session',
        'session_login_endpoint' => '/login',
        'admin_login' => true,
        'csrf_bootstrap_path' => '/sanctum/csrf-cookie',
        'csrf_token_source' => 'cookie',
        'csrf_token_key' => 'XSRF-TOKEN',
        'csrf_header_name' => 'X-XSRF-TOKEN',
        'health_path' => '/health',
        'response_format' => 'laravel',
    ]],
    ['create_resource', [
        'service' => 'blog',
        'name' => 'post',
        'endpoint' => '/posts',
        'title' => 'Posts',
        'fields' => [
            ['column' => 'id', 'type' => 'id'],
            ['column' => 'title', 'type' => 'text', 'methods' => ['sortable', 'required']],
            ['column' => 'body', 'type' => 'textarea'],
            ['column' => 'category_id', 'type' => 'belongs_to', 'endpoint' => '/categories'],
            [
                'column' => 'comments',
                'type' => 'has_many',
                'endpoint' => '/posts/{parent_id}/comments',
                'fields' => [
                    ['column' => 'author_name', 'type' => 'text', 'label' => 'Author'],
                    ['column' => 'body', 'type' => 'text', 'label' => 'Body'],
                ],
            ],
            [
                'column' => 'author',
                'type' => 'has_one',
                'endpoint' => '/posts/{parent_id}/author',
                'fields' => [
                    ['column' => 'name', 'type' => 'text', 'label' => 'Name'],
                    ['column' => 'email', 'type' => 'email', 'label' => 'Email'],
                    ['column' => 'bio', 'type' => 'textarea', 'label' => 'Bio'],
                ],
            ],
            ['column' => 'tags', 'type' => 'belongs_to_many', 'endpoint' => '/tags'],
            ['column' => 'status', 'type' => 'select', 'options' => $selectOptions],
        ],
        'filters' => [
            ['column' => 'status', 'type' => 'select', 'options' => $selectOptions],
            ['column' => 'category_id', 'type' => 'belongs_to', 'endpoint' => '/categories'],
        ],
        'search' => ['title', 'body'],
    ]],
    ['create_resource', [
        'service' => 'shop',
        'name' => 'product',
        'endpoint' => '/products',
        'title' => 'Products',
        'fields' => [
            ['column' => 'id', 'type' => 'id'],
            ['column' => 'name', 'type' => 'text', 'methods' => ['sortable', 'required']],
            ['column' => 'sku', 'type' => 'text', 'methods' => ['sortable']],
            ['column' => 'price', 'type' => 'number', 'methods' => ['required']],
            ['column' => 'brand_id', 'type' => 'belongs_to', 'endpoint' => '/brands'],
        ],
        'search' => ['name', 'sku'],
    ]],
    ['create_resource', [
        'service' => 'oauth',
        'name' => 'task',
        'endpoint' => '/tasks',
        'title' => 'OAuth Tasks',
        'fields' => [
            ['column' => 'id', 'type' => 'id'],
            ['column' => 'title', 'type' => 'text', 'methods' => ['sortable', 'required']],
            ['column' => 'created_at', 'type' => 'text'],
        ],
    ]],
    ['create_resource', [
        'service' => 'spa',
        'name' => 'post',
        'endpoint' => '/posts',
        'title' => 'Session Posts',
        'fields' => [
            ['column' => 'id', 'type' => 'id'],
            ['column' => 'title', 'type' => 'text', 'methods' => ['sortable', 'required']],
            ['column' => 'created_at', 'type' => 'text'],
        ],
    ]],
    ['initialize_local_service', ['name' => 'localapp', 'title' => 'Local App']],
    ['create_model_resource', [
        'service' => 'localapp',
        'name' => 'tenant',
        'model' => ApiAdminTenant::class,
        'fields' => [
            ['column' => 'id', 'type' => 'id'],
            ['column' => 'slug', 'type' => 'text'],
            ['column' => 'name', 'type' => 'text'],
            ['column' => 'active', 'type' => 'switcher'],
        ],
        'rules' => ['slug' => 'required|string', 'name' => 'required|string'],
        'search' => ['slug', 'name'],
    ]],
];

foreach (['simple', 'numbered'] as $name) {
    $steps[] = ['create_resource', [
        'service' => 'links', 'name' => $name, 'endpoint' => '/'.$name, 'actions' => ['view'],
        'fields' => [['column' => 'id', 'type' => 'id', 'methods' => ['sortable']], ['column' => 'title', 'type' => 'text']],
        'search' => ['title'],
        'filters' => [['column' => 'status', 'type' => 'select', 'options' => $selectOptions]],
    ]];
}

foreach ($steps as [$tool, $params]) {
    $result = $server->executeTool($tool, $params);
    if (($result['success'] ?? false) !== true) {
        fwrite(STDERR, "{$tool} failed: ".json_encode($result).PHP_EOL);
        exit(1);
    }
}

echo "provisioned\n";
