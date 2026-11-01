<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Unit\Tenancy;

use App\ApiAdmin\Fields\RestBelongsTo;
use App\ApiAdmin\Pages\RestFormPage;
use App\ApiAdmin\Resources\AbstractRestResource;
use App\ApiAdmin\ServiceConfig\ResponseMapping;
use App\ApiAdmin\ServiceConfig\ServiceConfig;
use App\ApiAdmin\Tenancy\TenantBrowserAccess;
use App\ApiAdmin\Tenancy\TenantContext;
use App\ApiAdmin\Tenancy\TenantKeyBuilder;
use App\ApiAdmin\Tenancy\TenantResolver;
use App\ApiAdmin\Tenancy\TenantSecretStore;
use App\Models\ApiAdminTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use MoonShine\Contracts\Core\TypeCasts\DataWrapperContract;
use MoonShine\Laravel\Models\MoonshineUser;
use Override;
use RuntimeException;
use Tests\TestCase;

final class TenantSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_membership_is_explicit_tenant_scoped_and_immediately_revocable(): void
    {
        config(['api-admin.hosted.mode' => 'hosted']);
        $tenant = ApiAdminTenant::query()->create(['slug' => 'acme', 'name' => 'Acme', 'active' => true]);
        $other = ApiAdminTenant::query()->create(['slug' => 'other', 'name' => 'Other', 'active' => true]);
        $this->useTenant($tenant);
        $user = MoonshineUser::query()->create(['email' => 'admin@test.invalid', 'name' => 'Admin', 'password' => 'random-password']);
        $access = new TenantBrowserAccess;
        $this->assertFalse($access->allows(null));
        $this->assertFalse($access->allows($user));
        DB::table('api_admin_tenant_memberships')->insert(['tenant_id' => $other->getKey(), 'moonshine_user_id' => $user->getKey()]);
        $this->assertFalse($access->allows($user));
        DB::table('api_admin_tenant_memberships')->insert(['tenant_id' => $tenant->getKey(), 'moonshine_user_id' => $user->getKey()]);
        $this->assertTrue($access->allows($user));
        $tenant->update(['active' => false]);
        $this->assertFalse($access->allows($user));
        $tenant->update(['active' => true]);
        DB::table('api_admin_tenant_memberships')->where('tenant_id', $tenant->getKey())->delete();
        $this->assertFalse($access->allows($user));
    }

    public function test_secrets_survive_cache_clear_and_remain_encrypted_and_tenant_scoped(): void
    {
        $store = new TenantSecretStore;
        $store->putMany('a', 'service', ['api_key' => 'secret-a']);
        $store->putMany('b', 'service', ['api_key' => 'secret-b']);
        Cache::flush();
        $this->assertSame('secret-a', $store->get('a', 'service', 'api_key'));
        $this->assertSame('secret-b', $store->get('b', 'service', 'api_key'));
        $encrypted = DB::table('api_admin_tenant_secrets')->where('tenant_id', 'a')->value('value');
        $this->assertIsString($encrypted);
        $this->assertStringNotContainsString('secret-a', $encrypted);
        $this->assertSame('secret-a', Crypt::decryptString($encrypted));
        $store->putMany('a', 'service', ['api_key' => '']);
        $this->assertSame('', $store->get('a', 'service', 'api_key', 'fallback'));
    }

    public function test_legacy_cache_secret_migrates_before_eviction_and_db_wins(): void
    {
        $key = TenantKeyBuilder::cacheKey('a', 'secret', 'service', 'api_key');
        Cache::forever($key, Crypt::encryptString('legacy'));
        $store = new TenantSecretStore;
        $this->assertSame('legacy', $store->get('a', 'service', 'api_key'));
        $this->assertFalse(Cache::has($key));
        Cache::flush();
        $this->assertSame('legacy', $store->get('a', 'service', 'api_key'));
        Cache::forever($key, Crypt::encryptString('stale'));
        $this->assertSame('legacy', new TenantSecretStore()->get('a', 'service', 'api_key'));
    }

    public function test_corrupt_secret_fails_closed(): void
    {
        DB::table('api_admin_tenant_secrets')->insert(['tenant_id' => 'a', 'service' => 'service', 'name' => 'api_key', 'value' => 'corrupt']);
        $this->expectException(RuntimeException::class);
        new TenantSecretStore()->get('a', 'service', 'api_key');
    }

    public function test_operator_command_grants_and_revokes_membership(): void
    {
        $tenant = ApiAdminTenant::query()->create(['slug' => 'acme', 'name' => 'Acme', 'active' => true]);
        $user = MoonshineUser::query()->create(['email' => 'member@test.invalid', 'name' => 'Member', 'password' => 'unknown']);
        $userId = $user->getKey();
        $this->assertIsInt($userId);
        $this->assertSame(0, Artisan::call('api-admin:tenant-member', ['tenant' => 'acme', 'user' => (string) $userId]));
        $this->assertDatabaseHas('api_admin_tenant_memberships', ['tenant_id' => $tenant->getKey(), 'moonshine_user_id' => $user->getKey()]);
        $this->assertSame(0, Artisan::call('api-admin:tenant-member', ['tenant' => 'acme', 'user' => (string) $userId, '--revoke' => true]));
        $this->assertDatabaseMissing('api_admin_tenant_memberships', ['tenant_id' => $tenant->getKey(), 'moonshine_user_id' => $user->getKey()]);
        $this->assertSame(1, Artisan::call('api-admin:tenant-member', ['tenant' => 'unknown', 'user' => (string) $userId]));
    }

    public function test_migration_command_preserves_legacy_secrets_before_cache_clear(): void
    {
        config(['api-admin.services' => ['service' => ['tenant_id' => 'a']]]);
        Cache::forever(TenantKeyBuilder::cacheKey('a', 'secret', 'service', 'api_key'), Crypt::encryptString('migrated'));
        $this->assertSame(0, Artisan::call('api-admin:migrate-tenant-secrets'));
        Cache::flush();
        $this->assertSame('migrated', new TenantSecretStore()->get('a', 'service', 'api_key'));
    }

    public function test_hosted_async_search_denies_other_tenant_member_without_contacting_upstream(): void
    {
        $tenant = ApiAdminTenant::query()->create(['slug' => 'acme', 'name' => 'Acme', 'active' => true]);
        $other = ApiAdminTenant::query()->create(['slug' => 'other', 'name' => 'Other', 'active' => true]);
        $user = MoonshineUser::query()->create(['email' => 'search@test.invalid', 'name' => 'Search', 'password' => 'unknown']);
        DB::table('api_admin_tenant_memberships')->insert(['tenant_id' => $other->getKey(), 'moonshine_user_id' => $user->getKey()]);
        config(['api-admin.hosted.mode' => 'hosted']);
        $this->useTenant($tenant);
        TenantSearchResource::$tenantId = $tenant->keyAsString();
        moonshine()->resources([TenantSearchResource::class]);
        Http::fake();
        $this->actingAs($user, 'moonshine')->getJson('/api-admin/async-search?_resource=tenant-search&_relation=status_id')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_legacy_form_rules_cannot_query_platform_database_in_hosted_mode(): void
    {
        config(['api-admin.hosted.mode' => 'hosted']);
        $resource = new TenantSearchResource(moonshine());
        $resource->setItem(['id' => 1]);
        $page = new class(moonshine()) extends RestFormPage
        {
            /** @param DataWrapperContract<mixed> $item
             * @return array<string, string>
             */
            protected function rules(DataWrapperContract $item): array
            {
                return ['email' => 'exists:moonshine_users,email'];
            }
        };
        $page->setResource($resource);
        $this->expectException(InvalidArgumentException::class);
        $page->getRules();
    }

    private function useTenant(ApiAdminTenant $tenant): void
    {
        app()->instance(TenantResolver::class, new readonly class($tenant) implements TenantResolver
        {
            public function __construct(private ApiAdminTenant $tenant) {}

            public function resolve(): TenantContext
            {
                return new TenantContext($this->tenant->keyAsString(), $this->tenant->slug, $this->tenant->name, 'hosted', false);
            }
        });
    }
}

final class TenantSearchResource extends AbstractRestResource
{
    public static string $tenantId = 'local';

    #[Override]
    public function getUriKey(): string
    {
        return 'tenant-search';
    }

    protected function resolveServiceConfig(): ServiceConfig
    {
        return new ServiceConfig(name: 'tenant-search', baseUrl: 'https://search.test', apiKey: 'synthetic', responseMapping: new ResponseMapping, tenantId: self::$tenantId);
    }

    protected function endpoint(): string
    {
        return '/items';
    }

    #[Override]
    protected function formFields(): iterable
    {
        return [RestBelongsTo::make('Status', 'status_id')->endpoint('/statuses')];
    }
}
