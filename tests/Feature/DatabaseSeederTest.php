<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_seeding_never_creates_demo_users_or_administrators(): void
    {
        $this->seed();
        $this->seed();

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('moonshine_users', 0);
    }
}
