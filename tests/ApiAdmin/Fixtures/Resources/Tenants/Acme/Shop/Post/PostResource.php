<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Fixtures\Resources\Tenants\Acme\Shop\Post;

use App\ApiAdmin\Resources\MultiPanelRestResource;
use App\ApiAdmin\ServiceConfig\ServiceConfig;
use Override;

final class PostResource extends MultiPanelRestResource
{
    #[Override]
    protected string $panel = 'admin';

    protected function resolveServiceConfig(): ServiceConfig
    {
        return ServiceConfig::fromArray([
            'name' => 'shop', 'tenant_id' => '1', 'panel' => 'admin',
            'base_url' => 'https://shop.example.test', 'auth_type' => 'none',
        ]);
    }

    protected function endpoint(): string
    {
        return '/posts';
    }
}
