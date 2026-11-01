<?php

declare(strict_types=1);

namespace App\ApiAdmin\Instructor;

use App\ApiAdmin\Tenancy\TenantContext;
use App\ApiAdmin\Tenancy\TenantKeyBuilder;
use App\ApiAdmin\Tenancy\TenantResolver;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final readonly class InstructorSessionRepository
{
    public function __construct(
        private ?CacheRepository $cache = null,
    ) {}

    public function start(?string $sessionId = null): InstructorSession
    {
        $id = $this->normalizeId($sessionId);
        $session = new InstructorSession(id: $id);

        $this->save($session);

        Log::info('InstructorSessionRepository: session started', [
            'session_id' => $id,
            'step' => $session->step,
        ]);

        return $session;
    }

    public function load(?string $sessionId = null): ?InstructorSession
    {
        $id = $this->normalizeId($sessionId);

        try {
            $state = $this->store()->get($this->key($id));
        } catch (Throwable $e) {
            Log::warning('InstructorSessionRepository: failed to load state', [
                'session_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if ($state === null) {
            Log::debug('InstructorSessionRepository: no active state', ['session_id' => $id]);

            return null;
        }

        if (! is_array($state)) {
            Log::warning('InstructorSessionRepository: malformed state discarded', ['session_id' => $id]);
            $this->reset($id);

            return null;
        }

        Log::debug('InstructorSessionRepository: state loaded', [
            'session_id' => $id,
            'step' => $state['step'] ?? null,
        ]);

        return InstructorSession::fromArray($id, $state);
    }

    public function save(InstructorSession $session): void
    {
        $ttl = $this->ttlSeconds();
        $this->store()->put($this->key($session->id), $session->toArray(), $ttl);

        Log::debug('InstructorSessionRepository: state saved', [
            'session_id' => $session->id,
            'step' => $session->step,
            'ttl' => $ttl,
        ]);
    }

    public function reset(?string $sessionId = null): void
    {
        $id = $this->normalizeId($sessionId);
        $this->store()->forget($this->key($id));

        Log::debug('InstructorSessionRepository: state reset', ['session_id' => $id]);
    }

    private function normalizeId(?string $sessionId): string
    {
        return $sessionId !== null && $sessionId !== '' ? $sessionId : (string) Str::uuid();
    }

    private function key(string $sessionId): string
    {
        if (config('api-admin.hosted.mode', 'local') !== 'hosted') {
            return 'api-admin:instructor:'.$sessionId;
        }

        $resolver = app()->get(TenantResolver::class);
        $tenant = $resolver->resolve();
        if (! $tenant instanceof TenantContext || $tenant->isLocal) {
            throw new RuntimeException('Missing tenant context for instructor session.');
        }

        return TenantKeyBuilder::cacheKey($tenant->id, 'instructor', hash('sha256', $sessionId));
    }

    private function store(): CacheRepository
    {
        return $this->cache ?? Cache::store();
    }

    private function ttlSeconds(): int
    {
        $configured = config('api-admin.instructor.session_ttl', 3600);

        return is_int($configured) && $configured > 0 ? $configured : 3600;
    }
}
