<?php

declare(strict_types=1);

namespace App\ApiAdmin\Http\Middleware;

use App\ApiAdmin\MCP\HostedMcpController;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

final class EnsureHostedMcpEnabled
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        if (config('api-admin.hosted.mode') !== 'hosted' || config('api-admin.hosted.mcp.enabled', false) !== true) {
            Log::notice('Disabled HTTP transport rejected');
            abort(404);
        }

        if (strlen($request->getContent()) > 1048576) {
            abort(413);
        }

        $version = $request->header('MCP-Protocol-Version');
        if ($version !== null && ! in_array($version, HostedMcpController::PROTOCOL_VERSIONS, true)) {
            abort(400, 'Unsupported MCP protocol version.');
        }

        $origin = $request->header('Origin');
        if ($origin !== null) {
            $url = config('app.url');
            $expected = is_string($url) ? Request::create($url)->getSchemeAndHttpHost() : null;
            if ($origin !== $expected) {
                Log::notice('Rejected foreign origin');
                abort(403, 'Invalid MCP origin.');
            }
        }

        return $next($request);
    }
}
