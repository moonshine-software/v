<?php

declare(strict_types=1);

namespace App\ApiAdmin\Instructor;

use App\ApiAdmin\MCP\GenerationTransaction;
use App\ApiAdmin\MCP\McpServer;
use App\ApiAdmin\MCP\ServiceRegistry;
use App\ApiAdmin\Tenancy\TenantRoutes;
use Illuminate\Support\Facades\Log;
use Throwable;

final readonly class InstructorApplyService
{
    /**
     * @param array<string, mixed> $proposal
     *
     * @return array<string, mixed>
     */
    public function apply(array $proposal): array
    {
        return GenerationTransaction::run(fn (): array => $this->applyProposal($proposal));
    }

    /**
     * @param array<string, mixed> $proposal
     *
     * @return array<string, mixed>
     */
    private function applyProposal(array $proposal): array
    {
        $servicePayload = is_array($proposal['service'] ?? null) ? $this->stringMap($proposal['service']) : [];
        $resources = is_array($proposal['resources'] ?? null) ? array_values(array_filter($proposal['resources'], is_array(...))) : [];
        $serviceName = is_string($servicePayload['name'] ?? null) ? $servicePayload['name'] : '';

        $baseUrl = is_string($servicePayload['base_url'] ?? null) ? $servicePayload['base_url'] : '';

        if ($serviceName === '' || $baseUrl === '' || $resources === []) {
            Log::warning('InstructorApplyService: invalid proposal', [
                'service_present' => $servicePayload !== [],
                'resources_count' => count($resources),
            ]);

            return [
                'success' => false,
                'code' => 'VALIDATION',
                'error' => 'Proposal must contain one service and at least one resource.',
            ];
        }

        if ($this->serviceExists($serviceName)) {
            Log::warning('InstructorApplyService: service collision', ['service' => $serviceName]);

            return [
                'success' => false,
                'code' => 'SERVICE_EXISTS',
                'error' => "Service '{$serviceName}' already exists. Reset the instructor session or choose another service name.",
            ];
        }

        $files = [];

        try {
            Log::info('InstructorApplyService: applying register_service', ['service' => $serviceName]);
            $server = new McpServer;
            $serviceResult = $server->executeTool('register_service', $this->registerPayload($servicePayload, $serviceName, $baseUrl));

            if (($serviceResult['success'] ?? false) !== true) {
                return [
                    'success' => false,
                    'code' => $serviceResult['code'] ?? 'REGISTER_SERVICE_FAILED',
                    'error' => $serviceResult['error'] ?? 'Service registration failed.',
                    'service_result' => InstructorSession::redact($serviceResult),
                    'files' => $files,
                ];
            }

            $files = array_merge($files, $this->stringList($serviceResult['files'] ?? []));

            $resourceResults = [];
            foreach ($resources as $resource) {
                $resource = $this->stringMap($resource);
                $resource['service'] = $serviceName;
                Log::info('InstructorApplyService: applying create_resource', [
                    'service' => $serviceName,
                    'resource' => $resource['name'] ?? null,
                    'endpoint' => $resource['endpoint'] ?? null,
                ]);

                $result = $server->executeTool('create_resource', $resource);
                $resourceResults[] = InstructorSession::redact($result);

                foreach (['file', 'pages'] as $key) {
                    if ($key === 'file' && is_string($result[$key] ?? null)) {
                        $files[] = $result[$key];
                    }
                    if ($key === 'pages' && is_array($result[$key] ?? null)) {
                        $files = array_merge($files, $this->stringList($result[$key]));
                    }
                }

                if (($result['success'] ?? false) !== true) {
                    Log::warning('InstructorApplyService: resource generation failed', [
                        'service' => $serviceName,
                        'resource' => $resource['name'] ?? null,
                    ]);

                    return [
                        'success' => false,
                        'code' => 'CREATE_RESOURCE_FAILED',
                        'error' => 'Resource generation failed. All changes were rolled back; review validation details and retry.',
                        'resource_results' => $resourceResults,
                        'files' => array_values(array_unique($files)),
                    ];
                }
            }

            Log::info('InstructorApplyService: proposal applied', [
                'service' => $serviceName,
                'resources_count' => count($resourceResults),
                'files_count' => count($files),
            ]);
            Log::debug('InstructorApplyService: generated files', ['files' => $files]);

            $panel = is_string($serviceResult['panel'] ?? null) ? $serviceResult['panel'] : $serviceName.'-admin';

            return [
                'success' => true,
                'service' => $serviceName,
                'panel' => $panel,
                'files' => array_values(array_unique($files)),
                'resource_results' => $resourceResults,
                'next_url' => '/'.TenantRoutes::expandPrefix(TenantRoutes::scopedPrefix($panel)),
            ];
        } catch (Throwable $e) {
            Log::error('InstructorApplyService: apply failed', [
                'service' => $serviceName,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'code' => 'APPLY_FAILED',
                'error' => $e->getMessage(),
                'files' => array_values(array_unique($files)),
            ];
        }
    }

    private function serviceExists(string $serviceName): bool
    {
        return array_any((new ServiceRegistry)->listServices(), fn (array $service): bool => ($service['name'] ?? null) === $serviceName);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{name: string, base_url: string, api_key?: string, panel?: string, title?: string, auth_type?: string, auth_header?: string, admin_login?: bool, health_path?: string, response_format?: string, response_mapping?: array<string, mixed>}
     */
    private function registerPayload(array $payload, string $serviceName, string $baseUrl): array
    {
        $out = [
            'name' => $serviceName,
            'base_url' => $baseUrl,
        ];

        foreach (['api_key', 'panel', 'title', 'auth_type', 'auth_header', 'health_path', 'response_format'] as $key) {
            if (is_string($payload[$key] ?? null)) {
                $out[$key] = $payload[$key];
            }
        }

        if (is_bool($payload['admin_login'] ?? null)) {
            $out['admin_login'] = $payload['admin_login'];
        }

        if (is_array($payload['response_mapping'] ?? null)) {
            $out['response_mapping'] = $this->stringMap($payload['response_mapping']);
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_filter($value, is_string(...))
            |> array_values(...);
    }

    /**
     * @param array<mixed, mixed> $value
     *
     * @return array<string, mixed>
     */
    private function stringMap(array $value): array
    {
        $out = [];

        foreach ($value as $key => $item) {
            $out[is_string($key) ? $key : (string) $key] = is_array($item) ? $this->stringMap($item) : $item;
        }

        return $out;
    }
}
