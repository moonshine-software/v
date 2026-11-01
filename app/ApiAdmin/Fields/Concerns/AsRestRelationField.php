<?php

declare(strict_types=1);

namespace App\ApiAdmin\Fields\Concerns;

/**
 * Minimal RelationFieldContract implementation for REST-backed relation fields.
 *
 * MoonShine's Fields::onlyOutside() filter requires fields to implement
 * RelationFieldContract. Since our REST fields are not Eloquent-based,
 * most relation methods return stubs.
 */
trait AsRestRelationField
{
    use HasEndpointQuery;

    protected bool $outsideComponent = true;

    public function getRelationName(): string
    {
        return (string) $this->getColumn();
    }

    public function getResourceColumn(): string
    {
        return (string) $this->getColumn();
    }

    public function getRelated(): mixed
    {
        return null;
    }

    /**
     * @param array<string, mixed> $attributes
     * @param array<string, mixed> $relations
     */
    public function makeRelated(
        int|string|null $key = null,
        array $attributes = [],
        array $relations = [],
        mixed $related = null,
    ): mixed {
        return null;
    }

    public function excludeInstancing(): void
    {
        // no-op for REST fields
    }

    public function isOutsideComponent(): bool
    {
        return $this->outsideComponent;
    }

    public function isMorph(): bool
    {
        return false;
    }

    public function disableOutside(): static
    {
        $this->outsideComponent = false;

        return $this;
    }
}
