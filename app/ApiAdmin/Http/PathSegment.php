<?php

declare(strict_types=1);

namespace App\ApiAdmin\Http;

use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

final class PathSegment
{
    public static function encode(string|int $value): string
    {
        $value = (string) $value;
        // Percent escapes can become separators or dot segments after an upstream decode.
        if (in_array($value, ['', '.', '..'], true) || preg_match('/[\/\\\\%\x00-\x1f\x7f]/', $value) === 1) {
            Log::warning('Rejected unsafe endpoint path segment');

            throw ValidationException::withMessages(['id' => 'Invalid record ID or endpoint path value.']);
        }

        return rawurlencode($value);
    }
}
