<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Fixtures\Resources\Blog\Post;

use App\ApiAdmin\Providers\ApiAdminServiceProvider;
use App\ApiAdmin\Resources\MultiPanelRestResource;
use App\ApiAdmin\ServiceConfig\ServiceConfig;
use Override;

/**
 * Stable resource for data tool tests, so they do not depend on generated services.
 */
class PostResource extends MultiPanelRestResource
{
    #[Override]
    protected string $panel = 'blog-admin';

    #[Override]
    protected string $title = 'Posts';

    #[Override]
    protected string $sortColumn = 'id';

    protected function resolveServiceConfig(): ServiceConfig
    {
        return ApiAdminServiceProvider::resolveService('blog');
    }

    protected function endpoint(): string
    {
        return '/posts';
    }
}
