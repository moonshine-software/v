<?php

declare(strict_types=1);

namespace App\ApiAdmin\MCP\Tools;

use App\ApiAdmin\Instructor\InstructorSession;
use App\ApiAdmin\Instructor\InstructorSessionRepository;
use Illuminate\Support\Facades\Log;

final class InstructorStatusTool implements ToolContract
{
    /**
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return [
            'name' => 'instructor_status',
            'description' => 'Read the built-in instructor onboarding session status and sanitized proposal summary. Read-only; does not register services or generate resources.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'session_id' => [
                        'type' => 'string',
                        'description' => 'Optional browser session id. Omit to inspect the current request session when available.',
                    ],
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public function execute(array $params): array
    {
        $sessionId = is_string($params['session_id'] ?? null) ? $params['session_id'] : $this->currentSessionId();

        Log::info('InstructorStatusTool: executing', [
            'session_id_set' => $sessionId !== null,
        ]);

        if ($sessionId === null) {
            Log::warning('InstructorStatusTool: no active session id');

            return [
                'success' => true,
                'active' => false,
                'hint' => 'Open /api-admin/instructor in the admin UI first, or pass a browser session_id.',
            ];
        }

        $session = (new InstructorSessionRepository)->load($sessionId);

        if (! $session instanceof InstructorSession) {
            Log::warning('InstructorStatusTool: no active instructor session', [
                'session_id' => $sessionId,
            ]);

            return [
                'success' => true,
                'active' => false,
                'session_id' => $sessionId,
            ];
        }

        $summary = $session->publicSummary();

        Log::debug('InstructorStatusTool: active session found', [
            'session_id' => $sessionId,
            'summary_keys' => array_keys($summary),
        ]);

        return [
            'success' => true,
            'active' => true,
            'session' => $summary,
        ];
    }

    private function currentSessionId(): ?string
    {
        if (request()->hasSession()) {
            return request()->session()->getId();
        }

        return null;
    }
}
