<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Unit;

use App\ApiAdmin\Fields\Concerns\HasEndpointQuery;
use PHPUnit\Framework\TestCase;

/**
 * Marker interface for the anonymous trait-exercising host class so
 * PHPStan sees the public `resolveEndpointQuery()` method the trait
 * provides without having to name the anonymous class.
 */
interface HasEndpointQueryHarness
{
    /**
     * @param array<string, mixed>|null $context
     *
     * @return array<string, mixed>
     */
    public function resolveEndpointQuery(?array $context = null): array;
}

/**
 * Trait-level tests for HasEndpointQuery.
 *
 * Focus on the `{col}` placeholder resolver — that's the MCP surface
 * agents rely on (Part 1 cascade). Uses an anonymous host class so the
 * tests don't drag in the MoonShine field machinery — the trait only
 * needs `$this->endpointQuery` and a resolveEndpointQueryContext() hook.
 */
class HasEndpointQueryTest extends TestCase
{
    /**
     * @param array<string, mixed> $endpointQuery
     * @param array<string, mixed> $context
     */
    private function host(array $endpointQuery, array $context = []): HasEndpointQueryHarness
    {
        return new class($endpointQuery, $context) implements HasEndpointQueryHarness
        {
            use HasEndpointQuery;

            /**
             * @param array<string, mixed> $endpointQuery
             * @param array<string, mixed> $context
             */
            public function __construct(array $endpointQuery, private array $context)
            {
                $this->endpointQuery = $endpointQuery;
            }

            /**
             * @return array<string, mixed>
             */
            protected function resolveEndpointQueryContext(): array
            {
                return $this->context;
            }
        };
    }

    public function test_empty_query_returns_empty_array(): void
    {
        $field = $this->host([]);

        $this->assertSame([], $field->resolveEndpointQuery(['project_id' => 42]));
    }

    public function test_scalar_non_placeholder_values_pass_through_unchanged(): void
    {
        // `for_entity: "project"` is the canonical static-scope use case:
        // a string literal that must ride every request regardless of
        // whether there's anything to substitute against.
        $field = $this->host([
            'for_entity' => 'project',
            'limit' => 25,
        ]);

        $out = $field->resolveEndpointQuery([]);

        $this->assertSame([
            'for_entity' => 'project',
            'limit' => 25,
        ], $out);
    }

    public function test_placeholder_substitutes_from_context(): void
    {
        // Canonical cascade shape: `{"project_id": "{project_id}"}` — the
        // outer brace is a value, the inner is our placeholder marker.
        $field = $this->host(
            ['project_id' => '{project_id}'],
        );

        $out = $field->resolveEndpointQuery(['project_id' => 42]);

        // Substituted values come back as strings so query-string encoding
        // is uniform regardless of whether the upstream expects int or UUID.
        $this->assertSame(['project_id' => '42'], $out);
    }

    public function test_placeholder_resolving_to_null_is_dropped(): void
    {
        // A missing key (e.g. CREATE form with no saved project yet) must
        // DROP the param entirely — NOT emit `?project_id=` which strict
        // APIs treat as "match empty string" and return 0 rows, making it
        // look like the dropdown is broken.
        $field = $this->host(
            ['project_id' => '{project_id}'],
        );

        $out = $field->resolveEndpointQuery([]);

        $this->assertSame([], $out);
    }

    public function test_placeholder_resolving_to_empty_string_is_dropped(): void
    {
        $field = $this->host(
            ['project_id' => '{project_id}'],
        );

        $out = $field->resolveEndpointQuery(['project_id' => '']);

        $this->assertSame([], $out);
    }

    public function test_placeholder_resolving_to_empty_array_is_dropped(): void
    {
        $field = $this->host(
            ['tags' => '{tags}'],
        );

        $out = $field->resolveEndpointQuery(['tags' => []]);

        $this->assertSame([], $out);
    }

    public function test_mixed_query_preserves_static_and_drops_empty_placeholder(): void
    {
        // The common real-world mix: one static scope param + one dynamic
        // that might be absent. The static one must survive alone when the
        // dynamic drops out (e.g. TomSelect typing on a scoped status field
        // before any parent was picked).
        $field = $this->host(
            [
                'for_entity' => 'project',
                'project_id' => '{project_id}',
            ],
        );

        $out = $field->resolveEndpointQuery([]);

        $this->assertSame(['for_entity' => 'project'], $out);
    }

    public function test_malformed_placeholder_is_not_substituted(): void
    {
        // Only the exact `^\{name\}$` shape substitutes. Anything else — a
        // trailing character, a dot-path, a prefix — passes through as
        // literal, so it's safe to compose values that happen to contain
        // braces (e.g. a filter DSL like `{$or:...}`).
        $field = $this->host(
            [
                'near' => '{x}rest',          // trailing characters
                'path' => 'prefix-{x}',       // prefix
                'deep' => '{a.b}',            // dots not allowed in marker
            ],
        );

        $out = $field->resolveEndpointQuery(['x' => 'anything', 'a' => ['b' => 'y']]);

        $this->assertSame([
            'near' => '{x}rest',
            'path' => 'prefix-{x}',
            'deep' => '{a.b}',
        ], $out);
    }

    public function test_supplied_context_wins_over_default(): void
    {
        // resolveEndpointQuery($context) argument should override the
        // default context lookup path. Useful for controllers that
        // construct a synthetic context from request query params rather
        // than a filled form item (RestAsyncSearchController does this).
        $field = $this->host(
            ['project_id' => '{project_id}'],
            context: ['project_id' => 'from-default'],
        );

        $explicit = $field->resolveEndpointQuery(['project_id' => 'from-explicit']);

        $this->assertSame(['project_id' => 'from-explicit'], $explicit);

        // Without an explicit arg the default context kicks in.
        $default = $field->resolveEndpointQuery();
        $this->assertSame(['project_id' => 'from-default'], $default);
    }
}
