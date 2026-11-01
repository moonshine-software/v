<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Fixtures\Resources\Blog\Comment;

use App\ApiAdmin\Providers\ApiAdminServiceProvider;
use App\ApiAdmin\Resources\AbstractRestResource;
use App\ApiAdmin\ServiceConfig\ServiceConfig;
use Override;

/**
 * Stable relation resource for data tool tests, so they do not depend on generated services.
 */
class CommentResource extends AbstractRestResource
{
    #[Override]
    protected string $title = 'Comment';

    protected function resolveServiceConfig(): ServiceConfig
    {
        return ApiAdminServiceProvider::resolveService('blog');
    }

    protected function endpoint(): string
    {
        return '/posts/{parent_id}/comments';
    }
}
