<?php

declare(strict_types=1);

namespace App\ApiAdmin\Http\Middleware;

use App\ApiAdmin\Resources\AbstractRestResource;
use App\ApiAdmin\Tenancy\TenantBrowserAccess;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use MoonShine\Contracts\Core\CrudPageContract;
use MoonShine\Contracts\Core\ResourceContract;
use Symfony\Component\HttpFoundation\Response;

final class EnsureTenantMembership
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        if (config('api-admin.hosted.mode', 'local') === 'hosted') {
            $guard = moonshineConfig()->getGuard();
            $user = Auth::guard($guard)->user();
            // Guests still need the normal panel login routes and auth middleware.
            if ($user !== null) {
                app(TenantBrowserAccess::class)->authorize($user);
            }

            $resourceUri = $request->route('resourceUri');
            $pageUri = $request->route('pageUri');
            $resource = is_string($resourceUri) ? moonshine()->getResources()->findByUri($resourceUri) : null;
            $page = ! $resource instanceof ResourceContract && is_string($pageUri)
                ? moonshine()->getPages()->findByUri($pageUri)
                : null;

            if (($resourceUri !== null && ! $resource instanceof AbstractRestResource) || $page instanceof CrudPageContract) {
                Log::notice('Denied native resource or unattached CRUD page', [
                    'resource' => $resourceUri,
                    'page' => $pageUri,
                ]);
                abort(403, 'Platform resources are unavailable in hosted panels.');
            }
        }

        return $next($request);
    }
}
