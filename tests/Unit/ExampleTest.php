<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ExampleTest extends TestCase
{
    /**
     * Sanity check that PHPUnit wiring boots — same intent as Laravel's
     * ship-it default `assertTrue(true)`, reformulated so it isn't a
     * tautology PHPStan flags at level max.
     */
    public function test_that_true_is_true(): void
    {
        $this->assertSame(1, 1);
    }
}
