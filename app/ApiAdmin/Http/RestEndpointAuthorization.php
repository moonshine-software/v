<?php

declare(strict_types=1);

namespace App\ApiAdmin\Http;

use App\ApiAdmin\Auth\DelegatedAuthSession;
use App\ApiAdmin\Auth\SessionState;
use App\ApiAdmin\Auth\UpstreamUserProvider;
use App\ApiAdmin\Fields\RestHasMany;
use App\ApiAdmin\Fields\RestHasOne;
use App\ApiAdmin\Providers\ApiAdminServiceProvider;
use App\ApiAdmin\Resources\AbstractRestResource;
use App\ApiAdmin\ServiceConfig\AdminAuthConfig;
use App\ApiAdmin\ServiceConfig\ServiceConfig;
use App\ApiAdmin\Tenancy\TenantBrowserAccess;
use App\ApiAdmin\Tenancy\TenantContext;
use App\ApiAdmin\Tenancy\TenantResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use MoonShine\Support\Enums\Ability;
use MoonShine\Support\Enums\Action;

final class RestEndpointAuthorization
{
    public function relation(Request $request, AbstractRestResource $parent, RestHasMany|RestHasOne $field, Ability $ability): void
    {
        abort_unless($field->allowsAction($ability), 403);
        $parentId = $request->input('_parent_id');
        abort_unless((is_string($parentId) || is_int($parentId)) && (string) $parentId !== '', 422, 'Parent ID required');
        $this->validateId($parentId);
        $key = $request->input('_key');
        if ($key !== null) {
            abort_unless(is_string($key) || is_int($key), 422);
            $this->validateId($key);
        }
        $this->authorize($parent, Ability::VIEW, $parentId);
        if (! in_array($ability, [Ability::VIEW, Ability::VIEW_ANY], true)) {
            $this->authorize($parent, Ability::UPDATE, $parentId);
        }
        $target = $field->getTargetService();
        if ($target !== null && $target !== $parent->getServiceConfig()->name) {
            $this->authenticate($request, ApiAdminServiceProvider::resolveService($target));
        }
        $related = $field->getRelatedResource();
        if ($related instanceof AbstractRestResource) {
            $this->authenticate($request, $related->getServiceConfig());
            $key = $request->input('_key');
            $this->authorize($related, $ability, is_string($key) || is_int($key) ? $key : null);
        }
    }

    private function validateId(string|int $id): void
    {
        abort_unless(preg_match('/^[a-zA-Z0-9_~.-]+$/D', (string) $id) === 1
            && ! in_array((string) $id, ['.', '..'], true), 422, 'Invalid record ID');
    }

    public function authenticate(Request $request, ServiceConfig $service): void
    {
        if (config('api-admin.hosted.mode', 'local') === 'hosted') {
            $resolver = app()->get(TenantResolver::class);
            $tenant = $resolver->resolve();
            abort_unless($tenant instanceof TenantContext && ! $tenant->isLocal && $service->tenantId === $tenant->id, 403, 'Resource belongs to another tenant');
        }
        $configuredGuard = config("moonshine.panels.{$service->panel}.auth.guard", config('moonshine.auth.guard', 'moonshine'));
        $guardName = $service->adminAuth->mode === AdminAuthConfig::MODE_MOONSHINE
            ? (is_string($configuredGuard) ? $configuredGuard : 'moonshine')
            : UpstreamUserProvider::guardName($service->panel, $service->tenantId);
        $guard = Auth::guard($guardName);
        abort_unless($guard->check(), 401);
        app(TenantBrowserAccess::class)->authorize($guard->user());

        $stateType = match (true) {
            $service->adminAuth->mode === AdminAuthConfig::MODE_DELEGATED_TOKEN => 'token',
            $service->adminAuth->mode === AdminAuthConfig::MODE_OAUTH2_AUTHORIZATION_CODE => 'oauth',
            $service->adminLogin && $service->authType === 'oauth2_password' => 'oauth',
            $service->adminLogin && $service->authType === 'session' => 'session',
            default => null,
        };
        if ($stateType !== null) {
            $hasState = $request->hasSession() && match ($stateType) {
                'token' => DelegatedAuthSession::token($request, $service->name) !== null,
                'oauth' => DelegatedAuthSession::oauth($request, $service->name) !== null,
                'session' => DelegatedAuthSession::sessionState($request, $service->name) instanceof SessionState,
            };
            abort_unless($hasState, 401, 'Panel login required');
        }

        // Resource policies and upstream user-scoped credentials must see this
        // panel's user rather than Laravel's default web guard.
        Auth::shouldUse($guardName);
    }

    public function authorize(AbstractRestResource $resource, Ability $ability, string|int|null $id = null): void
    {
        if ($id !== null) {
            $this->validateId($id);
        }
        $resource->setItemID($id);
        if ($id !== null) {
            $resource->setItem([$resource->getServiceConfig()->responseMapping->primaryKey => $id]);
        }
        $action = match ($ability) {
            Ability::VIEW, Ability::VIEW_ANY => Action::VIEW,
            Ability::CREATE => Action::CREATE,
            Ability::UPDATE => Action::UPDATE,
            Ability::DELETE => Action::DELETE,
            default => null,
        };
        $allowed = ($action === null || $resource->hasAction($action)) && $resource->can($ability);
        if (! $allowed) {
            Log::warning('REST endpoint denied resource action', ['resource' => $resource->getUriKey(), 'ability' => $ability->value]);
        }
        abort_unless($allowed, 403);

        if ($resource->isWithPolicy()) {
            if ($id !== null) {
                $item = $resource->findItem(orFail: true);
                $resource->setItem($item->getOriginal());
            }
            abort_unless(Gate::allows($ability->value, [$resource, $resource->getItem()]), 403);
        }
    }
}
