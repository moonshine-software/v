<?php

declare(strict_types=1);

namespace App\ApiAdmin\Console;

use App\ApiAdmin\MCP\McpAuthorizationGate;
use App\Models\ApiAdminMcpToken;
use App\Models\ApiAdminTenant;
use Illuminate\Console\Command;
use Override;

/**
 * Issues, lists and revokes tenant-bound hosted MCP bearer tokens.
 * Only the SHA-256 hash is stored; the plain token is printed once.
 */
final class McpTokenCommand extends Command
{
    public const string TOKEN_PREFIX = 'msv_';

    #[Override]
    protected $signature = 'api-admin:mcp-token
        {tenant : Tenant slug}
        {--scope=* : Scope to grant; repeat for several (tools:read, tools:generate, tools:data:read, tools:data:write, tools:destructive, tool:<tool_name> or *)}
        {--name= : Label that identifies the token in listings}
        {--list : List the tenant tokens without revealing them}
        {--revoke= : ID of the token to revoke}';

    #[Override]
    protected $description = 'Issue, list or revoke tenant-bound hosted MCP tokens';

    public function handle(McpAuthorizationGate $gate): int
    {
        $tenant = ApiAdminTenant::query()->where('slug', $this->argument('tenant'))->first();
        if (! $tenant instanceof ApiAdminTenant) {
            $this->error('Tenant does not exist. Create it first with: php artisan api-admin:tenant <slug>');

            return self::FAILURE;
        }

        if ($this->option('list') === true) {
            return $this->listTokens($tenant);
        }

        $revoke = $this->option('revoke');
        if ($revoke !== null) {
            return $this->revokeToken($tenant, $revoke);
        }

        return $this->issueToken($tenant, $gate);
    }

    private function issueToken(ApiAdminTenant $tenant, McpAuthorizationGate $gate): int
    {
        $scopes = [];
        foreach ($this->option('scope') as $scope) {
            if (is_string($scope) && $scope !== '' && ! in_array($scope, $scopes, true)) {
                $scopes[] = $scope;
            }
        }
        if ($scopes === []) {
            $this->error('Grant at least one --scope, for example --scope=tools:read.');

            return self::FAILURE;
        }

        $invalid = array_values(array_filter($scopes, static fn (string $scope): bool => ! $gate->isKnownScope($scope)));
        if ($invalid !== []) {
            $this->error('Unknown scope: '.implode(', ', $invalid).'. Allowed: '.implode(', ', $gate->knownScopes()).', tool:<tool_name>.');

            return self::FAILURE;
        }

        $name = $this->option('name');
        $name = is_string($name) && trim($name) !== '' ? mb_substr(trim($name), 0, 255) : 'MCP token';
        $plainTextToken = self::TOKEN_PREFIX.bin2hex(random_bytes(32));

        $token = ApiAdminMcpToken::query()->create([
            'api_admin_tenant_id' => $tenant->id,
            'name' => $name,
            'token_hash' => ApiAdminMcpToken::hashToken($plainTextToken),
            'scopes' => $scopes,
            'metadata' => ['issued_by' => 'artisan'],
        ]);

        $this->info("Token {$token->id} issued for tenant {$tenant->slug} with scopes: ".implode(', ', $scopes).'.');
        $this->warn('Copy it now. Only its hash is stored, so it cannot be shown again:');
        $this->line($plainTextToken);
        $this->line('MCP endpoint: '.TenantCommand::tenantUrl($tenant->slug, $this->stringConfig('api-admin.hosted.mcp.route', 'api-admin/mcp')));

        if (! $tenant->active) {
            $this->warn('The tenant is inactive; the token works after: php artisan api-admin:tenant '.$tenant->slug.' --activate');
        }
        if ($this->stringConfig('api-admin.hosted.mode', 'local') !== 'hosted' || ! filter_var(config('api-admin.hosted.mcp.enabled', false), FILTER_VALIDATE_BOOL)) {
            $this->warn('Hosted MCP answers only when API_ADMIN_HOSTED_MODE=hosted and API_ADMIN_HOSTED_MCP_ENABLED=true.');
        }

        return self::SUCCESS;
    }

    private function revokeToken(ApiAdminTenant $tenant, mixed $id): int
    {
        $token = is_string($id) && ctype_digit($id)
            ? ApiAdminMcpToken::query()->whereKey((int) $id)->where('api_admin_tenant_id', $tenant->id)->first()
            : null;
        if (! $token instanceof ApiAdminMcpToken) {
            $this->error("Token does not exist for tenant {$tenant->slug}.");

            return self::FAILURE;
        }

        if ($token->revoked_at !== null) {
            $this->info("Token {$token->id} was already revoked.");

            return self::SUCCESS;
        }

        $token->forceFill(['revoked_at' => now()])->save();
        $this->info("Token {$token->id} revoked.");

        return self::SUCCESS;
    }

    private function listTokens(ApiAdminTenant $tenant): int
    {
        $rows = ApiAdminMcpToken::query()->where('api_admin_tenant_id', $tenant->id)->orderBy('id')->get()
            ->map(static fn (ApiAdminMcpToken $token): array => [
                $token->id,
                $token->name,
                implode(', ', $token->scopes),
                $token->last_used_at?->toDateTimeString() ?? 'never',
                $token->revoked_at?->toDateTimeString() ?? 'active',
            ])
            ->all();

        if ($rows === []) {
            $this->info("Tenant {$tenant->slug} has no MCP tokens.");

            return self::SUCCESS;
        }

        $this->table(['ID', 'Name', 'Scopes', 'Last used', 'Revoked'], $rows);

        return self::SUCCESS;
    }

    private function stringConfig(string $key, string $default): string
    {
        $value = config($key, $default);

        return is_string($value) ? $value : $default;
    }
}
