<?php

declare(strict_types=1);

namespace App\ApiAdmin\Http\Controllers;

use App\ApiAdmin\Instructor\InstructorApplyService;
use App\ApiAdmin\Instructor\InstructorDiscoveryService;
use App\ApiAdmin\Instructor\InstructorProposalBuilder;
use App\ApiAdmin\Instructor\InstructorSession;
use App\ApiAdmin\Instructor\InstructorSessionRepository;
use App\ApiAdmin\Tenancy\TenantBrowserAccess;
use App\ApiAdmin\Tenancy\TenantRoutes;
use App\MoonShine\ApiAdmin\Pages\InstructorPage;
use App\MoonShine\Http\Requests\InstructorConfirmRequest;
use App\MoonShine\Http\Requests\InstructorStartRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

final class InstructorController extends Controller
{
    public function __construct(
        private readonly InstructorSessionRepository $sessions,
        private readonly InstructorDiscoveryService $discovery,
        private readonly InstructorProposalBuilder $proposalBuilder,
        private readonly InstructorApplyService $applyService,
    ) {}

    public function show(Request $request): RedirectResponse
    {
        if (! $this->authenticated()) {
            return redirect($this->loginPath());
        }

        $session = $this->sessions->load($this->sessionId($request));

        Log::debug('InstructorController: legacy wizard GET redirected to MoonShine page', [
            'session_id' => $this->sessionId($request),
            'step' => $session?->step,
            'target_url' => $this->instructorPageUrl(),
        ]);

        return redirect($this->instructorPageUrl());
    }

    public function start(InstructorStartRequest $request): RedirectResponse
    {
        if (! $this->authenticated()) {
            return redirect($this->loginPath());
        }

        $validated = $request->validated();
        $session = $this->sessions->start($this->sessionId($request))->withInput($validated);

        Log::info('InstructorController: start submitted', [
            'session_id' => $session->id,
            'input_keys' => array_keys($validated),
        ]);

        $paths = $this->paths(is_string($validated['endpoint_paths'] ?? null) ? $validated['endpoint_paths'] : '');
        $auth = [
            'auth_type' => $validated['auth_type'] ?? 'none',
            'api_key' => $validated['api_key'] ?? '',
            'auth_header' => $validated['auth_header'] ?? 'Authorization',
        ];

        $baseUrl = is_string($validated['base_url'] ?? null) ? $validated['base_url'] : '';
        $discovery = $this->discovery->discover($baseUrl, $paths, $auth);
        $session = $session->withDiscovery($discovery['probes'], $discovery['warnings']);

        if ($discovery['success']) {
            $proposal = $this->proposalBuilder->build($discovery, $validated);
            $session = $session->withProposal($proposal['proposal'], $proposal['warnings']);
        }

        $this->sessions->save($session);

        return redirect()
            ->route('api-admin.instructor.show', TenantRoutes::routeParams())
            ->with($discovery['success'] ? 'status' : 'warning', $discovery['success'] ? 'Discovery complete. Review the proposal before generating files.' : 'Discovery could not build a proposal. Review warnings and try another endpoint.');
    }

    public function confirm(InstructorConfirmRequest $request): RedirectResponse
    {
        if (! $this->authenticated()) {
            return redirect($this->loginPath());
        }

        $session = $this->sessions->load($this->sessionId($request));

        if (! $session instanceof InstructorSession || $session->proposal === []) {
            Log::warning('InstructorController: confirm rejected without proposal', [
                'session_id' => $this->sessionId($request),
            ]);

            return redirect()
                ->route('api-admin.instructor.show', TenantRoutes::routeParams())
                ->with('warning', 'No active proposal to apply.');
        }

        $proposal = $session->proposal;
        $validated = $request->validated();

        if (isset($proposal['service']) && is_array($proposal['service'])) {
            foreach (['api_key', 'oauth_client_secret', 'oauth_password', 'oauth_refresh_token', 'session_password'] as $secretKey) {
                if (is_string($validated[$secretKey] ?? null) && $validated[$secretKey] !== '') {
                    $proposal['service'][$secretKey] = $validated[$secretKey];
                } elseif (($proposal['service'][$secretKey] ?? null) === '[redacted]') {
                    $proposal['service'][$secretKey] = '';
                }
            }
        }

        Log::info('InstructorController: confirm submitted', [
            'session_id' => $session->id,
            'proposal_keys' => array_keys($proposal),
        ]);

        $result = $this->applyService->apply($proposal);
        $session = $session->withApplyResult($result);
        $this->sessions->save($session);

        return redirect()
            ->route('api-admin.instructor.show', TenantRoutes::routeParams())
            ->with(($result['success'] ?? false) === true ? 'status' : 'warning', ($result['success'] ?? false) === true ? 'Service generated.' : 'Generation failed. Review details below.');
    }

    public function reset(Request $request): RedirectResponse
    {
        if (! $this->authenticated()) {
            return redirect($this->loginPath());
        }

        Log::info('InstructorController: reset requested', [
            'session_id' => $this->sessionId($request),
        ]);

        $this->sessions->reset($this->sessionId($request));

        return redirect()
            ->route('api-admin.instructor.show', TenantRoutes::routeParams())
            ->with('status', 'Instructor state reset.');
    }

    /**
     * @return list<string>
     */
    private function paths(string $raw): array
    {
        return array_map(
            trim(...),
            preg_split('/[\r\n,]+/', $raw) ?: [],
        )
            |> array_filter(...)
            |> array_values(...);
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

    private function authenticated(): bool
    {
        $user = Auth::guard('moonshine')->user();
        if ($user === null) {
            return false;
        }
        app(TenantBrowserAccess::class)->authorize($user);

        return true;
    }

    private function loginPath(): string
    {
        return '/'.TenantRoutes::expandPrefix(TenantRoutes::scopedPrefix('admin/login'));
    }

    private function instructorPageUrl(): string
    {
        return route('moonshine.admin.page', [
            ...TenantRoutes::routeParams(),
            'pageUri' => InstructorPage::URI,
        ]);
    }
}
