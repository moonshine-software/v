<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\ApiAdmin\Console\McpTokenCommand;
use App\Models\ApiAdminMcpToken;
use App\Models\ApiAdminTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class HostedProvisioningCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_command_creates_renames_and_toggles_a_tenant(): void
    {
        $this->assertSame(0, Artisan::call('api-admin:tenant', ['slug' => 'acme-corp']));
        $this->assertStringContainsString('Tenant acme-corp created', Artisan::output());

        $tenant = ApiAdminTenant::query()->where('slug', 'acme-corp')->firstOrFail();
        $this->assertSame('Acme Corp', $tenant->name);
        $this->assertTrue($tenant->active);

        $this->assertSame(0, Artisan::call('api-admin:tenant', ['slug' => 'acme-corp', '--name' => 'Acme Inc.', '--deactivate' => true]));
        $tenant->refresh();
        $this->assertSame('Acme Inc.', $tenant->name);
        $this->assertFalse($tenant->active);

        $this->assertSame(0, Artisan::call('api-admin:tenant', ['slug' => 'acme-corp', '--activate' => true]));
        $this->assertTrue($tenant->refresh()->active);
        $this->assertSame(1, ApiAdminTenant::query()->count());

        $this->assertSame(0, Artisan::call('api-admin:tenant'));
        $this->assertStringContainsString('acme-corp', Artisan::output());
    }

    public function test_tenant_command_rejects_unsafe_slugs_and_conflicting_flags(): void
    {
        foreach (['', '42', 'Acme', '../acme', 'acme_corp', str_repeat('a', 64)] as $slug) {
            $this->assertSame(1, Artisan::call('api-admin:tenant', ['slug' => $slug]), $slug);
        }
        $this->assertSame(1, Artisan::call('api-admin:tenant', ['slug' => 'acme', '--activate' => true, '--deactivate' => true]));
        $this->assertSame(1, Artisan::call('api-admin:tenant', ['slug' => 'acme', '--name' => '   ']));
        $this->assertSame(0, ApiAdminTenant::query()->count());
    }

    public function test_token_is_shown_once_and_only_its_hash_is_stored(): void
    {
        $tenant = ApiAdminTenant::query()->create(['slug' => 'acme', 'name' => 'Acme', 'active' => true]);

        $this->assertSame(0, Artisan::call('api-admin:mcp-token', [
            'tenant' => 'acme',
            '--scope' => ['tools:read', 'tools:data:read', 'tools:read', 'tool:health_check'],
            '--name' => 'CI agent',
        ]));
        $output = Artisan::output();
        $this->assertSame(1, preg_match('/'.McpTokenCommand::TOKEN_PREFIX.'[0-9a-f]{64}/', $output, $match));
        $plainTextToken = $match[0] ?? '';

        $token = ApiAdminMcpToken::findValidToken($plainTextToken);
        $this->assertInstanceOf(ApiAdminMcpToken::class, $token);
        $this->assertSame($tenant->id, $token->api_admin_tenant_id);
        $this->assertSame('CI agent', $token->name);
        $this->assertSame(['tools:read', 'tools:data:read', 'tool:health_check'], $token->scopes);
        $this->assertNotSame($plainTextToken, $token->token_hash);
        $this->assertStringNotContainsString($plainTextToken, (string) json_encode($token->getAttributes()));
        $this->assertStringContainsString('/t/acme/api-admin/mcp', $output);

        $this->assertSame(0, Artisan::call('api-admin:mcp-token', ['tenant' => 'acme', '--list' => true]));
        $listing = Artisan::output();
        $this->assertStringContainsString('CI agent', $listing);
        $this->assertStringNotContainsString($plainTextToken, $listing);
        $this->assertStringNotContainsString($token->token_hash, $listing);

        $this->assertSame(0, Artisan::call('api-admin:mcp-token', ['tenant' => 'acme', '--revoke' => (string) $token->id]));
        $this->assertNull(ApiAdminMcpToken::findValidToken($plainTextToken));
    }

    public function test_token_requires_known_scopes_an_existing_tenant_and_its_own_tokens(): void
    {
        ApiAdminTenant::query()->create(['slug' => 'acme', 'name' => 'Acme', 'active' => true]);
        $other = ApiAdminTenant::query()->create(['slug' => 'beta', 'name' => 'Beta', 'active' => true]);
        $foreign = ApiAdminMcpToken::query()->create([
            'api_admin_tenant_id' => $other->id,
            'name' => 'Beta token',
            'token_hash' => ApiAdminMcpToken::hashToken('beta-token'),
            'scopes' => ['*'],
        ]);

        $this->assertSame(1, Artisan::call('api-admin:mcp-token', ['tenant' => 'acme']));
        $this->assertSame(1, Artisan::call('api-admin:mcp-token', ['tenant' => 'acme', '--scope' => ['tools:everything']]));
        $this->assertSame(1, Artisan::call('api-admin:mcp-token', ['tenant' => 'acme', '--scope' => ['tool:drop_database']]));
        $this->assertSame(1, Artisan::call('api-admin:mcp-token', ['tenant' => 'missing', '--scope' => ['tools:read']]));
        $this->assertSame(1, ApiAdminMcpToken::query()->count());

        $this->assertSame(1, Artisan::call('api-admin:mcp-token', ['tenant' => 'acme', '--revoke' => (string) $foreign->id]));
        $this->assertSame(1, Artisan::call('api-admin:mcp-token', ['tenant' => 'acme', '--revoke' => 'abc']));
        $this->assertNull($foreign->refresh()->revoked_at);
    }
}
