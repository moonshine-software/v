<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\ApiAdmin\Instructor\InstructorSession;
use App\ApiAdmin\Instructor\InstructorSessionRepository;
use App\ApiAdmin\Tenancy\TenantContext;
use App\ApiAdmin\Tenancy\TenantResolver;
use App\Models\ApiAdminTenant;
use App\MoonShine\ApiAdmin\Pages\InstructorPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use MoonShine\Laravel\Models\MoonshineUser;
use RuntimeException;
use Tests\TestCase;

final class InstructorWizardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $user = new MoonshineUser;
        $user->forceFill([
            'email' => 'admin',
            'password' => bcrypt('12345'),
            'name' => 'admin',
        ])->save();
    }

    public function test_instructor_requires_moonshine_authentication(): void
    {
        $this->get('/api-admin/instructor')->assertRedirect('/admin/login');
    }

    public function test_instructor_screen_is_reachable_when_authenticated(): void
    {
        $this->actingAs($this->user(), 'moonshine')
            ->get('/api-admin/instructor')
            ->assertRedirect($this->instructorPagePath());

        $this->actingAs($this->user(), 'moonshine')
            ->get($this->instructorPagePath())
            ->assertOk()
            ->assertSee('Instructor Agent')
            ->assertSee('API discovery')
            ->assertSee('<option value="bearer"', false)
            ->assertSee('Services');
    }

    public function test_start_probe_builds_reviewable_proposal(): void
    {
        Http::fake([
            'https://api.example.test/users' => Http::response([
                'data' => [
                    ['id' => 1, 'email' => 'a@example.test', 'name' => 'Alice'],
                ],
                'total' => 1,
                'per_page' => 15,
                'current_page' => 1,
                'last_page' => 1,
            ]),
        ]);

        $this->actingAs($this->user(), 'moonshine');

        $this->followingRedirects()->post('/api-admin/instructor/start', [
            'base_url' => 'https://api.example.test',
            'service_name' => 'crm',
            'resource_name' => 'user',
            'endpoint_paths' => '/users',
            'auth_type' => 'none',
            'health_path' => '/health',
        ])
            ->assertOk()
            ->assertSee('Review proposal')
            ->assertSee('crm')
            ->assertSee('/users');
    }

    public function test_confirm_without_active_proposal_is_rejected(): void
    {
        $this->actingAs($this->user(), 'moonshine')
            ->post('/api-admin/instructor/confirm')
            ->assertRedirect('/api-admin/instructor')
            ->assertSessionHas('warning');
    }

    public function test_hosted_instructor_denies_nonmember_before_upstream_probe(): void
    {
        $tenant = ApiAdminTenant::query()->create(['slug' => 'acme', 'name' => 'Acme', 'active' => true]);
        config(['api-admin.hosted.mode' => 'hosted']);
        app()->instance(TenantResolver::class, new readonly class($tenant) implements TenantResolver
        {
            public function __construct(private ApiAdminTenant $tenant) {}

            public function resolve(): TenantContext
            {
                return new TenantContext($this->tenant->keyAsString(), 'acme', 'Acme', 'hosted', false);
            }
        });
        Http::fake();
        $this->actingAs($this->user(), 'moonshine')->get('/api-admin/instructor')->assertForbidden();
        $this->post('/api-admin/instructor/start', ['base_url' => 'https://api.example.test', 'auth_type' => 'none'])->assertForbidden();
        Http::assertNothingSent();
        DB::table('api_admin_tenant_memberships')->insert(['tenant_id' => $tenant->getKey(), 'moonshine_user_id' => $this->user()->getKey()]);
        $this->get('/api-admin/instructor')->assertRedirect();
    }

    public function test_hosted_instructor_requires_tenant_context_instead_of_using_legacy_state(): void
    {
        config(['api-admin.hosted.mode' => 'hosted']);
        app()->instance(TenantResolver::class, new class implements TenantResolver
        {
            public function resolve(): ?TenantContext
            {
                return null;
            }
        });
        $session = new InstructorSession(id: 'moonshine-user-42', proposal: ['service' => ['name' => 'private']]);
        Cache::put('api-admin:instructor:'.$session->id, $session->toArray(), 3600);
        $repository = new InstructorSessionRepository;
        $this->assertNull($repository->load($session->id));
        $this->expectException(RuntimeException::class);
        $repository->save($session);
    }

    private function user(): MoonshineUser
    {
        return MoonshineUser::query()->where('email', 'admin')->firstOrFail();
    }

    private function instructorPagePath(): string
    {
        return '/admin/page/'.InstructorPage::URI;
    }
}
