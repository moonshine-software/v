<?php

declare(strict_types=1);

namespace App\MoonShine\ApiAdmin\Pages;

use App\ApiAdmin\Instructor\InstructorSessionRepository;
use App\ApiAdmin\Tenancy\TenantBrowserAccess;
use App\ApiAdmin\Tenancy\TenantRoutes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use MoonShine\Contracts\UI\ComponentContract;
use MoonShine\Laravel\Pages\Page;
use MoonShine\MenuManager\Attributes\SkipMenu;
use MoonShine\UI\Components\FlexibleRender;
use Override;

#[SkipMenu]
final class InstructorPage extends Page
{
    public const string URI = 'instructor-page';

    #[Override]
    public function getUriKey(): string
    {
        return self::URI;
    }

    #[Override]
    public function getTitle(): string
    {
        return $this->title ?: 'Instructor Agent';
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    public function getBreadcrumbs(): array
    {
        return [
            $this->adminUrl() => 'Services',
            '#' => $this->getTitle(),
        ];
    }

    /**
     * @return list<ComponentContract>
     */
    protected function components(): iterable
    {
        if (config('api-admin.hosted.mode', 'local') === 'hosted') {
            app(TenantBrowserAccess::class)->authorize(Auth::guard('moonshine')->user());
        }
        $request = request();
        $sessionId = $this->sessionId($request);
        $session = app(InstructorSessionRepository::class)->load($sessionId);

        Log::info('InstructorPage: wizard opened', [
            'session_id' => $sessionId,
            'step' => $session?->step,
        ]);

        return [
            FlexibleRender::make(
                view('api-admin::instructor.page', [
                    'session' => $session,
                    'summary' => $session?->publicSummary(),
                    'startUrl' => route('api-admin.instructor.start', TenantRoutes::routeParams()),
                    'confirmUrl' => route('api-admin.instructor.confirm', TenantRoutes::routeParams()),
                    'resetUrl' => route('api-admin.instructor.reset', TenantRoutes::routeParams()),
                ])
            ),
        ];
    }

    private function sessionId(Request $request): string
    {
        $userId = Auth::guard('moonshine')->id();
        if (is_int($userId) || is_string($userId)) {
            return 'moonshine-user-'.$userId;
        }

        if ($request->hasSession()) {
            return $request->session()->getId();
        }

        return 'stateless';
    }

    private function adminUrl(): string
    {
        return '/'.TenantRoutes::expandPrefix(TenantRoutes::scopedPrefix('admin'));
    }
}
