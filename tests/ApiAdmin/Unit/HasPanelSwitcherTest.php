<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Unit;

use App\ApiAdmin\Layouts\HasPanelSwitcher;
use App\ApiAdmin\Layouts\LocalPanelLayout;
use App\ApiAdmin\MCP\ServiceRegistry;
use App\ApiAdmin\Providers\ApiAdminServiceProvider;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use LogicException;
use MoonShine\Contracts\AssetManager\AssetElementContract;
use MoonShine\Contracts\Core\DependencyInjection\CoreContract;
use MoonShine\Contracts\MenuManager\MenuElementContract;
use MoonShine\Contracts\UI\ComponentContract;
use MoonShine\Laravel\Providers\MoonShineServiceProvider;
use MoonShine\MenuManager\MenuItem;
use MoonShine\MultiPanels\MultiPanelResource;
use MoonShine\MultiPanels\Providers\MultiPanelServiceProvider;
use MoonShine\UI\Components\FlexibleRender;
use MoonShine\UI\Components\MoonShineComponent;
use Orchestra\Testbench\TestCase;
use Override;

final class HasPanelSwitcherTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            MoonShineServiceProvider::class,
            MultiPanelServiceProvider::class,
            ApiAdminServiceProvider::class,
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        MoonShineComponent::consoleMode();
        app('view')->addNamespace(
            'api-admin',
            realpath(__DIR__.'/../../../resources/views/api-admin') ?: resource_path('views/api-admin'),
        );
    }

    protected function tearDown(): void
    {
        MoonShineComponent::consoleMode(false);

        parent::tearDown();
    }

    public function test_hides_switcher_for_single_local_service_panel(): void
    {
        $this->configurePanels(
            services: [
                'catalog' => [
                    'name' => 'catalog',
                    'kind' => ServiceRegistry::SERVICE_KIND_LOCAL,
                    'driver' => 'eloquent',
                    'panel' => 'catalog-admin',
                ],
            ],
            panels: [
                'catalog-admin' => [
                    'prefix' => 'catalog-admin',
                    'title' => 'Catalog Admin',
                    'api_admin_service' => 'catalog',
                    'api_admin_kind' => ServiceRegistry::SERVICE_KIND_LOCAL,
                ],
            ],
            path: '/catalog-admin',
        );

        $layout = new PanelSwitcherLayoutFixture;

        $this->assertNull($layout->switcher());
        $this->assertSame([], $layout->sidebarSlotComponents());
    }

    public function test_renders_switcher_for_multiple_local_service_panels(): void
    {
        $this->configurePanels(
            services: [
                'catalog' => [
                    'name' => 'catalog',
                    'kind' => ServiceRegistry::SERVICE_KIND_LOCAL,
                    'panel' => 'catalog-admin',
                ],
                'support' => [
                    'name' => 'support',
                    'driver' => 'eloquent',
                    'panel' => 'support-admin',
                ],
            ],
            panels: [
                'catalog-admin' => [
                    'prefix' => 'catalog-admin',
                    'title' => 'Catalog Admin',
                    'api_admin_service' => 'catalog',
                    'api_admin_kind' => ServiceRegistry::SERVICE_KIND_LOCAL,
                ],
                'support-admin' => [
                    'prefix' => 'support-admin',
                    'title' => 'Support Admin',
                    'api_admin_service' => 'support',
                    'api_admin_kind' => ServiceRegistry::SERVICE_KIND_LOCAL,
                ],
            ],
            path: '/support-admin',
        );

        $data = $this->switcherViewData((new PanelSwitcherLayoutFixture)->switcher());

        $this->assertSame('Support Admin', $data['title']);
        $this->assertCount(2, $data['items']);
        $this->assertSame(
            [ServiceRegistry::SERVICE_KIND_LOCAL, ServiceRegistry::SERVICE_KIND_LOCAL],
            array_column($data['items'], 'kind'),
        );
    }

    public function test_local_panel_layout_shows_own_resources_and_switcher(): void
    {
        app(CoreContract::class)->resources([LocalPanelMenuResource::class, OtherPanelMenuResource::class]);

        $this->configurePanels(
            services: [
                'catalog' => ['name' => 'catalog', 'kind' => ServiceRegistry::SERVICE_KIND_LOCAL, 'panel' => 'catalog-admin'],
                'blog' => ['name' => 'blog', 'kind' => ServiceRegistry::SERVICE_KIND_REST, 'panel' => 'blog-admin'],
            ],
            panels: [
                'catalog-admin' => ['prefix' => 'catalog-admin', 'title' => 'Catalog'],
                'blog-admin' => ['prefix' => 'blog-admin', 'title' => 'Blog'],
            ],
            path: '/catalog-admin',
        );

        $fixture = new LocalPanelMenuFixture;

        $this->assertCount(1, $fixture->menu());
        $this->assertInstanceOf(MenuItem::class, $fixture->menu()[0]);
        $this->assertSame(['Catalog', 'Blog'], array_column($this->switcherViewData($fixture->switcher())['items'], 'title'));
        $this->assertContains(HasPanelSwitcher::class, class_uses(LocalPanelLayout::class));
    }

    public function test_renders_switcher_for_single_rest_service_panel(): void
    {
        $this->configurePanels(
            services: [
                'blog' => [
                    'name' => 'blog',
                    'kind' => ServiceRegistry::SERVICE_KIND_REST,
                    'panel' => 'blog-admin',
                ],
            ],
            panels: [
                'blog-admin' => [
                    'prefix' => 'blog-admin',
                    'title' => 'Blog Admin',
                ],
            ],
            path: '/blog-admin',
        );

        $data = $this->switcherViewData((new PanelSwitcherLayoutFixture)->switcher());

        $this->assertSame('Blog Admin', $data['title']);
        $this->assertCount(1, $data['items']);
        $this->assertSame(ServiceRegistry::SERVICE_KIND_REST, $data['items'][0]['kind']);
    }

    public function test_uses_all_panel_config_as_fallback_without_service_metadata(): void
    {
        $this->configurePanels(
            services: [],
            panels: [
                'admin' => [
                    'prefix' => 'admin',
                    'title' => 'Admin',
                ],
            ],
            path: '/admin',
        );

        $data = $this->switcherViewData((new PanelSwitcherLayoutFixture)->switcher());

        $this->assertSame('Admin', $data['title']);
        $this->assertCount(1, $data['items']);
        $this->assertSame('/admin', $data['items'][0]['href']);
    }

    /**
     * @param array<string, array<string, mixed>> $services
     * @param array<string, array<string, mixed>> $panels
     */
    private function configurePanels(array $services, array $panels, string $path): void
    {
        config([
            'api-admin.services' => $services,
            'moonshine.panels' => $panels,
        ]);

        app()->instance('request', Request::create($path, 'GET'));
    }

    /**
     * @return array{title: string, items: list<array{href: string, title: string, active: bool, kind: string}>}
     */
    private function switcherViewData(?FlexibleRender $switcher): array
    {
        $this->assertInstanceOf(FlexibleRender::class, $switcher);

        $content = $switcher->toArray()['content'] ?? null;
        $this->assertInstanceOf(View::class, $content);

        $data = $content->getData();
        $this->assertIsString($data['title'] ?? null);
        $this->assertIsArray($data['items'] ?? null);

        /** @var array{title: string, items: list<array{href: string, title: string, active: bool, kind: string}>} $data */
        return $data;
    }
}

abstract class PanelSwitcherLayoutBase
{
    /**
     * @return array<int|string, AssetElementContract>
     */
    protected function assets(): array
    {
        return [];
    }

    protected function getCore(): CoreContract
    {
        throw new LogicException('Panel switcher tests should not resolve MoonShine core.');
    }
}

final class PanelSwitcherLayoutFixture extends PanelSwitcherLayoutBase
{
    use HasPanelSwitcher;

    public function switcher(): ?FlexibleRender
    {
        return $this->panelSwitcher();
    }

    /**
     * @return list<ComponentContract>
     */
    public function sidebarSlotComponents(): array
    {
        return $this->apiAdminSidebarSlot();
    }
}

final class LocalPanelMenuFixture extends PanelSwitcherLayoutBase
{
    use HasPanelSwitcher;

    /**
     * @return list<MenuElementContract>
     */
    public function menu(): array
    {
        return $this->panelMenu();
    }

    public function switcher(): ?FlexibleRender
    {
        return $this->panelSwitcher();
    }

    protected function getCore(): CoreContract
    {
        return app(CoreContract::class);
    }
}

/**
 * @extends MultiPanelResource<Model>
 */
final class LocalPanelMenuResource extends MultiPanelResource
{
    #[Override]
    protected string $panel = 'catalog-admin';
}

/**
 * @extends MultiPanelResource<Model>
 */
final class OtherPanelMenuResource extends MultiPanelResource
{
    #[Override]
    protected string $panel = 'other-admin';
}
