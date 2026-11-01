<?php

declare(strict_types=1);

namespace App\ApiAdmin\MCP\SpecStore;

/**
 * Cross-reference scanner / mutator for resource specs.
 *
 * `column` is the only stable identity in a spec — every place that
 * references a field by name (search, rules, filters, layout-tree
 * leaves, mutation routes, sibling depends_on, endpoint_query
 * placeholders) becomes a stale pointer when the field is removed or
 * renamed. This walker centralises the scan so remove_field and
 * rename_field share one rewrite matrix instead of drifting copies.
 *
 * Scanned locations:
 *   - search[]
 *   - rules (flat / per-mode top-level / per-column-per-mode)
 *   - filters[].column
 *   - mutations.create.body_from[] / body values / when_present
 *   - mutations.update[*].body_from[] / body / when_present
 *   - layout.form.groups[].columns[]
 *   - layout.form.tree recursive {type:'fields', columns:[...]}
 *   - sibling fields[].depends_on[]  (excludes the target field itself)
 *   - sibling fields[].endpoint_query values containing `{column}`
 */
final class SpecReferenceWalker
{
    /**
     * Find every location that references `$column`. Returns an empty
     * list when the spec is clean.
     *
     * Each entry: {location: string, detail?: string}.
     *
     * @param array<string, mixed> $spec
     *
     * @return list<array{location: string, detail?: string}>
     */
    public static function findReferences(array $spec, string $column): array
    {
        $hits = [];

        if (in_array($column, self::stringList($spec['search'] ?? null), true)) {
            $hits[] = ['location' => 'search'];
        }

        if (self::rulesReference($spec['rules'] ?? null, $column)) {
            $hits[] = ['location' => 'rules'];
        }

        $filters = is_array($spec['filters'] ?? null) ? $spec['filters'] : [];
        foreach ($filters as $i => $filter) {
            if (is_array($filter) && ($filter['column'] ?? null) === $column) {
                $hits[] = ['location' => "filters[{$i}]"];
            }
        }

        $mutations = $spec['mutations'] ?? null;
        if (is_array($mutations)) {
            $createHits = self::mutationReference($mutations['create'] ?? null, $column);
            foreach ($createHits as $detail) {
                $hits[] = ['location' => 'mutations.create', 'detail' => $detail];
            }
            $updateList = is_array($mutations['update'] ?? null) ? $mutations['update'] : [];
            foreach ($updateList as $i => $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                foreach (self::mutationReference($entry, $column) as $detail) {
                    $hits[] = ['location' => "mutations.update[{$i}]", 'detail' => $detail];
                }
            }
        }

        $layoutForm = self::layoutForm($spec);
        $groups = is_array($layoutForm['groups'] ?? null) ? $layoutForm['groups'] : [];
        foreach ($groups as $i => $group) {
            if (! is_array($group)) {
                continue;
            }
            if (in_array($column, self::stringList($group['columns'] ?? null), true)) {
                $hits[] = ['location' => "layout.form.groups[{$i}]"];
            }
        }

        if (is_array($layoutForm['tree'] ?? null) && self::treeReferences($layoutForm['tree'], $column)) {
            $hits[] = ['location' => 'layout.form.tree'];
        }

        foreach (['fields', 'index_fields', 'form_fields', 'detail_fields'] as $arrayKey) {
            $list = is_array($spec[$arrayKey] ?? null) ? $spec[$arrayKey] : [];
            foreach ($list as $i => $field) {
                if (! is_array($field)) {
                    continue;
                }
                if (($field['column'] ?? null) === $column) {
                    continue; // self-reference is the field itself
                }
                $detailColumn = is_string($field['column'] ?? null) ? $field['column'] : '';
                $deps = self::stringList($field['depends_on'] ?? null);
                if (in_array($column, $deps, true)) {
                    $hits[] = ['location' => "{$arrayKey}[{$i}].depends_on", 'detail' => $detailColumn];
                }
                $eq = $field['endpoint_query'] ?? null;
                if (is_array($eq) && self::endpointQueryReferences($eq, $column)) {
                    $hits[] = ['location' => "{$arrayKey}[{$i}].endpoint_query", 'detail' => $detailColumn];
                }
            }
        }

        return $hits;
    }

    /**
     * Strip every reference to `$column` (in addition to removing the
     * field entry itself). Used by remove_field's `force` path so the
     * regenerated PHP doesn't carry dangling pointers that would 500 at
     * runtime.
     *
     * @param array<string, mixed> $spec
     *
     * @return array<string, mixed>
     */
    public static function removeReferences(array $spec, string $column): array
    {
        $spec['search'] = self::removeFromStringList($spec['search'] ?? null, $column);
        if ($spec['search'] === []) {
            unset($spec['search']);
        }

        $spec['rules'] = self::rulesWithoutColumn($spec['rules'] ?? null, $column);
        if ($spec['rules'] === null) {
            unset($spec['rules']);
        }

        if (isset($spec['filters']) && is_array($spec['filters'])) {
            $spec['filters'] = array_filter(
                $spec['filters'],
                fn ($f): bool => ! is_array($f) || ($f['column'] ?? null) !== $column,
            )
                |> array_values(...);
            if ($spec['filters'] === []) {
                unset($spec['filters']);
            }
        }

        if (isset($spec['mutations']) && is_array($spec['mutations'])) {
            $spec['mutations'] = self::mutationsWithoutColumn($spec['mutations'], $column);
        }

        if (isset($spec['layout']) && is_array($spec['layout'])) {
            $form = is_array($spec['layout']['form'] ?? null) ? $spec['layout']['form'] : null;
            if ($form !== null) {
                if (isset($form['groups']) && is_array($form['groups'])) {
                    $form['groups'] = self::groupsWithoutColumn($form['groups'], $column);
                }
                if (isset($form['tree']) && is_array($form['tree'])) {
                    $form['tree'] = self::treeWithoutColumn($form['tree'], $column);
                }
                $spec['layout']['form'] = $form;
            }
        }

        foreach (['fields', 'index_fields', 'form_fields', 'detail_fields'] as $arrayKey) {
            if (! isset($spec[$arrayKey])) {
                continue;
            }
            if (! is_array($spec[$arrayKey])) {
                continue;
            }
            $cleaned = [];
            foreach ($spec[$arrayKey] as $field) {
                if (! is_array($field)) {
                    $cleaned[] = $field;

                    continue;
                }
                if (($field['column'] ?? null) === $column) {
                    continue; // drop the target field
                }
                if (isset($field['depends_on']) && is_array($field['depends_on'])) {
                    $field['depends_on'] = array_filter(
                        $field['depends_on'],
                        fn ($v): bool => $v !== $column,
                    )
                        |> array_values(...);
                    if ($field['depends_on'] === []) {
                        unset($field['depends_on']);
                    }
                }
                if (isset($field['endpoint_query']) && is_array($field['endpoint_query'])) {
                    $field['endpoint_query'] = self::endpointQueryWithoutColumn($field['endpoint_query'], $column);
                    if ($field['endpoint_query'] === []) {
                        unset($field['endpoint_query']);
                    }
                }
                $cleaned[] = $field;
            }
            $spec[$arrayKey] = $cleaned;
        }

        return $spec;
    }

    /**
     * Rewrite every reference to `$from` so it now points at `$to`. The
     * field entry itself is renamed in `fields[*].column`. All cross-
     * references — search/rules/filters/mutations/layout-tree/groups/
     * sibling depends_on/sibling endpoint_query placeholders — are
     * walked and rewritten in one pass. Returns the updated spec plus
     * a per-location counts map so callers can audit what changed.
     *
     * @param array<string, mixed> $spec
     *
     * @return array{spec: array<string, mixed>, counts: array<string, int>}
     */
    public static function renameReferences(array $spec, string $from, string $to, bool $rewriteMutations = true): array
    {
        $counts = [
            'fields' => 0,
            'search' => 0,
            'rules' => 0,
            'filters' => 0,
            'mutations' => 0,
            'layout_groups' => 0,
            'layout_tree' => 0,
            'depends_on' => 0,
            'endpoint_query' => 0,
        ];

        foreach (['fields', 'index_fields', 'form_fields', 'detail_fields'] as $arrayKey) {
            if (isset($spec[$arrayKey]) && is_array($spec[$arrayKey])) {
                $spec[$arrayKey] = self::fieldArrayRenamed($spec[$arrayKey], $from, $to, $counts);
            }
        }

        if (isset($spec['search']) && is_array($spec['search'])) {
            foreach ($spec['search'] as $i => $entry) {
                if ($entry === $from) {
                    $spec['search'][$i] = $to;
                    $counts['search']++;
                }
            }
        }

        if (isset($spec['rules']) && is_array($spec['rules'])) {
            $spec['rules'] = self::rulesRenamed($spec['rules'], $from, $to, $counts);
        }

        if (isset($spec['filters']) && is_array($spec['filters'])) {
            foreach ($spec['filters'] as $i => $filter) {
                if (is_array($filter) && ($filter['column'] ?? null) === $from) {
                    $filter['column'] = $to;
                    $spec['filters'][$i] = $filter;
                    $counts['filters']++;
                }
            }
        }

        if ($rewriteMutations && isset($spec['mutations']) && is_array($spec['mutations'])) {
            $spec['mutations'] = self::mutationsRenamed($spec['mutations'], $from, $to, $counts);
        }

        if (isset($spec['layout']) && is_array($spec['layout']) && isset($spec['layout']['form']) && is_array($spec['layout']['form'])) {
            $form = $spec['layout']['form'];
            if (isset($form['groups']) && is_array($form['groups'])) {
                foreach ($form['groups'] as $i => $group) {
                    if (! is_array($group)) {
                        continue;
                    }
                    if (! isset($group['columns'])) {
                        continue;
                    }
                    if (! is_array($group['columns'])) {
                        continue;
                    }
                    $rewroteGroup = false;
                    foreach ($group['columns'] as $j => $col) {
                        if ($col === $from) {
                            $group['columns'][$j] = $to;
                            $rewroteGroup = true;
                        }
                    }
                    if ($rewroteGroup) {
                        $form['groups'][$i] = $group;
                        $counts['layout_groups']++;
                    }
                }
            }
            if (isset($form['tree']) && is_array($form['tree'])) {
                $form['tree'] = self::treeRenamed($form['tree'], $from, $to, $counts);
            }
            $spec['layout']['form'] = $form;
        }

        return ['spec' => $spec, 'counts' => $counts];
    }

    /**
     * Rewrite `column` and sibling `depends_on`/`endpoint_query`
     * placeholders inside one field-array (canonical fields[] or any
     * per-page override). Mutates `$counts` in-place so the caller-
     * side aggregation sees totals across all 4 field-arrays.
     *
     * @param array<array-key, mixed> $list
     * @param array<string, int> $counts
     *
     * @return list<mixed>
     */
    private static function fieldArrayRenamed(array $list, string $from, string $to, array &$counts): array
    {
        $renamed = [];
        foreach ($list as $field) {
            if (! is_array($field)) {
                $renamed[] = $field;

                continue;
            }
            if (($field['column'] ?? null) === $from) {
                $field['column'] = $to;
                $counts['fields']++;
            }
            if (isset($field['depends_on']) && is_array($field['depends_on'])) {
                $rewroteDeps = false;
                foreach ($field['depends_on'] as $i => $dep) {
                    if ($dep === $from) {
                        $field['depends_on'][$i] = $to;
                        $rewroteDeps = true;
                    }
                }
                if ($rewroteDeps) {
                    $counts['depends_on']++;
                }
            }
            if (isset($field['endpoint_query']) && is_array($field['endpoint_query'])) {
                $rewroteEq = false;
                foreach ($field['endpoint_query'] as $key => $value) {
                    if (is_string($value) && self::placeholderInString($value, $from)) {
                        $field['endpoint_query'][$key] = str_replace('{'.$from.'}', '{'.$to.'}', $value);
                        $rewroteEq = true;
                    }
                }
                if ($rewroteEq) {
                    $counts['endpoint_query']++;
                }
            }
            $renamed[] = $field;
        }

        return $renamed;
    }

    /**
     * @param array<array-key, mixed> $rules
     * @param array<string, int> $counts
     *
     * @return array<array-key, mixed>
     */
    private static function rulesRenamed(array $rules, string $from, string $to, array &$counts): array
    {
        if (array_key_exists($from, $rules)) {
            $rules[$to] = $rules[$from];
            unset($rules[$from]);
            $counts['rules']++;
        }
        foreach (['create', 'update'] as $mode) {
            $branch = $rules[$mode] ?? null;
            if (is_array($branch) && array_key_exists($from, $branch)) {
                $branch[$to] = $branch[$from];
                unset($branch[$from]);
                $rules[$mode] = $branch;
                $counts['rules']++;
            }
        }

        return $rules;
    }

    /**
     * @param array<array-key, mixed> $mutations
     * @param array<string, int> $counts
     *
     * @return array<array-key, mixed>
     */
    private static function mutationsRenamed(array $mutations, string $from, string $to, array &$counts): array
    {
        if (isset($mutations['create']) && is_array($mutations['create'])) {
            $mutations['create'] = self::mutationEntryRenamed($mutations['create'], $from, $to, $counts);
        }
        if (isset($mutations['update']) && is_array($mutations['update'])) {
            $renamed = [];
            foreach ($mutations['update'] as $entry) {
                if (! is_array($entry)) {
                    $renamed[] = $entry;

                    continue;
                }
                $renamed[] = self::mutationEntryRenamed($entry, $from, $to, $counts);
            }
            $mutations['update'] = $renamed;
        }

        return $mutations;
    }

    /**
     * @param array<array-key, mixed> $mutation
     * @param array<string, int> $counts
     *
     * @return array<array-key, mixed>
     */
    private static function mutationEntryRenamed(array $mutation, string $from, string $to, array &$counts): array
    {
        $touched = false;

        if (isset($mutation['body_from']) && is_array($mutation['body_from'])) {
            foreach ($mutation['body_from'] as $i => $col) {
                if ($col === $from) {
                    $mutation['body_from'][$i] = $to;
                    $touched = true;
                }
            }
        }

        if (isset($mutation['when_present']) && $mutation['when_present'] === $from) {
            $mutation['when_present'] = $to;
            $touched = true;
        }

        if (isset($mutation['body']) && is_array($mutation['body'])) {
            if (array_key_exists($from, $mutation['body'])) {
                $mutation['body'][$to] = $mutation['body'][$from];
                unset($mutation['body'][$from]);
                $touched = true;
            }
            foreach ($mutation['body'] as $key => $value) {
                if (is_string($value) && self::placeholderInString($value, $from)) {
                    $mutation['body'][$key] = str_replace('{'.$from.'}', '{'.$to.'}', $value);
                    $touched = true;
                }
            }
        }

        if (isset($mutation['path']) && is_string($mutation['path']) && self::placeholderInString($mutation['path'], $from)) {
            $mutation['path'] = str_replace('{'.$from.'}', '{'.$to.'}', $mutation['path']);
            $touched = true;
        }

        if ($touched) {
            $counts['mutations']++;
        }

        return $mutation;
    }

    /**
     * @param array<array-key, mixed> $tree
     * @param array<string, int> $counts
     *
     * @return array<array-key, mixed>
     */
    private static function treeRenamed(array $tree, string $from, string $to, array &$counts): array
    {
        if (($tree['type'] ?? null) === 'fields' && isset($tree['columns']) && is_array($tree['columns'])) {
            $rewrote = false;
            foreach ($tree['columns'] as $i => $col) {
                if ($col === $from) {
                    $tree['columns'][$i] = $to;
                    $rewrote = true;
                }
            }
            if ($rewrote) {
                $counts['layout_tree']++;
            }

            return $tree;
        }

        if (isset($tree['children']) && is_array($tree['children'])) {
            $cleaned = [];
            foreach ($tree['children'] as $child) {
                $cleaned[] = is_array($child) ? self::treeRenamed($child, $from, $to, $counts) : $child;
            }
            $tree['children'] = $cleaned;
        }

        return $tree;
    }

    /**
     * @param array<string, mixed> $spec
     *
     * @return array<array-key, mixed>
     */
    private static function layoutForm(array $spec): array
    {
        $layout = $spec['layout'] ?? null;
        if (! is_array($layout)) {
            return [];
        }

        $form = $layout['form'] ?? null;

        return is_array($form) ? $form : [];
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $v) {
            if (is_string($v)) {
                $out[] = $v;
            }
        }

        return $out;
    }

    private static function rulesReference(mixed $rules, string $column): bool
    {
        if (! is_array($rules)) {
            return false;
        }

        if (array_key_exists($column, $rules)) {
            return true;
        }

        foreach (['create', 'update'] as $mode) {
            $branch = $rules[$mode] ?? null;
            if (is_array($branch) && array_key_exists($column, $branch)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function mutationReference(mixed $mutation, string $column): array
    {
        if (! is_array($mutation)) {
            return [];
        }

        $hits = [];

        if (in_array($column, self::stringList($mutation['body_from'] ?? null), true)) {
            $hits[] = 'body_from';
        }

        if (isset($mutation['when_present']) && $mutation['when_present'] === $column) {
            $hits[] = 'when_present';
        }

        if (isset($mutation['body']) && is_array($mutation['body'])) {
            if (array_key_exists($column, $mutation['body'])) {
                $hits[] = 'body[key]';
            }
            foreach ($mutation['body'] as $value) {
                if (is_string($value) && self::placeholderInString($value, $column)) {
                    $hits[] = 'body[placeholder]';
                    break;
                }
            }
        }

        if (isset($mutation['path']) && is_string($mutation['path']) && self::placeholderInString($mutation['path'], $column)) {
            $hits[] = 'path[placeholder]';
        }

        return $hits;
    }

    private static function placeholderInString(string $value, string $column): bool
    {
        return str_contains($value, '{'.$column.'}');
    }

    /**
     * @param array<array-key, mixed> $tree
     */
    private static function treeReferences(array $tree, string $column): bool
    {
        if (($tree['type'] ?? null) === 'fields') {
            return in_array($column, self::stringList($tree['columns'] ?? null), true);
        }

        $children = $tree['children'] ?? null;
        if (! is_array($children)) {
            return false;
        }

        return array_any($children, fn ($child): bool => is_array($child) && self::treeReferences($child, $column));
    }

    /**
     * @param array<array-key, mixed> $eq
     */
    private static function endpointQueryReferences(array $eq, string $column): bool
    {
        return array_any($eq, fn ($value): bool => is_string($value) && self::placeholderInString($value, $column));
    }

    /**
     * @return list<string>
     */
    private static function removeFromStringList(mixed $raw, string $column): array
    {
        return array_filter(self::stringList($raw), fn (string $v): bool => $v !== $column)
            |> array_values(...);
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private static function rulesWithoutColumn(mixed $rules, string $column): ?array
    {
        if (! is_array($rules)) {
            return null;
        }

        if (array_key_exists($column, $rules)) {
            unset($rules[$column]);
        }

        foreach (['create', 'update'] as $mode) {
            $branch = $rules[$mode] ?? null;
            if (is_array($branch) && array_key_exists($column, $branch)) {
                unset($branch[$column]);
                $rules[$mode] = $branch;
            }
        }

        return $rules === [] ? null : $rules;
    }

    /**
     * @param array<array-key, mixed> $mutations
     *
     * @return array<array-key, mixed>
     */
    private static function mutationsWithoutColumn(array $mutations, string $column): array
    {
        if (isset($mutations['create']) && is_array($mutations['create'])) {
            $mutations['create'] = self::mutationStripColumn($mutations['create'], $column);
        }

        if (isset($mutations['update']) && is_array($mutations['update'])) {
            $cleaned = [];
            foreach ($mutations['update'] as $entry) {
                if (! is_array($entry)) {
                    $cleaned[] = $entry;

                    continue;
                }
                $stripped = self::mutationStripColumn($entry, $column);
                if (isset($stripped['when_present']) && $stripped['when_present'] === $column) {
                    // mutation is gated on a column we just removed — drop entirely
                    continue;
                }
                $cleaned[] = $stripped;
            }
            $mutations['update'] = $cleaned;
        }

        return $mutations;
    }

    /**
     * @param array<array-key, mixed> $mutation
     *
     * @return array<array-key, mixed>
     */
    private static function mutationStripColumn(array $mutation, string $column): array
    {
        if (isset($mutation['body_from']) && is_array($mutation['body_from'])) {
            $mutation['body_from'] = array_filter(
                $mutation['body_from'],
                fn ($v): bool => $v !== $column,
            )
                |> array_values(...);
            if ($mutation['body_from'] === []) {
                unset($mutation['body_from']);
            }
        }

        if (isset($mutation['body']) && is_array($mutation['body'])) {
            unset($mutation['body'][$column]);
            foreach ($mutation['body'] as $key => $value) {
                if (is_string($value) && self::placeholderInString($value, $column)) {
                    unset($mutation['body'][$key]);
                }
            }
            if ($mutation['body'] === []) {
                unset($mutation['body']);
            }
        }

        return $mutation;
    }

    /**
     * @param array<int|string, mixed> $groups
     *
     * @return list<array<array-key, mixed>>
     */
    private static function groupsWithoutColumn(array $groups, string $column): array
    {
        $cleaned = [];
        foreach ($groups as $group) {
            if (! is_array($group)) {
                continue;
            }
            if (isset($group['columns']) && is_array($group['columns'])) {
                $group['columns'] = array_filter(
                    $group['columns'],
                    fn ($v): bool => $v !== $column,
                )
                    |> array_values(...);
            }
            $cleaned[] = $group;
        }

        return $cleaned;
    }

    /**
     * @param array<array-key, mixed> $tree
     *
     * @return array<array-key, mixed>
     */
    private static function treeWithoutColumn(array $tree, string $column): array
    {
        if (($tree['type'] ?? null) === 'fields' && isset($tree['columns']) && is_array($tree['columns'])) {
            $tree['columns'] = array_filter(
                $tree['columns'],
                fn ($v): bool => $v !== $column,
            )
                |> array_values(...);

            return $tree;
        }

        if (isset($tree['children']) && is_array($tree['children'])) {
            $cleaned = [];
            foreach ($tree['children'] as $child) {
                $cleaned[] = is_array($child) ? self::treeWithoutColumn($child, $column) : $child;
            }
            $tree['children'] = $cleaned;
        }

        return $tree;
    }

    /**
     * @param array<array-key, mixed> $eq
     *
     * @return array<array-key, mixed>
     */
    private static function endpointQueryWithoutColumn(array $eq, string $column): array
    {
        foreach ($eq as $key => $value) {
            if (is_string($value) && self::placeholderInString($value, $column)) {
                unset($eq[$key]);
            }
        }

        return $eq;
    }
}
