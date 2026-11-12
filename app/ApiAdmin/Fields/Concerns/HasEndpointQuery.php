<?php

declare(strict_types=1);

namespace App\ApiAdmin\Fields\Concerns;

use App\ApiAdmin\Fields\RestRelationTarget;
use App\ApiAdmin\Http\PathSegment;
use App\ApiAdmin\Resources\AbstractRestResource;
use Illuminate\Support\Facades\Log;
use MoonShine\Contracts\Core\TypeCasts\DataWrapperContract;
use MoonShine\Contracts\UI\HasReactivityContract;

/**
 * Adds `->endpointQuery([...])` to a REST relation field.
 *
 * Query params ride every outgoing HTTP request the field makes —
 * options fetch for belongs_to / belongs_to_many, list fetch for
 * has_many, single-item fetch for has_one. Canonical use: a scoped
 * API that would 422 without `?for_entity=project` on the dropdown
 * endpoint. Field-local on purpose: the right scope can differ per
 * referring resource, so it's not inferred from the target resource's
 * own default_query.
 *
 * Values support `{column_name}` placeholders that resolve from the
 * current form context (same syntax as mutations.path / mutations.body).
 * At render time the trait walks endpointQuery and substitutes each
 * `{col}` with the parent resource's current item value or the request
 * value (request wins). Params whose placeholders resolve to null/empty
 * are DROPPED so we don't emit `?col=` — strict APIs treat that as an
 * equality filter on empty-string and return an empty list.
 *
 * This handles EDIT-mode cascades (options scoped to the saved parent).
 * CREATE-mode client-side cascade (pick a project → refetch statuses
 * scoped to that project) is handled by `dependsOn(['project_id'])`:
 * the field auto-marks itself `reactive()`, and on every sibling change
 * MoonShine's reactive endpoint re-renders the form — `resolveEndpointQueryContext()`
 * then picks up the fresh sibling value from `request()->all()` and
 * rebuilds the async-search URL with the new scope. User still needs to
 * mark the driver field (the project_id select) reactive itself for the
 * change-propagation wire to fire.
 */
trait HasEndpointQuery
{
    /** @var array<string, mixed> */
    protected array $endpointQuery = [];

    /** @var list<string> */
    protected array $dependsOnColumns = [];

    protected ?AbstractRestResource $parentResource = null;

    protected ?string $targetService = null;

    protected ?AbstractRestResource $relatedResource = null;

    /** @var class-string<AbstractRestResource>|null */
    protected ?string $relatedResourceClass = null;

    protected ?string $dataWrapper = null;

    /** @param class-string<AbstractRestResource> $resource */
    public function relatedResource(string $resource): static
    {
        $this->relatedResourceClass = $resource;
        $this->relatedResource = null;

        return $this;
    }

    public function getRelatedResource(): ?AbstractRestResource
    {
        if ($this->relatedResource === null && $this->relatedResourceClass !== null) {
            $instance = app($this->relatedResourceClass);
            $this->relatedResource = $instance instanceof AbstractRestResource ? $instance : null;
        }

        return $this->relatedResource;
    }

    public function getRelationTarget(): ?RestRelationTarget
    {
        return $this->resolveRelationTarget($this->dataWrapper);
    }

    public function getRelationPrimaryKey(): string
    {
        return $this->getRelationTarget()?->serviceConfig()->responseMapping->primaryKey ?? 'id';
    }

    public function targetService(?string $service): static
    {
        $service = $service === null ? null : trim($service);
        $this->targetService = $service === '' ? null : $service;

        return $this;
    }

    public function service(?string $service): static
    {
        return $this->targetService($service);
    }

    public function getTargetService(): ?string
    {
        return $this->targetService;
    }

    /**
     * @param array<string, mixed> $params
     */
    public function endpointQuery(array $params): static
    {
        $this->endpointQuery = $params;

        return $this;
    }

    /**
     * Declare that this relation field's options depend on sibling columns
     * in the same form. Classic CREATE-mode cascade: RestBelongsTo('Status')
     * scoped by project_id gets `dependsOn(['project_id'])` so picking a
     * project narrows the status dropdown.
     *
     * Implementation:
     *  - Auto-marks the field as `reactive()` so MoonShine re-renders it
     *    on every form change. The driver sibling (project_id field) must
     *    also be `->reactive()` or its change won't broadcast.
     *  - On re-render, `resolveEndpointQueryContext()` picks up fresh
     *    sibling values from `request()->all()` — the reactive endpoint
     *    posts the whole form, so the new project_id arrives in context
     *    and the async-search URL is rebuilt with `?endpoint_query[project_id]=<new>`.
     *
     * No client-side JS required: MoonShine's reactive mechanism owns the
     * TomSelect refresh because the entire field HTML (including the
     * `data-async-url` attribute) is swapped in place.
     *
     * @param list<string> $columns Sibling column names (e.g. ['project_id']).
     */
    public function dependsOn(array $columns): static
    {
        $this->dependsOnColumns = $columns;

        if ($this->dependsOnColumns !== [] && $this instanceof HasReactivityContract) {
            // Silent reactive — we don't need to re-broadcast our own value,
            // only consume sibling changes to rebuild options.
            $this->reactive(silentSelf: true);
        }

        return $this;
    }

    /**
     * Introspection accessor — exposed for tests and debugging.
     *
     * @return list<string>
     */
    public function getDependsOnColumns(): array
    {
        return $this->dependsOnColumns;
    }

    /**
     * Raw (unresolved) query spec — exposed for debugging / introspection.
     * Callers that send the query over the wire should use
     * {@see resolveEndpointQuery()} so placeholders are substituted.
     *
     * @return array<string, mixed>
     */
    public function getEndpointQuery(): array
    {
        return $this->endpointQuery;
    }

    /**
     * Substitute `{col}` placeholders in endpointQuery values against a
     * context map, dropping params whose placeholder resolved to null /
     * empty / missing. Non-placeholder values pass through verbatim.
     *
     * The context is optional: when omitted, the trait pulls it from the
     * parent resource's current item (so EDIT pages auto-scope dropdowns
     * to the already-saved parent) merged with `request()->all()` so POST
     * submissions and query-string overrides win.
     *
     * @param array<string, mixed>|null $context
     *
     * @return array<string, mixed>
     */
    public function resolveEndpointQuery(?array $context = null): array
    {
        if ($this->endpointQuery === []) {
            return [];
        }

        $context ??= $this->resolveEndpointQueryContext();

        $out = [];
        foreach ($this->endpointQuery as $key => $value) {
            if (is_string($value) && preg_match('/^\{([a-zA-Z_]\w*)\}$/', $value, $m) === 1) {
                $resolved = \data_get($context, $m[1]);
                if ($resolved === null) {
                    // Drop: sending `?col=` with empty value on a strict
                    // API returns zero rows rather than "unscoped", which
                    // looks like the dropdown is broken.
                    continue;
                }
                if ($resolved === '') {
                    // Drop: sending `?col=` with empty value on a strict
                    // API returns zero rows rather than "unscoped", which
                    // looks like the dropdown is broken.
                    continue;
                }
                if ($resolved === []) {
                    // Drop: sending `?col=` with empty value on a strict
                    // API returns zero rows rather than "unscoped", which
                    // looks like the dropdown is broken.
                    continue;
                }
                $out[$key] = \is_scalar($resolved) ? (string) $resolved : $resolved;
                continue;
            }

            $out[$key] = $value;
        }

        return $out;
    }

    /**
     * Build the default context for placeholder resolution.
     *
     * Order of precedence (earliest wins on the final merge):
     *  1. `request()->all()` — POSTed form state, query-string overrides.
     *  2. Parent resource's current casted item, if any — EDIT-mode
     *     scope anchored to the saved record.
     *
     * A field that needs a different context source (e.g. a controller
     * endpoint that constructs its own context from request query) should
     * call `resolveEndpointQuery($ownContext)` directly.
     *
     * @return array<string, mixed>
     */
    protected function resolveEndpointQueryContext(): array
    {
        /** @var array<string, mixed> $item */
        $item = [];
        // Table fields already carry their row; fetching the relation resource has no parent binding.
        $wrapper = $this->getData();
        if ($wrapper === null && $this->parentResource !== null) {
            $wrapper = $this->parentResource->getCastedData();
        }
        if ($wrapper !== null) {
            $data = $wrapper->getOriginal();
            if (\is_array($data)) {
                /** @var array<string, mixed> $item */
                $item = $data;
            } elseif (\is_object($data) && \method_exists($data, 'toArray')) {
                $toArray = $data->toArray();
                if (\is_array($toArray)) {
                    /** @var array<string, mixed> $item */
                    $item = $toArray;
                }
            }
        }

        /** @var array<string, mixed> $requestAll */
        $requestAll = \request()->all();

        return \array_merge($item, $requestAll);
    }

    protected function resolveRelationTarget(?string $dataWrapper = null): ?RestRelationTarget
    {
        if (! $this->parentResource instanceof AbstractRestResource) {
            return null;
        }

        return RestRelationTarget::resolve(
            parentResource: $this->parentResource,
            targetService: $this->targetService,
            dataWrapper: $dataWrapper,
            relatedResource: $this->getRelatedResource(),
        );
    }

    /**
     * Resolve `{placeholder}` tokens in relation endpoints from parent/request
     * context plus explicit values supplied by the caller.
     *
     * @param array<string, scalar|null> $explicitValues
     * @param array<string, mixed>|null $context
     */
    protected function resolveRelationEndpoint(
        string $endpoint,
        array $explicitValues = [],
        ?array $context = null,
    ): ?string {
        if ($endpoint === '') {
            return null;
        }

        $matchCount = preg_match_all('/\{([a-zA-Z_]\w*)\}/', $endpoint, $matches);
        if ($matchCount === false || $matchCount === 0) {
            return $endpoint;
        }

        $placeholders = $matches[1]
            |> array_unique(...)
            |> array_values(...);
        $context = array_merge($this->resolveEndpointQueryContext(), $context ?? []);
        $resolved = [];

        foreach ($placeholders as $placeholder) {
            $value = array_key_exists($placeholder, $explicitValues)
                ? $explicitValues[$placeholder]
                : data_get($context, $placeholder);

            if (in_array($value, [null, '', []], true)) {
                Log::warning('REST relation endpoint placeholder is missing', [
                    'endpoint' => $endpoint,
                    'placeholder' => $placeholder,
                ]);

                return null;
            }

            if (! is_scalar($value)) {
                Log::warning('REST relation endpoint placeholder is not scalar', [
                    'endpoint' => $endpoint,
                    'placeholder' => $placeholder,
                    'type' => get_debug_type($value),
                ]);

                return null;
            }

            $resolved[$placeholder] = (string) $value;
        }

        $resolvedEndpoint = $endpoint;
        foreach ($resolved as $placeholder => $value) {
            $resolvedEndpoint = str_replace('{'.$placeholder.'}', PathSegment::encode($value), $resolvedEndpoint);
        }

        Log::debug('REST relation endpoint placeholders resolved', [
            'endpoint' => $endpoint,
            'resolved_endpoint' => $resolvedEndpoint,
            'placeholders' => $placeholders,
        ]);

        return $resolvedEndpoint;
    }

    /**
     * @return array<string, mixed>
     */
    protected function relationEndpointContextFromItem(?DataWrapperContract $item): array
    {
        if (! $item instanceof DataWrapperContract) {
            return [];
        }

        $original = $item->getOriginal();
        if (is_array($original)) {
            /** @var array<string, mixed> $original */
            return $original;
        }

        if (is_object($original) && method_exists($original, 'toArray')) {
            $array = $original->toArray();
            if (is_array($array)) {
                /** @var array<string, mixed> $array */
                return $array;
            }
        }

        return [];
    }
}
