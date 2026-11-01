<?php

declare(strict_types=1);

namespace App\ApiAdmin\Validation;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\MessageBag;
use Illuminate\Validation\ValidationException;

/**
 * Two-layer validation error mapper.
 *
 * Layer 1: Standard Laravel validation rules applied before the API call.
 * Layer 2: API 422 errors mapped back to form fields after the API call.
 *
 * When the remote API returns a 422 Unprocessable Entity response,
 * this mapper extracts field-level errors and converts them into
 * a ValidationException that MoonShine can display on the form.
 */
final class ApiErrorMapper
{
    /**
     * Default field mapping for error key translation.
     * Maps API field names to form field names when they differ.
     *
     * @var array<string, string>
     */
    private array $fieldMap = [];

    /**
     * Key in the API response where errors are located.
     */
    private string $errorsKey = 'errors';

    /**
     * Key in the API response for a general error message.
     */
    private string $messageKey = 'message';

    /**
     * Set field name mapping: API field name → form field name.
     *
     * @param array<string, string> $map
     */
    public function fieldMap(array $map): self
    {
        $this->fieldMap = $map;

        Log::debug('ApiErrorMapper: field map set', [
            'mappings' => array_keys($map),
        ]);

        return $this;
    }

    /**
     * Set the key where errors are located in the API response.
     */
    public function errorsKey(string $key): self
    {
        $this->errorsKey = $key;

        return $this;
    }

    /**
     * Set the key for the general error message.
     */
    public function messageKey(string $key): self
    {
        $this->messageKey = $key;

        return $this;
    }

    /**
     * Check an HTTP response for validation errors (422) and throw if found.
     *
     * @throws ValidationException When the API returns 422 with field errors
     */
    public function handleResponse(Response $response): void
    {
        if ($response->status() !== 422) {
            Log::debug('ApiErrorMapper::handleResponse — not a validation error', [
                'status' => $response->status(),
            ]);

            return;
        }

        $rawJson = $response->json();
        $json = is_array($rawJson) ? $rawJson : null;

        Log::info('ApiErrorMapper::handleResponse — API returned 422', [
            'has_errors' => $json !== null && array_key_exists($this->errorsKey, $json),
            'has_message' => $json !== null && array_key_exists($this->messageKey, $json),
        ]);

        $errors = $this->extractErrors($json);

        if ($errors->isEmpty()) {
            Log::warning('ApiErrorMapper::handleResponse — 422 with no parseable errors', [
                'response_keys' => $json !== null ? array_keys($json) : [],
            ]);

            $message = $json !== null ? data_get($json, $this->messageKey, 'Validation failed') : 'Validation failed';
            $errors->add('_general', is_string($message) ? $message : 'Validation failed');
        }

        Log::info('ApiErrorMapper::handleResponse — throwing ValidationException', [
            'error_fields' => $errors->keys(),
            'error_count' => $errors->count(),
        ]);

        throw ValidationException::withMessages($errors->toArray());
    }

    /**
     * Extract field errors from API response and map them to form field names.
     *
     * Supports multiple API error formats:
     * - Laravel: {"errors": {"field": ["message1", "message2"]}}
     * - Django REST: {"field": ["message1"]}
     * - Generic: {"errors": [{"field": "name", "message": "required"}]}
     *
     * @param array<int|string, mixed>|null $json
     */
    public function extractErrors(?array $json): MessageBag
    {
        $bag = new MessageBag;

        if ($json === null) {
            return $bag;
        }

        $errors = data_get($json, $this->errorsKey);

        // Try flat format: errors at top level (Django REST style)
        $errors ??= $json;

        if (! is_array($errors)) {
            return $bag;
        }

        Log::debug('ApiErrorMapper::extractErrors', [
            'error_type' => $this->detectFormat($errors),
            'raw_keys' => array_keys($errors),
        ]);

        // Format 1: {"field": ["msg1", "msg2"]} or {"field": "msg"}
        if ($this->isAssociativeFieldErrors($errors)) {
            foreach ($errors as $field => $messages) {
                $mappedField = $this->mapFieldName($this->scalarToString($field));
                $messagesList = is_array($messages) ? $messages : [$messages];

                foreach ($messagesList as $message) {
                    $bag->add($mappedField, $this->scalarToString($message));
                }
            }

            return $bag;
        }

        // Format 2: [{"field": "name", "message": "required"}]
        if ($this->isObjectArrayErrors($errors)) {
            foreach ($errors as $error) {
                if (! is_array($error)) {
                    continue;
                }

                $fieldRaw = $error['field'] ?? $error['attr'] ?? $error['source'] ?? '_general';
                $messageRaw = $error['message'] ?? $error['detail'] ?? $error['msg'] ?? 'Unknown error';

                $mappedField = $this->mapFieldName($this->scalarToString($fieldRaw));
                $bag->add($mappedField, $this->scalarToString($messageRaw));
            }

            return $bag;
        }

        // Format 3: ["error message 1", "error message 2"] — no field association
        foreach ($errors as $message) {
            if (is_string($message)) {
                $bag->add('_general', $message);
            }
        }

        return $bag;
    }

    /**
     * Convert a scalar-ish value to string without tripping PHP's
     * "array to string conversion" notice when the source is mixed.
     */
    private function scalarToString(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return '';
    }

    /**
     * Map an API field name to the form field name.
     */
    private function mapFieldName(string $apiField): string
    {
        $mapped = $this->fieldMap[$apiField] ?? $apiField;

        if ($mapped !== $apiField) {
            Log::debug('ApiErrorMapper: field mapped', [
                'api_field' => $apiField,
                'form_field' => $mapped,
            ]);
        }

        return $mapped;
    }

    /**
     * Detect whether errors array is associative (field → messages format).
     *
     * @param array<int|string, mixed> $errors
     */
    private function isAssociativeFieldErrors(array $errors): bool
    {
        return ! array_is_list($errors);
    }

    /**
     * Detect whether errors is an array of objects with field/message keys.
     *
     * @param array<int|string, mixed> $errors
     */
    private function isObjectArrayErrors(array $errors): bool
    {
        if (! array_is_list($errors)) {
            return false;
        }

        $first = $errors[0] ?? null;

        return is_array($first) && (isset($first['field']) || isset($first['attr']) || isset($first['source']));
    }

    /**
     * Detect the error format for logging purposes.
     *
     * @param array<int|string, mixed> $errors
     */
    private function detectFormat(array $errors): string
    {
        if ($this->isAssociativeFieldErrors($errors)) {
            return 'field_messages';
        }

        if ($this->isObjectArrayErrors($errors)) {
            return 'object_array';
        }

        return 'flat_array';
    }
}
