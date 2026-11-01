<?php

declare(strict_types=1);

namespace App\ApiAdmin\MCP;

use App\ApiAdmin\Tenancy\TenantContext;
use App\ApiAdmin\Tenancy\TenantResolver;
use App\Models\ApiAdminMcpToken;
use App\Models\ApiAdminTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use stdClass;
use Throwable;

final readonly class HostedMcpController
{
    public const array PROTOCOL_VERSIONS = ['2025-06-18', '2025-03-26'];

    public function __construct(
        private McpServer $server,
    ) {}

    public function __invoke(Request $request, string $tenant): JsonResponse|Response
    {
        $auth = $this->authenticate($request, $tenant);
        if (is_string($auth)) {
            return response()->json($this->error(null, -32001, $auth), Response::HTTP_UNAUTHORIZED);
        }

        $token = $auth->token;
        $context = $auth->context;

        $this->bindTenantContext($context);
        $token->markUsed(Carbon::now());

        Log::info('HostedMcpController: hosted MCP session request', [
            'tenant_id' => $context->id,
            'tenant_slug' => $context->slug,
            'token_id_hash' => $token->tokenIdFingerprint(),
            'client' => $this->clientMetadata($request),
        ]);

        $payload = json_decode($request->getContent(), true);
        if (! is_array($payload)) {
            Log::error('HostedMcpController: invalid JSON-RPC payload', [
                'tenant_id' => $context->id,
                'token_id_hash' => $token->tokenIdFingerprint(),
            ]);

            return response()->json($this->error(null, -32700, 'Parse error'), Response::HTTP_BAD_REQUEST);
        }

        /** @var array<string, mixed> $rpc */
        $rpc = $payload;
        if (($rpc['jsonrpc'] ?? null) !== '2.0' || ! is_string($rpc['method'] ?? null)
            || (isset($rpc['params']) && ! is_array($rpc['params']))
            || (array_key_exists('id', $rpc) && ! is_string($rpc['id']) && ! is_int($rpc['id']))) {
            return response()->json($this->error(null, -32600, 'Invalid Request'), Response::HTTP_BAD_REQUEST);
        }

        if (! array_key_exists('id', $rpc)) {
            Log::debug('Accepted notification', [
                'tenant_id' => $context->id,
                'method' => $rpc['method'],
            ]);

            return response()->noContent(Response::HTTP_ACCEPTED);
        }

        $response = $this->handleRpc($rpc, $this->tokenScopes($token), $context, $token);

        Log::info('HostedMcpController: hosted MCP session completed', [
            'tenant_id' => $context->id,
            'token_id_hash' => $token->tokenIdFingerprint(),
            'method' => $rpc['method'],
        ]);

        return response()->json($response);
    }

    /**
     * @param array<string, mixed> $rpc
     * @param array<int, string> $scopes
     *
     * @return array<string, mixed>
     */
    private function handleRpc(array $rpc, array $scopes, TenantContext $context, ApiAdminMcpToken $token): array
    {
        $id = $rpc['id'] ?? null;
        $method = $rpc['method'] ?? null;
        $params = $rpc['params'] ?? [];

        Log::debug('HostedMcpController: dispatching JSON-RPC method', [
            'tenant_id' => $context->id,
            'token_id_hash' => $token->tokenIdFingerprint(),
            'method' => is_string($method) ? $method : gettype($method),
        ]);

        if (! is_string($method)) {
            return $this->error($id, -32600, 'Invalid Request');
        }

        if ($method === 'initialize') {
            $supported = self::PROTOCOL_VERSIONS;
            $clientVersion = is_array($params) ? ($params['protocolVersion'] ?? null) : null;
            $protocolVersion = is_string($clientVersion) && in_array($clientVersion, $supported, true)
                ? $clientVersion
                : $supported[0];

            return $this->result($id, [
                'protocolVersion' => $protocolVersion,
                'capabilities' => [
                    'tools' => new stdClass,
                ],
                'serverInfo' => [
                    'name' => 'moonshine-v-hosted',
                    'version' => '0.1.0',
                ],
                'instructions' => 'Hosted moonshine-v MCP. Requests are authenticated with a tenant-bound bearer token; tools run only inside that tenant context.',
            ]);
        }

        if ($method === 'ping') {
            return $this->result($id, new stdClass);
        }

        if ($method === 'tools/list') {
            return $this->result($id, [
                'tools' => array_values($this->server->getToolSchemas()),
            ]);
        }

        if ($method === 'tools/call') {
            $name = is_array($params) ? ($params['name'] ?? null) : null;
            $arguments = is_array($params) ? ($params['arguments'] ?? []) : [];

            if (! is_string($name) || ! is_array($arguments)) {
                return $this->error($id, -32602, 'Invalid params');
            }

            /** @var array<string, mixed> $toolArgs */
            $toolArgs = $arguments;
            $result = $this->server->executeTool($name, $toolArgs, array_values($scopes));

            return $this->toolResult($id, $result);
        }

        if ($method === 'shutdown') {
            return $this->result($id, null);
        }

        return $this->error($id, -32601, "Method not found: {$method}");
    }

    private function authenticate(Request $request, string $tenantSlug): HostedMcpAuthentication|string
    {
        $bearer = $request->bearerToken();
        if (! is_string($bearer) || $bearer === '') {
            Log::warning('HostedMcpController: missing bearer token', [
                'tenant_slug' => $tenantSlug,
            ]);

            return 'Missing bearer token';
        }

        try {
            $token = ApiAdminMcpToken::findValidToken($bearer);
        } catch (Throwable $e) {
            Log::error('HostedMcpController: token lookup failed', [
                'tenant_slug' => $tenantSlug,
                'error' => $e->getMessage(),
                'class' => $e::class,
            ]);

            return 'Token lookup failed';
        }

        if (! $token instanceof ApiAdminMcpToken) {
            Log::warning('HostedMcpController: invalid or revoked bearer token', [
                'tenant_slug' => $tenantSlug,
            ]);

            return 'Invalid token';
        }

        $tenant = $token->tenant;
        if (! $tenant instanceof ApiAdminTenant || ! $tenant->active || $tenant->slug !== $tenantSlug) {
            Log::warning('HostedMcpController: token tenant mismatch', [
                'route_tenant' => $tenantSlug,
                'token_id_hash' => $token->tokenIdFingerprint(),
                'token_tenant_id' => $token->api_admin_tenant_id,
            ]);

            return 'Invalid tenant token';
        }

        $context = new TenantContext(
            id: $tenant->keyAsString(),
            slug: $tenant->slug,
            name: $tenant->name,
            mode: 'hosted',
            isLocal: false,
        );

        return new HostedMcpAuthentication($token, $context);
    }

    private function bindTenantContext(TenantContext $context): void
    {
        app()->instance(TenantResolver::class, new readonly class($context) implements TenantResolver
        {
            public function __construct(
                private TenantContext $context,
            ) {}

            public function resolve(): TenantContext
            {
                return $this->context;
            }
        });
    }

    /**
     * @return array<int, string>
     */
    private function tokenScopes(ApiAdminMcpToken $token): array
    {
        return array_filter(
            $token->scopes,
            static fn (string $scope): bool => $scope !== '',
        )
            |> array_values(...);
    }

    /**
     * @return array{user_agent: string|null, accept: string|null}
     */
    private function clientMetadata(Request $request): array
    {
        return [
            'user_agent' => $request->userAgent(),
            'accept' => $request->header('Accept'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function result(mixed $id, mixed $result): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ];
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    private function toolResult(mixed $id, array $result): array
    {
        $success = (bool) ($result['success'] ?? false);

        return $this->result($id, [
            'content' => [[
                'type' => 'text',
                'text' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]],
            'isError' => ! $success,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function error(mixed $id, int $code, string $message): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ];
    }
}
