<?php

declare(strict_types=1);

namespace App\MoonShine\ApiAdmin\Pages;

use App\ApiAdmin\MCP\ServiceRegistry;
use App\ApiAdmin\Providers\ApiAdminServiceProvider;
use App\ApiAdmin\ServiceConfig\ServiceConfig;
use App\ApiAdmin\ServiceConfig\ServiceHealthChecker;
use App\ApiAdmin\Tenancy\TenantRoutes;
use Illuminate\Support\Facades\Log;
use MoonShine\Contracts\UI\ComponentContract;
use MoonShine\Laravel\Pages\Page;
use MoonShine\MenuManager\Attributes\SkipMenu;
use MoonShine\UI\Components\FlexibleRender;
use Override;
use Throwable;

#[SkipMenu]
class AdminHubPage extends Page
{
    #[Override]
    public function getTitle(): string
    {
        return $this->title ?: 'Services';
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    public function getBreadcrumbs(): array
    {
        return [
            '#' => $this->getTitle(),
        ];
    }

    /**
     * @return list<ComponentContract>
     */
    protected function components(): iterable
    {
        $hubRaw = $this->getCore()->getConfig()->get('prefix');
        $hub = is_string($hubRaw) ? $hubRaw : 'admin';
        $checker = app(ServiceHealthChecker::class);
        $registeredServices = (new ServiceRegistry)->listServices();
        $visiblePanels = [];
        foreach ($registeredServices as $registeredService) {
            $panel = $registeredService['panel'] ?? null;
            if (is_string($panel) && $panel !== '') {
                $visiblePanels[$panel] = true;
            }
        }

        $items = [];
        $mode = $registeredServices === [] ? 'first-run' : 'add-service';

        Log::debug('AdminHubPage: instructor visibility resolved', [
            'mode' => $mode,
            'services_count' => count($registeredServices),
        ]);

        $items[] = [
            'title' => $registeredServices === [] ? 'Connect your first service' : 'Add service',
            'url' => $this->instructorUrl(),
            'description' => $registeredServices === []
                ? 'Probe an API and review the first generated resource'
                : 'Register another upstream API',
            'status' => null,
            'base_url' => null,
            'is_action' => true,
        ];

        $panelsRaw = config('moonshine.panels', []);
        /** @var array<string, array<string, mixed>> $panels */
        $panels = is_array($panelsRaw) ? $panelsRaw : [];

        foreach ($panels as $key => $panel) {
            $keyStr = (string) $key;
            $prefixRaw = $panel['prefix'] ?? $keyStr;
            $prefix = trim(is_string($prefixRaw) ? $prefixRaw : $keyStr, '/');
            if ($prefix === '') {
                continue;
            }
            if ($prefix === $hub) {
                continue;
            }
            if ($visiblePanels !== [] && ! isset($visiblePanels[$keyStr])) {
                continue;
            }

            $service = $this->resolvePanelService($keyStr);
            $health = $service instanceof ServiceConfig
                ? $this->formatStatus($checker->check($service))
                : null;

            $titleRaw = $panel['title'] ?? ucfirst($keyStr);
            $title = is_string($titleRaw) ? $titleRaw : $keyStr;

            $items[] = [
                'title' => $title,
                'url' => '/'.TenantRoutes::expandPrefix($prefix),
                'description' => $service instanceof ServiceConfig ? $service->name : $keyStr,
                'status' => $health,
                'base_url' => $service instanceof ServiceConfig ? $service->baseUrl : null,
                'is_action' => false,
            ];
        }

        return [
            FlexibleRender::make(
                view('api-admin::components.admin-hub', [
                    'items' => $items,
                ])
            ),
        ];
    }

    private function resolvePanelService(string $panelKey): ?ServiceConfig
    {
        $servicesRaw = config('api-admin.services', []);
        /** @var array<string, array<string, mixed>> $services */
        $services = is_array($servicesRaw) ? $servicesRaw : [];

        foreach ($services as $name => $service) {
            if (($service['panel'] ?? null) !== $panelKey) {
                continue;
            }

            try {
                return ApiAdminServiceProvider::resolveService((string) $name);
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }

    private function instructorUrl(): string
    {
        return route('moonshine.admin.page', [
            ...TenantRoutes::routeParams(),
            'pageUri' => InstructorPage::URI,
        ]);
    }

    /**
     * @param array{status: string, code: int|null, url: string} $health
     *
     * @return array{label: string, state: string}
     */
    private function formatStatus(array $health): array
    {
        $label = match ($health['status']) {
            ServiceHealthChecker::STATUS_OK => 'Online',
            ServiceHealthChecker::STATUS_DEGRADED => 'Degraded',
            default => 'Offline',
        };

        if ($health['code'] !== null) {
            $label .= ' · '.$health['code'];
        }

        return [
            'label' => $label,
            'state' => $health['status'],
        ];
    }
}
