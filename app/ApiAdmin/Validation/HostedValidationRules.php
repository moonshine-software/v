<?php

declare(strict_types=1);

namespace App\ApiAdmin\Validation;

use InvalidArgumentException;

final class HostedValidationRules
{
    /** @var list<string> */
    private const array RULES = [
        'bail', 'required', 'required_if', 'required_unless', 'required_with',
        'required_with_all', 'required_without', 'required_without_all',
        'nullable', 'sometimes', 'present', 'filled', 'missing', 'prohibited',
        'prohibited_if', 'prohibited_unless', 'exclude', 'exclude_if',
        'exclude_unless', 'exclude_with', 'exclude_without', 'accepted',
        'accepted_if', 'declined', 'declined_if', 'confirmed', 'same', 'different',
        'string', 'integer', 'numeric', 'decimal', 'boolean', 'array', 'list',
        'min', 'max', 'size', 'between', 'digits', 'digits_between', 'min_digits',
        'max_digits', 'in', 'not_in', 'distinct', 'gt', 'gte', 'lt', 'lte',
        'multiple_of', 'ascii', 'alpha', 'alpha_dash', 'alpha_num', 'lowercase',
        'uppercase', 'starts_with', 'ends_with', 'doesnt_start_with',
        'doesnt_end_with', 'uuid', 'ulid', 'ip', 'ipv4', 'ipv6', 'mac_address',
        'date', 'date_equals', 'date_format', 'before', 'before_or_equal',
        'after', 'after_or_equal', 'timezone', 'json', 'url', 'email',
    ];

    /** @return list<string> */
    public static function names(): array
    {
        return self::RULES;
    }

    /** @param array<string, mixed> $map */
    public static function assertMap(array $map): void
    {
        if (config('api-admin.hosted.mode', 'local') !== 'hosted') {
            return;
        }
        foreach ($map as $rules) {
            $rules = is_array($rules) ? $rules : [$rules];
            foreach ($rules as $rule) {
                if (! is_string($rule)) {
                    throw new InvalidArgumentException('Hosted validation permits declarative string rules only.');
                }
                self::assertString($rule);
            }
        }
    }

    public static function assertString(string $rules): void
    {
        if (config('api-admin.hosted.mode', 'local') !== 'hosted') {
            return;
        }
        foreach (explode('|', $rules) as $rule) {
            [$name, $parameters] = array_pad(explode(':', $rule, 2), 2, '');
            $name = $name
                |> trim(...)
                |> strtolower(...);
            if (! in_array($name, self::RULES, true)) {
                throw new InvalidArgumentException("Validation rule [{$name}] is not available for hosted generation.");
            }
            if ($name === 'email' && $parameters !== ''
                && array_diff(explode(',', $parameters), ['rfc', 'filter', 'filter_unicode']) !== []) {
                throw new InvalidArgumentException('Hosted email validation permits only rfc, filter, and filter_unicode modes.');
            }
        }
    }
}
