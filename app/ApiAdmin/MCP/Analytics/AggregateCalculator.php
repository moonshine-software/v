<?php

declare(strict_types=1);

namespace App\ApiAdmin\MCP\Analytics;

use Illuminate\Support\Facades\Log;

/**
 * Calculates simple analytics over serialized resource item arrays.
 */
final class AggregateCalculator
{
    /**
     * @param list<array<string, mixed>> $items
     *
     * @return array<string, mixed>
     */
    public function calculate(array $items, string $operation, ?string $field = null, ?string $groupBy = null): array
    {
        $operation = strtolower($operation);

        Log::debug('AggregateCalculator::calculate', [
            'operation' => $operation,
            'field' => $field,
            'group_by' => $groupBy,
            'item_count' => count($items),
        ]);

        if (! in_array($operation, ['count', 'sum', 'avg', 'min', 'max'], true)) {
            Log::warning('AggregateCalculator::calculate — invalid operation', ['operation' => $operation]);

            return [
                'success' => false,
                'code' => 'VALIDATION',
                'error' => "Unsupported aggregate operation '{$operation}'",
                'hint' => 'Use one of: count, sum, avg, min, max.',
            ];
        }

        if ($groupBy !== null && $groupBy !== '') {
            return $this->calculateGroups($items, $operation, $field, $groupBy);
        }

        $value = $this->calculateValue($items, $operation, $field);
        if (($value['success'] ?? false) !== true) {
            return $value;
        }

        return [
            'success' => true,
            'result' => $value['value'] ?? null,
            'count' => count($items),
            'skipped_non_numeric' => $value['skipped_non_numeric'] ?? 0,
        ];
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return array<string, mixed>
     */
    private function calculateGroups(array $items, string $operation, ?string $field, string $groupBy): array
    {
        $groups = [];
        foreach ($items as $item) {
            $rawKey = data_get($item, $groupBy);
            $key = is_scalar($rawKey) ? (string) $rawKey : '';
            if ($key === '') {
                $key = '(empty)';
            }
            $groups[$key][] = $item;
        }

        $out = [];
        foreach ($groups as $key => $rows) {
            /** @var list<array<string, mixed>> $rows */
            $value = $this->calculateValue($rows, $operation, $field);
            if (($value['success'] ?? false) !== true) {
                return $value;
            }

            $out[] = [
                'key' => $key,
                'value' => $value['value'] ?? null,
                'count' => count($rows),
                'skipped_non_numeric' => $value['skipped_non_numeric'] ?? 0,
            ];
        }

        usort(
            $out,
            static fn (array $a, array $b): int => strcmp($a['key'], $b['key']),
        );

        return ['success' => true, 'groups' => $out, 'count' => count($items)];
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return array<string, mixed>
     */
    private function calculateValue(array $items, string $operation, ?string $field): array
    {
        if ($operation === 'count') {
            return ['success' => true, 'value' => count($items), 'skipped_non_numeric' => 0];
        }

        if ($field === null || $field === '') {
            Log::warning('AggregateCalculator::calculateValue — field missing for numeric operation', [
                'operation' => $operation,
            ]);

            return [
                'success' => false,
                'code' => 'VALIDATION',
                'error' => "'field' is required for {$operation}",
                'hint' => 'Pass the numeric resource field to aggregate, e.g. {"field": "amount"}.',
            ];
        }

        $values = [];
        $skipped = 0;
        foreach ($items as $item) {
            $value = data_get($item, $field);
            if (is_int($value) || is_float($value)) {
                $values[] = (float) $value;

                continue;
            }
            if (is_string($value) && is_numeric($value)) {
                $values[] = (float) $value;

                continue;
            }
            $skipped++;
        }

        if ($values === []) {
            Log::warning('AggregateCalculator::calculateValue — no numeric input', [
                'operation' => $operation,
                'field' => $field,
                'skipped_non_numeric' => $skipped,
            ]);

            return ['success' => true, 'value' => null, 'skipped_non_numeric' => $skipped];
        }

        $value = match ($operation) {
            'sum' => array_sum($values),
            'avg' => array_sum($values) / count($values),
            'min' => min($values),
            'max' => max($values),
            default => null,
        };

        Log::debug('AggregateCalculator::calculateValue — completed', [
            'operation' => $operation,
            'field' => $field,
            'numeric_count' => count($values),
            'skipped_non_numeric' => $skipped,
        ]);

        return ['success' => true, 'value' => $value, 'skipped_non_numeric' => $skipped];
    }
}
