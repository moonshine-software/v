<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Unit\Auth;

use App\ApiAdmin\Auth\SessionState;
use Orchestra\Testbench\TestCase;

class SessionStateTest extends TestCase
{
    public function test_to_array_emits_serialization_friendly_shape(): void
    {
        $state = new SessionState(
            cookies: ['sanctum_session' => 'abc', 'XSRF-TOKEN' => 'xyz'],
            csrfToken: 'xyz',
            expiresAt: 1_777_000_000,
        );

        $this->assertSame([
            'cookies' => ['sanctum_session' => 'abc', 'XSRF-TOKEN' => 'xyz'],
            'csrfToken' => 'xyz',
            'expiresAt' => 1_777_000_000,
        ], $state->toArray());
    }

    public function test_from_array_round_trips_via_to_array(): void
    {
        // Round-trip is what the cache layer relies on after the
        // serialize/unserialize cycle of DatabaseStore.
        $original = new SessionState(
            cookies: ['s' => 'v', 'XSRF-TOKEN' => 't'],
            csrfToken: 't',
            expiresAt: 1_777_000_000,
        );

        $rebuilt = SessionState::fromArray($original->toArray());

        $this->assertNotNull($rebuilt);
        $this->assertSame($original->cookies, $rebuilt->cookies);
        $this->assertSame($original->csrfToken, $rebuilt->csrfToken);
        $this->assertSame($original->expiresAt, $rebuilt->expiresAt);
    }

    public function test_from_array_returns_null_on_invalid_shapes(): void
    {
        // Cache poisoning, schema drift, leftover serialized objects from a
        // pre-fix deploy — anything that doesn't match the array shape must
        // resolve to null so the caller falls through to a fresh login
        // rather than blowing up on a typed property assignment.
        $invalidShapes = [
            'null' => null,
            'object_resurfaced_as_incomplete_class' => (object) ['cookies' => [], 'csrfToken' => '', 'expiresAt' => 0],
            'string' => 'not-an-array',
            'missing_csrf_token' => ['cookies' => [], 'expiresAt' => 0],
            'wrong_csrf_type' => ['cookies' => [], 'csrfToken' => 123, 'expiresAt' => 0],
            'wrong_expires_type' => ['cookies' => [], 'csrfToken' => '', 'expiresAt' => 'soon'],
            'cookies_non_string_value' => ['cookies' => ['s' => 99], 'csrfToken' => '', 'expiresAt' => 0],
            'cookies_non_string_key' => ['cookies' => [0 => 'v'], 'csrfToken' => '', 'expiresAt' => 0],
        ];

        foreach ($invalidShapes as $label => $payload) {
            $this->assertNull(SessionState::fromArray($payload), "shape '{$label}' must resolve to null");
        }
    }

    public function test_is_expired_consistent_after_round_trip(): void
    {
        $state = new SessionState(cookies: [], csrfToken: '', expiresAt: time() - 1);

        $this->assertTrue($state->isExpired());

        $rebuilt = SessionState::fromArray($state->toArray());
        $this->assertNotNull($rebuilt);
        $this->assertTrue($rebuilt->isExpired());
    }
}
