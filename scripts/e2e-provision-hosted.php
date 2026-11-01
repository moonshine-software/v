<?php

declare(strict_types=1);

// Runs only in the isolated E2E copy with its own hosted database and registries.
use App\ApiAdmin\MCP\McpServer;
use App\ApiAdmin\Tenancy\TenantContext;
use App\ApiAdmin\Tenancy\TenantResolver;
use App\Models\ApiAdminTenant;
use App\Models\MoonshineUserUpstreamIdentity;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use MoonShine\Laravel\Models\MoonshineUser;
use MoonShine\Laravel\Models\MoonshineUserRole;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$tenant = ApiAdminTenant::query()->create(['slug' => 'acme', 'name' => 'Acme', 'active' => true]);
$otherTenant = ApiAdminTenant::query()->create(['slug' => 'beta', 'name' => 'Beta', 'active' => true]);
$ownerRole = MoonshineUserRole::query()->create(['id' => 1, 'name' => 'Admin']);
$memberRole = MoonshineUserRole::query()->create(['id' => 2, 'name' => 'Tenant Member']);
MoonshineUser::query()->create([
    'name' => 'Platform Owner', 'email' => 'hosted-owner@example.test',
    'password' => bcrypt('hosted-owner-password'), 'moonshine_user_role_id' => $ownerRole->getKey(),
]);
foreach ([
    ['Tenant Member', 'hosted-member@example.test', 'hosted-member-password'],
    ['OAuth Member', 'hosted-oauth@example.test', bin2hex(random_bytes(24))],
    ['PAT Member', 'hosted-pat@example.test', bin2hex(random_bytes(24))],
] as [$name, $email, $password]) {
    $user = MoonshineUser::query()->create([
        'name' => $name, 'email' => $email, 'password' => bcrypt($password),
        'moonshine_user_role_id' => $memberRole->getKey(),
    ]);
    DB::table('api_admin_tenant_memberships')->insert([
        'tenant_id' => $tenant->getKey(), 'moonshine_user_id' => $user->getKey(),
    ]);
    if ($name === 'Tenant Member') {
        DB::table('api_admin_tenant_memberships')->insert([
            'tenant_id' => $otherTenant->getKey(), 'moonshine_user_id' => $user->getKey(),
        ]);
    } else {
        MoonshineUserUpstreamIdentity::query()->create([
            'tenant_id' => $tenant->keyAsString(),
            'provider_service' => $name === 'PAT Member' ? 'hosted-pat' : 'hosted-oauth',
            'subject_id' => $name === 'PAT Member' ? 'pat-member-1' : 'oauth-member-1',
            'moonshine_user_id' => $user->getKey(),
        ]);
    }
}
$app->instance(TenantResolver::class, new readonly class($tenant) implements TenantResolver
{
    public function __construct(private ApiAdminTenant $tenant) {}

    public function resolve(): TenantContext
    {
        return new TenantContext($this->tenant->keyAsString(), $this->tenant->slug, $this->tenant->name, 'hosted', false);
    }
});
$baseUrl = 'http://127.0.0.1:'.getenv('E2E_OAUTH_PORT');
$server = new McpServer;
foreach ([
    ['register_service', [
        'name' => 'hosted-oauth', 'base_url' => $baseUrl, 'auth_type' => 'none',
        'panel' => 'hosted-oauth-admin', 'title' => 'Initial hosted title', 'health_path' => '/health',
        'oauth_client_id' => 'e2e-code-client', 'oauth_client_secret' => 'e2e-code-secret',
        'admin_auth_mode' => 'oauth2_authorization_code',
        'admin_auth' => [
            'authorize_url' => $baseUrl.'/authorize', 'token_url' => $baseUrl.'/token', 'validate_endpoint' => '/me',
        ],
    ]],
    ['create_resource', [
        'service' => 'hosted-oauth', 'name' => 'task', 'endpoint' => '/tasks', 'title' => 'Tasks',
        'fields' => [['column' => 'id', 'type' => 'id'], ['column' => 'title', 'type' => 'text']],
    ]],
    ['configure_layout', [
        'service' => 'hosted-oauth', 'title' => 'Hosted OAuth',
        'logo' => '/vendor/moonshine/logo-small.svg', 'logo_small' => '/vendor/moonshine/logo.svg',
    ]],
    ['register_service', [
        'name' => 'hosted-pat', 'base_url' => $baseUrl, 'auth_type' => 'none',
        'panel' => 'hosted-pat-admin', 'title' => 'Hosted PAT', 'health_path' => '/health',
        'admin_auth_mode' => 'delegated_token', 'admin_auth' => ['validate_endpoint' => '/me'],
    ]],
    ['create_resource', [
        'service' => 'hosted-pat', 'name' => 'task', 'endpoint' => '/tasks', 'title' => 'Tasks',
        'fields' => [['column' => 'id', 'type' => 'id'], ['column' => 'title', 'type' => 'text']],
    ]],
] as [$tool, $params]) {
    $result = $server->executeTool($tool, $params);
    if (($result['success'] ?? false) !== true) {
        throw new RuntimeException($tool.' failed: '.json_encode($result));
    }
}
echo "hosted provisioned\n";
