<?php

declare(strict_types=1);

namespace App\ApiAdmin\Layouts;

use App\ApiAdmin\MCP\ServiceRegistry;
use App\ApiAdmin\Resources\AbstractRestResource;
use App\ApiAdmin\Resources\MultiPanelRestResource;
use Illuminate\Support\Facades\Vite;
use MoonShine\AssetManager\Raw;
use MoonShine\Contracts\AssetManager\AssetElementContract;
use MoonShine\Contracts\MenuManager\MenuElementContract;
use MoonShine\Contracts\UI\ComponentContract;
use MoonShine\MenuManager\MenuGroup;
use MoonShine\MenuManager\MenuItem;
use MoonShine\MultiPanels\MultiPanelResource;
use MoonShine\UI\Components\FlexibleRender;
use Throwable;

/**
 * Trait for layouts in a multi-panel setup.
 *
 * Provides:
 * - panelMenu(): auto-discovered menu filtered by current panel
 * - panelSwitcher(): MoonShine dropdown to navigate between panels
 * - getFooterMenu(): links to this project's documentation and MoonShine's
 */
trait HasPanelSwitcher
{
    public const string DOCUMENTATION_URL = 'https://github.com/moonshine-software/v/tree/main/docs';

    /**
     * @return array<string, string>
     */
    protected function getFooterMenu(): array
    {
        return [
            self::DOCUMENTATION_URL => 'Documentation',
            'https://getmoonshine.app/docs' => 'MoonShine',
        ];
    }

    /**
     * @return list<AssetElementContract>
     */
    protected function assets(): array
    {
        $assets = parent::assets();
        foreach ($this->apiAdminRealtimeAssets() as $asset) {
            $assets[] = $asset;
        }

        return array_values($assets);
    }

    /**
     * @return list<ComponentContract>
     */
    protected function apiAdminSidebarSlot(): array
    {
        $components = [];
        $switcher = $this->panelSwitcher();
        if ($switcher instanceof ComponentContract) {
            $components[] = $switcher;
        }

        $realtime = $this->realtimeRefreshComponent();
        if ($realtime instanceof ComponentContract) {
            $components[] = $realtime;
        }

        return $components;
    }

    /**
     * Build menu items only from resources belonging to the current panel.
     *
     * @return list<MenuElementContract>
     */
    protected function panelMenu(): array
    {
        $panelName = request()->getPanelName();
        $resources = $this->getCore()->getResources()->toArray();

        $menu = [];
        $groups = [];

        foreach ($resources as $resource) {
            $resourcePanel = match (true) {
                $resource instanceof MultiPanelRestResource => $resource->getPanel(),
                $resource instanceof MultiPanelResource => $resource->getRouter()->getParam('panelName'),
                default => null,
            };

            if ($resourcePanel !== $panelName) {
                continue;
            }

            $item = MenuItem::make($resource::class);
            $group = $resource->getGroup();

            if ($group !== null) {
                $groups[$group][] = $item;
            } else {
                $menu[] = $item;
            }
        }

        foreach ($groups as $label => $items) {
            $menu[] = MenuGroup::make($label, $items);
        }

        return $menu;
    }

    /**
     * Render a panel switcher dropdown in the sidebar.
     */
    protected function panelSwitcher(): ?FlexibleRender
    {
        $panelsRaw = config('moonshine.panels', []);
        /** @var array<string, mixed> $panels */
        $panels = is_array($panelsRaw) ? $panelsRaw : [];
        $currentPanel = request()->getPanelName();
        $servicePanels = $this->apiAdminServicePanelIndex();
        $hasServicePanelIndex = $servicePanels !== [];

        $items = [];
        // The hub is not a service panel, so it has no entry below to take a title from.
        $currentTitle = 'Services';

        foreach ($panels as $key => $panel) {
            $keyStr = (string) $key;
            $panelConfig = is_array($panel) ? $panel : [];

            if ($hasServicePanelIndex && ! isset($servicePanels[$keyStr]) && ! isset($panelConfig['api_admin_service'])) {
                continue;
            }

            $prefixRaw = $panelConfig['prefix'] ?? $keyStr;
            $prefix = is_string($prefixRaw) ? $prefixRaw : $keyStr;
            $titleRaw = $panelConfig['title'] ?? ucfirst($keyStr);
            $title = is_string($titleRaw) ? $titleRaw : $keyStr;
            $url = '/'.ltrim($prefix, '/');
            $isCurrent = ($keyStr === $currentPanel);

            if ($isCurrent) {
                $currentTitle = $title;
            }

            $items[] = [
                'href' => $url,
                'title' => $title,
                'active' => $isCurrent,
                'kind' => $this->apiAdminPanelKind($keyStr, $panelConfig, $servicePanels),
            ];
        }

        if ($this->shouldHideSingleLocalSwitcher($items)) {
            return null;
        }

        return FlexibleRender::make(
            view('api-admin::components.panel-switcher', [
                'title' => $currentTitle,
                'items' => $items,
            ])
        );
    }

    /**
     * @return array<string, array{name: string, kind: string}>
     */
    private function apiAdminServicePanelIndex(): array
    {
        $servicesRaw = config('api-admin.services', []);
        $services = is_array($servicesRaw) ? $servicesRaw : [];
        $panels = [];

        foreach ($services as $name => $service) {
            if (! is_array($service)) {
                continue;
            }

            $panel = $service['panel'] ?? null;
            if (! is_string($panel)) {
                continue;
            }
            if ($panel === '') {
                continue;
            }

            $panels[$panel] = [
                'name' => (string) $name,
                'kind' => $this->apiAdminServiceKind($service),
            ];
        }

        return $panels;
    }

    /**
     * @param array<mixed> $panel
     * @param array<string, array{name: string, kind: string}> $servicePanels
     */
    private function apiAdminPanelKind(string $panelKey, array $panel, array $servicePanels): string
    {
        if (isset($servicePanels[$panelKey])) {
            return $servicePanels[$panelKey]['kind'];
        }

        $kind = $panel['api_admin_kind'] ?? null;

        return $kind === ServiceRegistry::SERVICE_KIND_LOCAL
            ? ServiceRegistry::SERVICE_KIND_LOCAL
            : ServiceRegistry::SERVICE_KIND_REST;
    }

    /**
     * @param array<mixed> $service
     */
    private function apiAdminServiceKind(array $service): string
    {
        $kind = $service['kind'] ?? $service['driver'] ?? ServiceRegistry::SERVICE_KIND_REST;

        return match ($kind) {
            ServiceRegistry::SERVICE_KIND_LOCAL, 'eloquent' => ServiceRegistry::SERVICE_KIND_LOCAL,
            default => ServiceRegistry::SERVICE_KIND_REST,
        };
    }

    /**
     * @param list<array{href: string, title: string, active: bool, kind: string}> $items
     */
    private function shouldHideSingleLocalSwitcher(array $items): bool
    {
        return count($items) === 1 && $items[0]['kind'] === ServiceRegistry::SERVICE_KIND_LOCAL;
    }

    protected function realtimeRefreshComponent(): ?FlexibleRender
    {
        $resource = $this->currentApiAdminResource();
        if (! $resource instanceof AbstractRestResource) {
            return null;
        }

        $metadata = $resource->realtimeRefreshMetadata($this->currentApiAdminPageType());
        if ($metadata['enabled'] !== true) {
            return null;
        }

        return FlexibleRender::make(
            view('api-admin::components.realtime-refresh', [
                'metadata' => $metadata,
            ])
        );
    }

    private function currentApiAdminResource(): ?AbstractRestResource
    {
        $resourceUri = request()->route('resourceUri');
        if (! is_string($resourceUri) || $resourceUri === '') {
            return null;
        }

        foreach ($this->getCore()->getResources()->toArray() as $resource) {
            if (! $resource instanceof AbstractRestResource) {
                continue;
            }

            if ($resource->getUriKey() === $resourceUri) {
                return $resource;
            }
        }

        return null;
    }

    private function currentApiAdminPageType(): string
    {
        $routeName = request()->route()?->getName();
        $routeName = is_string($routeName) ? $routeName : '';
        $pageUri = request()->route('pageUri');
        $pageUri = is_string($pageUri) ? $pageUri : '';

        if (str_contains($routeName, 'crud.create') || str_contains($routeName, 'crud.edit') || str_contains($pageUri, 'form')) {
            return 'form';
        }

        if (str_contains($routeName, 'crud.show') || str_contains($pageUri, 'detail')) {
            return 'detail';
        }

        return 'index';
    }

    /**
     * @return list<AssetElementContract>
     */
    private function apiAdminRealtimeAssets(): array
    {
        if (! $this->apiAdminViteIsAvailable()) {
            return [];
        }

        try {
            return [
                Raw::make((string) Vite::withEntryPoints(['resources/js/app.js'])->toHtml()),
            ];
        } catch (Throwable) {
            return [];
        }
    }

    private function apiAdminViteIsAvailable(): bool
    {
        return is_file(public_path('hot')) || is_file(public_path('build/manifest.json'));
    }
}
