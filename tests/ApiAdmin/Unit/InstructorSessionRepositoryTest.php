<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Unit;

use App\ApiAdmin\Instructor\InstructorSession;
use App\ApiAdmin\Instructor\InstructorSessionRepository;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Orchestra\Testbench\TestCase;

final class InstructorSessionRepositoryTest extends TestCase
{
    public function test_saves_loads_and_resets_session_state_without_secrets(): void
    {
        $repository = new InstructorSessionRepository(new Repository(new ArrayStore));

        $session = $repository->start('browser-session')
            ->withInput([
                'base_url' => 'https://api.example.test',
                'api_key' => 'secret-token',
            ])
            ->withProposal([
                'service' => [
                    'name' => 'example',
                    'api_key' => 'secret-token',
                ],
            ]);

        $repository->save($session);

        $loaded = $repository->load('browser-session');

        $this->assertInstanceOf(InstructorSession::class, $loaded);
        $this->assertSame('review', $loaded->step);
        $this->assertSame('[redacted]', $loaded->input['api_key']);
        $service = $loaded->proposal['service'];
        $this->assertIsArray($service);
        $this->assertSame('[redacted]', $service['api_key']);

        $repository->reset('browser-session');

        $this->assertNull($repository->load('browser-session'));
    }
}
