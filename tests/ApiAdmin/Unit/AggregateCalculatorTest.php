<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Unit;

use App\ApiAdmin\MCP\Analytics\AggregateCalculator;
use Orchestra\Testbench\TestCase;

final class AggregateCalculatorTest extends TestCase
{
    public function test_count_does_not_require_field(): void
    {
        $result = (new AggregateCalculator)->calculate([
            ['id' => 1],
            ['id' => 2],
        ], 'count');

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['result']);
        $this->assertSame(2, $result['count']);
    }

    public function test_sum_avg_min_max_skip_non_numeric_values(): void
    {
        $items = [
            ['amount' => 10],
            ['amount' => '20.5'],
            ['amount' => 'not numeric'],
        ];

        $calculator = new AggregateCalculator;

        $sum = $calculator->calculate($items, 'sum', 'amount');
        $this->assertTrue($sum['success']);
        $this->assertSame(30.5, $sum['result']);
        $this->assertSame(1, $sum['skipped_non_numeric']);

        $avg = $calculator->calculate($items, 'avg', 'amount');
        $this->assertTrue($avg['success']);
        $this->assertSame(15.25, $avg['result']);

        $min = $calculator->calculate($items, 'min', 'amount');
        $this->assertTrue($min['success']);
        $this->assertSame(10.0, $min['result']);

        $max = $calculator->calculate($items, 'max', 'amount');
        $this->assertTrue($max['success']);
        $this->assertSame(20.5, $max['result']);
    }

    public function test_numeric_operation_requires_field(): void
    {
        $result = (new AggregateCalculator)->calculate([['amount' => 10]], 'sum');

        $this->assertFalse($result['success']);
        $this->assertSame('VALIDATION', $result['code']);
    }

    public function test_groups_are_sorted_by_key(): void
    {
        $result = (new AggregateCalculator)->calculate([
            ['status' => 'published', 'amount' => 10],
            ['status' => 'draft', 'amount' => 5],
            ['status' => 'published', 'amount' => 7],
        ], 'sum', 'amount', 'status');

        $this->assertTrue($result['success']);
        $groups = $result['groups'];
        $this->assertIsArray($groups);
        $first = $groups[0];
        $this->assertIsArray($first);
        $second = $groups[1];
        $this->assertIsArray($second);
        $this->assertSame('draft', $first['key']);
        $this->assertSame(5.0, $first['value']);
        $this->assertSame('published', $second['key']);
        $this->assertSame(17.0, $second['value']);
    }

    public function test_invalid_operation_returns_validation_error(): void
    {
        $result = (new AggregateCalculator)->calculate([], 'median', 'amount');

        $this->assertFalse($result['success']);
        $this->assertSame('VALIDATION', $result['code']);
    }
}
