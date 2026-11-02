<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\ApiAdmin\Instructor\InstructorSession;
use App\ApiAdmin\Instructor\InstructorSessionRepository;
use App\ApiAdmin\MCP\McpServer;
use App\ApiAdmin\Tenancy\TenantContext;
use App\ApiAdmin\Tenancy\TenantResolver;
use App\Models\ApiAdminTenant;
use App\MoonShine\ApiAdmin\Pages\InstructorPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use MoonShine\Laravel\Models\MoonshineUser;
use RuntimeException;
use Tests\ApiAdmin\Concerns\UsesIsolatedProjectRoot;
use Tests\TestCase;

final class InstructorWizardTest extends TestCase
{
    use RefreshDatabase;
    use UsesIsolatedProjectRoot;

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

    protected function tearDown(): void
    {
        $this->removeIsolatedProjectRoot();

        parent::tearDown();
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
            ->assertSee('Add service')
            ->assertDontSee('Instructor Agent')
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

    public function test_header_auth_survives_review_and_confirmation_without_exposing_credentials(): void
    {
        $this->useIsolatedProjectRoot(app());
        config([
            'api-admin.services' => [],
            'api-admin.configs_path' => base_path('Configs'),
            'api-admin.layouts_path' => base_path('Layouts'),
            'api-admin.resources_path' => base_path('app/MoonShine/ApiAdmin/Resources'),
            'api-admin.specs_path' => base_path('.ai-factory/specs'),
            'api-admin.generated_services_path' => base_path('generated/services.php'),
            'api-admin.generated_panels_path' => base_path('generated/panels.php'),
        ]);
        File::put(base_path('.env'), '');
        File::put(config_path('moonshine.php'), '<?php return [];');
        Http::preventStrayRequests();
        Http::fake([
            'https://api.example.test/posts*' => static fn (Request $request) => Http::response(
                ['data' => [['id' => 1, 'title' => 'Header-authenticated post']]],
                $request->hasHeader('X-API-Key', 'probe-credential')
                    || $request->hasHeader('X-API-Key', 'generation-credential') ? 200 : 401,
            ),
        ]);
        $user = $this->user();
        $this->actingAs($user, 'moonshine');
        $service = 'header_'.bin2hex(random_bytes(5));

        $this->post('/api-admin/instructor/start', [
            'base_url' => 'https://api.example.test',
            'service_name' => $service,
            'resource_name' => 'post',
            'endpoint_paths' => '/posts',
            'auth_type' => 'header',
            'auth_header' => 'X-API-Key',
            'api_key' => 'probe-credential',
        ])->assertRedirect('/api-admin/instructor');

        $repository = app(InstructorSessionRepository::class);
        $userId = $user->getKey();
        $this->assertIsInt($userId);
        $sessionId = 'moonshine-user-'.$userId;
        $session = $repository->load($sessionId);
        $this->assertInstanceOf(InstructorSession::class, $session);
        $this->assertSame('X-API-Key', $session->input['auth_header']);
        $this->assertSame('X-API-Key', data_get($session->proposal, 'service.auth_header'));
        $this->assertSame('[redacted]', $session->input['api_key']);
        $this->assertSame('[redacted]', data_get($session->publicSummary(), 'proposal.service.api_key'));
        $this->assertStringNotContainsString('probe-credential', json_encode($session->toArray(), JSON_THROW_ON_ERROR));

        $this->post('/api-admin/instructor/confirm', ['api_key' => 'generation-credential'])
            ->assertRedirect('/api-admin/instructor');

        $session = $repository->load($sessionId);
        $this->assertInstanceOf(InstructorSession::class, $session);
        $this->assertTrue(data_get($session->applyResult, 'success'), json_encode($session->applyResult, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('generation-credential', json_encode($session->toArray(), JSON_THROW_ON_ERROR));
        $result = new McpServer()->executeTool('fetch_resource_items', ['service' => $service, 'resource' => 'post']);
        $this->assertTrue($result['success'], json_encode($result, JSON_THROW_ON_ERROR));
        $this->assertSame('Header-authenticated post', data_get($result, 'data.items.0.title'));
        Http::assertSent(static fn (Request $request): bool => $request->hasHeader('X-API-Key', 'generation-credential'));
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
