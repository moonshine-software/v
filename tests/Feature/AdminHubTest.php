<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use MoonShine\Laravel\Models\MoonshineUser;
use Tests\TestCase;

/**
 * Feature tests for the multi-panel admin routing.
 *
 * Routes are registered at boot time so we can assert their existence directly.
 * The hub lives at /admin; generated service panels come from the per-installation
 * registry and are never part of the repository. Rendered per-panel content
 * (layouts, pages) is covered via Playwright — MoonShine's configurator is bound
 * per-resolve in unit tests, which prevents MultiPanelMiddleware's overrides
 * from reaching the controller inside a $this->get() call.
 */
class AdminHubTest extends TestCase
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

    public function test_admin_hub_requires_authentication(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_admin_hub_is_reachable_when_authenticated(): void
    {
        Http::fake();

        $this->actingAs($this->user(), 'moonshine')->get('/admin')->assertOk();
    }

    public function test_hub_panel_uses_a_flat_prefix(): void
    {
        $this->assertSame('admin', Route::getRoutes()->getByName('moonshine.admin.index')?->uri());
    }

    public function test_repository_config_declares_no_services(): void
    {
        $this->assertSame([], $this->loadConfig('api-admin.php')['services'] ?? null);
    }

    public function test_panels_come_only_from_the_generated_registry(): void
    {
        $generated = tempnam(sys_get_temp_dir(), 'panels');
        $this->assertIsString($generated);
        file_put_contents($generated, "<?php return ['catalog-admin' => ['prefix' => 'catalog-admin', 'title' => 'Catalog']];");

        putenv('API_ADMIN_GENERATED_PANELS_PATH='.$generated);

        try {
            $panels = $this->loadConfig('moonshine.php')['panels'] ?? null;
        } finally {
            putenv('API_ADMIN_GENERATED_PANELS_PATH');
            unlink($generated);
        }

        $this->assertIsArray($panels);
        $this->assertSame(['catalog-admin', 'admin'], array_keys($panels));
    }

    /**
     * @return array<mixed>
     */
    private function loadConfig(string $file): array
    {
        $config = include config_path($file);
        $this->assertIsArray($config);

        return $config;
    }

    private function user(): MoonshineUser
    {
        return MoonshineUser::query()->where('email', 'admin')->firstOrFail();
    }
}
