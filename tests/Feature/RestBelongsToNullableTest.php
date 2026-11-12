<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\ApiAdmin\Fields\RestBelongsTo;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RestBelongsToNullableTest extends TestCase
{
    /**
     * @param array<string, mixed> $input
     */
    #[DataProvider('relationInputs')]
    public function test_relation_applies_native_select_null_and_missing_value_semantics(
        array $input,
        bool $nullable,
        int|string|null $expected,
    ): void {
        request()->merge($input);
        $field = RestBelongsTo::make('Assignee', 'assignee_id')->nullable($nullable);

        $result = $field->apply(static fn (array $data): array => $data, ['assignee_id' => 17]);

        $this->assertSame(['assignee_id' => $expected], $result);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, bool, int|string|null}>
     */
    public static function relationInputs(): iterable
    {
        yield 'nullable explicit null clears the relation' => [['assignee_id' => null], true, null];
        yield 'nullable omitted input preserves the relation' => [[], true, 17];
        yield 'non-nullable null preserves the relation' => [['assignee_id' => null], false, 17];
        yield 'selected identifier is preserved' => [['assignee_id' => '42'], true, '42'];
        yield 'zero identifier is preserved' => [['assignee_id' => '0'], true, '0'];
    }
}
