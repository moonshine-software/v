<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Unit\Tenancy;

use App\ApiAdmin\Tenancy\HttpTenantResolver;
use App\ApiAdmin\Tenancy\NullTenantResolver;
use App\Models\ApiAdminTenant;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;

class TenantResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('api_admin_tenants', function (Blueprint $table): void {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->boolean('active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('api_admin_tenants');

        parent::tearDown();
    }

    public function test_null_resolver_returns_local_context(): void
    {
        $context = (new NullTenantResolver)->resolve();

        $this->assertSame('local', $context->id);
        $this->assertSame('local', $context->slug);
        $this->assertSame('local', $context->mode);
        $this->assertTrue($context->isLocal);
    }

    public function test_http_resolver_defaults_to_local_when_hosted_mode_disabled(): void
    {
        config(['api-admin.hosted.mode' => 'local']);

        $context = new HttpTenantResolver(Request::create('/admin'))->resolve();

        $this->assertNotNull($context);
        $this->assertSame('local', $context->id);
        $this->assertTrue($context->isLocal);
    }

    public function test_hosted_mode_requires_tenant_identifier(): void
    {
        config(['api-admin.hosted.mode' => 'hosted']);

        $context = new HttpTenantResolver(Request::create('/admin'))->resolve();

        $this->assertNull($context);
    }

    public function test_hosted_mode_resolves_active_tenant_from_header(): void
    {
        config(['api-admin.hosted.mode' => 'hosted']);
        $tenant = ApiAdminTenant::query()->create([
            'slug' => 'acme',
            'name' => 'Acme',
            'active' => true,
        ]);

        $request = Request::create('/admin', server: ['HTTP_X_APIADMIN_TENANT' => 'acme']);

        $context = new HttpTenantResolver($request)->resolve();

        $this->assertNotNull($context);
        $this->assertSame($tenant->keyAsString(), $context->id);
        $this->assertSame('acme', $context->slug);
        $this->assertSame('Acme', $context->name);
        $this->assertSame('hosted', $context->mode);
        $this->assertFalse($context->isLocal);
    }

    public function test_hosted_mode_rejects_inactive_tenant(): void
    {
        config(['api-admin.hosted.mode' => 'hosted']);
        ApiAdminTenant::query()->create([
            'slug' => 'paused',
            'name' => 'Paused',
            'active' => false,
        ]);

        $request = Request::create('/admin', server: ['HTTP_X_APIADMIN_TENANT' => 'paused']);

        $this->assertNull(new HttpTenantResolver($request)->resolve());
    }
}
