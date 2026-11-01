<?php

declare(strict_types=1);

namespace App\ApiAdmin\Fields;

use App\ApiAdmin\Providers\ApiAdminServiceProvider;
use App\ApiAdmin\Resources\AbstractRestResource;
use App\ApiAdmin\ServiceConfig\ServiceConfig;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Resolved HTTP target for a REST relation field.
 *
 * Same-service relations keep using the parent resource's HTTP client so any
 * resource-level client customization still applies. Cross-service relations
 * resolve the target ServiceConfig through the same registered-service binding
 * used by the rest of the package.
 */
final readonly class RestRelationTarget
{
    private function __construct(
        private AbstractRestResource $parentResource,
        private ServiceConfig $serviceConfig,
        private string $sourceService,
        private string $targetService,
        private string $dataWrapper,
        private bool $usesParentClient,
    ) {}

    public static function resolve(
        AbstractRestResource $parentResource,
        ?string $targetService = null,
        ?string $dataWrapper = null,
    ): ?self {
        try {
            $sourceConfig = $parentResource->getServiceConfig();
            $sourceService = $sourceConfig->name;
            $normalizedTarget = self::normalizeTargetService($targetService);
            $usesParentClient = $normalizedTarget === null || $normalizedTarget === $sourceService;

            $serviceConfig = $usesParentClient
                ? $sourceConfig
                : ApiAdminServiceProvider::resolveService($normalizedTarget);

            $wrapper = $dataWrapper ?? $serviceConfig->responseMapping->dataKey;

            Log::debug('RestRelationTarget::resolve — relation target resolved', [
                'source_service' => $sourceService,
                'target_service' => $serviceConfig->name,
                'data_wrapper' => $wrapper,
                'uses_parent_client' => $usesParentClient,
            ]);

            return new self(
                parentResource: $parentResource,
                serviceConfig: $serviceConfig,
                sourceService: $sourceService,
                targetService: $serviceConfig->name,
                dataWrapper: $wrapper,
                usesParentClient: $usesParentClient,
            );
        } catch (BindingResolutionException|RuntimeException $e) {
            Log::warning('RestRelationTarget::resolve — target service unavailable', [
                'source_resource' => $parentResource::class,
                'target_service' => $targetService,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return null;
        } catch (Throwable $e) {
            Log::error('RestRelationTarget::resolve — unexpected failure', [
                'source_resource' => $parentResource::class,
                'target_service' => $targetService,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function httpClient(): PendingRequest
    {
        return $this->usesParentClient
            ? $this->parentResource->httpClient()
            : $this->serviceConfig->httpClient();
    }

    public function serviceConfig(): ServiceConfig
    {
        return $this->serviceConfig;
    }

    public function sourceService(): string
    {
        return $this->sourceService;
    }

    public function targetService(): string
    {
        return $this->targetService;
    }

    public function dataWrapper(): string
    {
        return $this->dataWrapper;
    }

    public function usesParentClient(): bool
    {
        return $this->usesParentClient;
    }

    private static function normalizeTargetService(?string $targetService): ?string
    {
        if ($targetService === null) {
            return null;
        }

        $targetService = trim($targetService);

        return $targetService === '' ? null : $targetService;
    }
}
