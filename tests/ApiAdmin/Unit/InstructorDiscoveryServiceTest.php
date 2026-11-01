<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Unit;

use App\ApiAdmin\Instructor\InstructorDiscoveryService;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;

final class InstructorDiscoveryServiceTest extends TestCase
{
    public function test_discovers_laravel_mapping_and_columns_from_explicit_endpoint(): void
    {
        Http::fake([
            'https://api.example.test/users' => Http::response([
                'data' => [
                    ['id' => 1, 'email' => 'a@example.test', 'name' => 'Alice'],
                ],
                'total' => 1,
                'per_page' => 15,
                'current_page' => 1,
                'last_page' => 1,
            ]),
        ]);

        $result = (new InstructorDiscoveryService)->discover('https://api.example.test', ['/users']);

        $this->assertTrue($result['success']);
        $this->assertSame('laravel', $result['probes'][0]['guessed_format']);
        $this->assertSame(['id', 'email', 'name'], $result['probes'][0]['detected_columns']);
        $mapping = $result['probes'][0]['guessed_mapping'];
        $this->assertIsArray($mapping);
        $this->assertSame('id', $mapping['primary_key']);
    }

    public function test_rejects_invalid_base_url_without_http_calls(): void
    {
        Http::fake();

        $result = (new InstructorDiscoveryService)->discover('not-a-url', ['/users']);

        $this->assertFalse($result['success']);
        $this->assertSame([], $result['probes']);
        Http::assertNothingSent();
    }
}
