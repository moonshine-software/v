<?php

declare(strict_types=1);

namespace App\ApiAdmin\Instructor;

use Illuminate\Support\Arr;

final readonly class InstructorSession
{
    /**
     * @param array<string, mixed> $input
     * @param list<array<string, mixed>> $probes
     * @param array<string, mixed> $proposal
     * @param array<string, mixed> $applyResult
     * @param list<string> $warnings
     */
    public function __construct(
        public string $id,
        public string $step = 'start',
        public array $input = [],
        public array $probes = [],
        public array $proposal = [],
        public array $applyResult = [],
        public array $warnings = [],
    ) {}

    /**
     * @param array<mixed, mixed> $state
     */
    public static function fromArray(string $id, array $state): self
    {
        $step = Arr::get($state, 'step', 'start');
        $input = Arr::get($state, 'input', []);
        $probes = Arr::get($state, 'probes', []);
        $proposal = Arr::get($state, 'proposal', []);
        $applyResult = Arr::get($state, 'apply_result', []);
        $warnings = Arr::get($state, 'warnings', []);

        return new self(
            id: $id,
            step: is_string($step) && $step !== '' ? $step : 'start',
            input: is_array($input) ? self::stringMap($input) : [],
            probes: self::listOfArrays($probes),
            proposal: is_array($proposal) ? self::stringMap($proposal) : [],
            applyResult: is_array($applyResult) ? self::stringMap($applyResult) : [],
            warnings: self::listOfStrings($warnings),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'step' => $this->step,
            'input' => self::redact($this->input),
            'probes' => $this->probes,
            'proposal' => self::redact($this->proposal),
            'apply_result' => self::redact($this->applyResult),
            'warnings' => $this->warnings,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function publicSummary(): array
    {
        return [
            'id' => $this->id,
            'step' => $this->step,
            'has_proposal' => $this->proposal !== [],
            'proposal' => self::redact($this->proposal),
            'warnings' => $this->warnings,
            'apply_result' => self::redact($this->applyResult),
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    public function withInput(array $input): self
    {
        return new self(
            id: $this->id,
            step: $this->step,
            input: self::redact($input),
            probes: $this->probes,
            proposal: $this->proposal,
            applyResult: $this->applyResult,
            warnings: $this->warnings,
        );
    }

    /**
     * @param list<array<string, mixed>> $probes
     * @param list<string> $warnings
     */
    public function withDiscovery(array $probes, array $warnings): self
    {
        return new self(
            id: $this->id,
            step: 'review',
            input: $this->input,
            probes: $probes,
            proposal: $this->proposal,
            applyResult: $this->applyResult,
            warnings: $warnings,
        );
    }

    /**
     * @param array<string, mixed> $proposal
     * @param list<string> $warnings
     */
    public function withProposal(array $proposal, array $warnings = []): self
    {
        return new self(
            id: $this->id,
            step: 'review',
            input: $this->input,
            probes: $this->probes,
            proposal: self::redact($proposal),
            applyResult: $this->applyResult,
            warnings: array_values(array_unique([...$this->warnings, ...$warnings])),
        );
    }

    /**
     * @param array<string, mixed> $result
     */
    public function withApplyResult(array $result): self
    {
        return new self(
            id: $this->id,
            step: ($result['success'] ?? false) === true ? 'completed' : 'review',
            input: $this->input,
            probes: $this->probes,
            proposal: $this->proposal,
            applyResult: self::redact($result),
            warnings: $this->warnings,
        );
    }

    /**
     * @param array<mixed, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public static function redact(array $payload): array
    {
        $secretKeys = [
            'api_key',
            'auth_header',
            'oauth_client_secret',
            'oauth_password',
            'oauth_refresh_token',
            'session_password',
            'password',
            'token',
            'cookie',
        ];

        $redacted = [];

        foreach ($payload as $key => $value) {
            $keyString = is_string($key) ? $key : (string) $key;
            $isSecret = array_any($secretKeys, fn (string $secretKey): bool => str_contains(strtolower($keyString), $secretKey));

            if ($isSecret && $value !== '' && $value !== null) {
                $redacted[$keyString] = '[redacted]';
                continue;
            }

            $redacted[$keyString] = is_array($value) ? self::redact(self::stringMap($value)) : $value;
        }

        return $redacted;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function listOfArrays(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];

        foreach ($value as $item) {
            if (is_array($item)) {
                $out[] = self::stringMap($item);
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private static function listOfStrings(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_filter($value, is_string(...))
            |> array_values(...);
    }

    /**
     * @param array<mixed, mixed> $value
     *
     * @return array<string, mixed>
     */
    private static function stringMap(array $value): array
    {
        $out = [];

        foreach ($value as $key => $item) {
            $out[is_string($key) ? $key : (string) $key] = is_array($item) ? self::stringMap($item) : $item;
        }

        return $out;
    }
}
