<?php

declare(strict_types=1);

namespace App\ApiAdmin\Resources;

use App\ApiAdmin\Tenancy\TenantRoutes;
use Illuminate\Support\Str;
use MoonShine\Contracts\Core\DependencyInjection\RouterContract;
use Override;

/**
 * REST resource bound to a specific panel in a multi-panel setup.
 *
 * Each microservice gets its own panel (URL prefix, layout, menu).
 * Resources extend this class and set $panel to their service name.
 */
abstract class MultiPanelRestResource extends AbstractRestResource
{
    /**
     * Panel identifier this resource belongs to.
     * Must match a key in config('moonshine.panels').
     */
    protected string $panel = 'default';

    /**
     * Get the panel name for this resource.
     */
    public function getPanel(): string
    {
        return $this->panel;
    }

    /**
     * Get the router with panel-aware route generation.
     *
     * Injects the panelName parameter so all URLs generated for this resource
     * include the correct panel prefix (e.g. /blog-admin/posts instead of /admin/posts).
     */
    #[Override]
    public function getRouter(): RouterContract
    {
        return parent::getRouter()->withParams([
            'panelName' => $this->panel,
            ...TenantRoutes::routeParams(),
        ]);
    }

    /**
     * Get the URI key with panel prefix for unique identification.
     */
    #[Override]
    public function getUriKey(): string
    {
        $serviceKey = $this->getServiceUriKey();

        if ($serviceKey === '') {
            return parent::getUriKey();
        }

        return $serviceKey.'-'.parent::getUriKey();
    }

    private function getServiceUriKey(): string
    {
        $resourceClass = static::class;
        $resourcePath = Str::after($resourceClass, '\\Resources\\');

        if ($resourcePath === $resourceClass) {
            return '';
        }

        // The final segments are Service\Resource\ResourceClass; hosted adds tenant folders before them.
        $segments = explode('\\', $resourcePath);
        $service = count($segments) >= 3 ? $segments[count($segments) - 3] : $segments[0];

        return Str::of($service)->kebab()->toString();
    }
}
