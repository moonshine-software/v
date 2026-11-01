<?php

declare(strict_types=1);

namespace App\ApiAdmin\Auth;

use App\ApiAdmin\Providers\ApiAdminServiceProvider;
use App\ApiAdmin\ServiceConfig\AdminAuthConfig;
use App\ApiAdmin\ServiceConfig\ServiceConfig;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class EnsureDelegatedPanelState
{
    /**
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $service = $this->serviceForCurrentPanel($request);
        $stateType = $service instanceof ServiceConfig ? $this->requiredStateType($service) : null;

        if (! $service instanceof ServiceConfig || $stateType === null || $this->hasState($request, $service, $stateType)) {
            return $next($request);
        }

        Log::warning('Missing delegated panel session state', [
            'service' => $service->name,
            'panel' => $service->panel,
            'auth_type' => $service->authType,
            'admin_auth_mode' => $service->adminAuth->mode,
            'required_state' => $stateType,
            'path' => $request->path(),
        ]);

        $this->logoutPanelGuard($service);

        return redirect()->guest($this->loginUrl($service->panel));
    }

    private function serviceForCurrentPanel(Request $request): ?ServiceConfig
    {
        $panel = $this->panelName($request);
        $services = config('api-admin.services', []);

        if ($panel === null || ! is_array($services)) {
            return null;
        }

        foreach ($services as $name => $config) {
            if (! is_array($config)) {
                continue;
            }

            if (($config['panel'] ?? null) !== $panel) {
                continue;
            }

            try {
                return ApiAdminServiceProvider::resolveService((string) $name);
            } catch (Throwable $e) {
                Log::warning('Failed to resolve panel service', [
                    'service' => (string) $name,
                    'panel' => $panel,
                    'error' => $e->getMessage(),
                ]);

                return ServiceConfig::fromArray($this->fallbackServiceConfig($config, (string) $name));
            }
        }

        return null;
    }

    private function panelName(Request $request): ?string
    {
        $routeName = $request->route()?->getName();

        if (is_string($routeName) && str_starts_with($routeName, 'moonshine.')) {
            $panelAndRoute = substr($routeName, strlen('moonshine.'));
            $separator = strpos($panelAndRoute, '.');

            if ($separator !== false) {
                return substr($panelAndRoute, 0, $separator);
            }
        }

        $panel = $request->getPanelName();

        return $panel !== '' ? $panel : null;
    }

    /**
     * @param array<mixed> $config
     *
     * @return array<string, mixed>
     */
    private function fallbackServiceConfig(array $config, string $name): array
    {
        $result = [];

        foreach ($config as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        $result['name'] = $name;

        return $result;
    }

    private function requiredStateType(ServiceConfig $service): ?string
    {
        if ($service->adminAuth->mode === AdminAuthConfig::MODE_DELEGATED_TOKEN) {
            return 'token';
        }

        if ($service->adminAuth->mode === AdminAuthConfig::MODE_OAUTH2_AUTHORIZATION_CODE) {
            return 'oauth';
        }

        if (! $service->adminLogin) {
            return null;
        }

        return match ($service->authType) {
            'oauth2_password' => 'oauth',
            'session' => 'session',
            default => null,
        };
    }

    private function hasState(Request $request, ServiceConfig $service, string $stateType): bool
    {
        return match ($stateType) {
            'token' => DelegatedAuthSession::token($request, $service->name) !== null,
            'oauth' => DelegatedAuthSession::oauth($request, $service->name) !== null,
            'session' => DelegatedAuthSession::sessionState($request, $service->name) instanceof SessionState,
            default => true,
        };
    }

    private function logoutPanelGuard(ServiceConfig $service): void
    {
        // Delegated panels always have their own guard, so the hub session survives.
        $guardName = UpstreamUserProvider::guardName($service->panel);

        Auth::guard($guardName)->logout();

        Log::info('Logged out panel guard before delegated re-auth', [
            'service' => $service->name,
            'panel' => $service->panel,
            'guard' => $guardName,
        ]);
    }

    private function loginUrl(string $panel): string
    {
        $routeName = 'moonshine.'.$panel.'.login';

        if (Route::has($routeName)) {
            return route($routeName);
        }

        return url('/'.trim($panel, '/').'/login');
    }
}
