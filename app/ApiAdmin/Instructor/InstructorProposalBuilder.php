<?php

declare(strict_types=1);

namespace App\ApiAdmin\Instructor;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final readonly class InstructorProposalBuilder
{
    /**
     * @param array{success: bool, base_url: string, probes: list<array<string, mixed>>, warnings: list<string>} $discovery
     * @param array<string, mixed> $input
     *
     * @return array{proposal: array<string, mixed>, warnings: list<string>}
     */
    public function build(array $discovery, array $input): array
    {
        $baseUrl = $discovery['base_url'];
        $serviceName = $this->serviceName($input, $baseUrl);
        $probe = $this->bestProbe($discovery['probes']);
        $warnings = $discovery['warnings'];

        if ($probe === null) {
            $warnings[] = 'No successful probe was available for an initial resource proposal.';
        }

        $mapping = is_array($probe['guessed_mapping'] ?? null) ? $probe['guessed_mapping'] : ['data_key' => 'data', 'primary_key' => 'id'];
        $format = is_string($probe['guessed_format'] ?? null) ? $probe['guessed_format'] : 'custom';
        $columns = $this->columns($probe);
        $primaryKey = is_string($mapping['primary_key'] ?? null) && $mapping['primary_key'] !== '' ? $mapping['primary_key'] : 'id';

        if (! in_array($primaryKey, $columns, true)) {
            array_unshift($columns, $primaryKey);
            $warnings[] = "Primary key '{$primaryKey}' was inferred and should be reviewed.";
        }

        $resourceName = $this->resourceName($probe, $input);
        $fields = $this->fields($columns, $primaryKey);

        $proposal = [
            'service' => [
                'name' => $serviceName,
                'base_url' => $baseUrl,
                'api_key' => is_string($input['api_key'] ?? null) ? $input['api_key'] : '',
                'panel' => $serviceName.'-admin',
                'title' => Str::title(str_replace(['-', '_'], ' ', $serviceName)).' Admin',
                'auth_type' => is_string($input['auth_type'] ?? null) ? $input['auth_type'] : 'none',
                'auth_header' => is_string($input['auth_header'] ?? null) ? $input['auth_header'] : 'Authorization',
                'health_path' => is_string($input['health_path'] ?? null) ? $input['health_path'] : '/health',
                'response_format' => in_array($format, ['laravel', 'django', 'flat', 'github'], true) ? $format : 'custom',
                'response_mapping' => $mapping,
            ],
            'resources' => [[
                'service' => $serviceName,
                'name' => $resourceName,
                'endpoint' => is_string($probe['path'] ?? null) ? $probe['path'] : '/'.$resourceName,
                'title' => Str::headline(Str::plural($resourceName)),
                'fields' => $fields,
                'search' => $this->searchFields($fields),
                'filters' => $this->filterFields($fields),
            ]],
        ];

        Log::info('InstructorProposalBuilder: proposal built', [
            'service' => $serviceName,
            'resources_count' => count($proposal['resources']),
            'warnings' => count($warnings),
        ]);
        Log::debug('InstructorProposalBuilder: inferred proposal keys', [
            'service_keys' => array_keys($proposal['service']),
            'resource_keys' => array_keys($proposal['resources'][0]),
        ]);

        return [
            'proposal' => InstructorSession::redact($proposal),
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /**
     * @param list<array<string, mixed>> $probes
     *
     * @return array<string, mixed>|null
     */
    private function bestProbe(array $probes): ?array
    {
        foreach ($probes as $probe) {
            if (($probe['success'] ?? false) === true && $this->columns($probe) !== []) {
                return $probe;
            }
        }

        foreach ($probes as $probe) {
            if (($probe['success'] ?? false) === true) {
                return $probe;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed>|null $probe
     *
     * @return list<string>
     */
    private function columns(?array $probe): array
    {
        $columns = is_array($probe['detected_columns'] ?? null) ? $probe['detected_columns'] : [];

        return array_filter($columns, is_string(...))
            |> array_values(...);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function serviceName(array $input, string $baseUrl): string
    {
        if (is_string($input['service_name'] ?? null) && $input['service_name'] !== '') {
            return Str::slug($input['service_name'], '_');
        }

        $host = parse_url($baseUrl, PHP_URL_HOST);
        $host = is_string($host) && $host !== '' ? $host : 'service';
        $parts = explode('.', $host);
        $candidate = $parts[0];

        return Str::slug($candidate, '_') ?: 'service';
    }

    /**
     * @param array<string, mixed>|null $probe
     * @param array<string, mixed> $input
     */
    private function resourceName(?array $probe, array $input): string
    {
        if (is_string($input['resource_name'] ?? null) && $input['resource_name'] !== '') {
            return Str::singular(Str::slug($input['resource_name'], '_'));
        }

        $path = is_string($probe['path'] ?? null) ? trim($probe['path'], '/') : 'resource';
        $segment = basename($path);

        return Str::singular(Str::slug($segment !== '' ? $segment : 'resource', '_'));
    }

    /**
     * @param list<string> $columns
     *
     * @return list<array<string, mixed>>
     */
    private function fields(array $columns, string $primaryKey): array
    {
        $fields = [];

        foreach (array_values(array_unique($columns)) as $column) {
            $type = $column === $primaryKey ? 'id' : $this->fieldType($column);
            $field = [
                'column' => $column,
                'label' => Str::headline($column),
                'type' => $type,
            ];

            if ($column === $primaryKey || in_array($column, ['created_at', 'updated_at', 'deleted_at'], true)) {
                $field['hide_on'] = ['form'];
            }

            $fields[] = $field;
        }

        if ($fields === []) {
            Log::warning('InstructorProposalBuilder: empty field proposal');

            return [['column' => $primaryKey, 'label' => Str::headline($primaryKey), 'type' => 'id', 'hide_on' => ['form']]];
        }

        return $fields;
    }

    private function fieldType(string $column): string
    {
        $lower = strtolower($column);

        return match (true) {
            str_ends_with($lower, '_at') || str_ends_with($lower, '_date') => 'datetime',
            str_contains($lower, 'email') => 'email',
            str_contains($lower, 'url') || str_contains($lower, 'link') => 'url',
            str_starts_with($lower, 'is_') || str_starts_with($lower, 'has_') || str_contains($lower, 'active') => 'switcher',
            str_ends_with($lower, '_id') => 'number',
            str_contains($lower, 'count') || str_contains($lower, 'total') || str_contains($lower, 'amount') || str_contains($lower, 'price') => 'number',
            str_contains($lower, 'body') || str_contains($lower, 'description') || str_contains($lower, 'content') => 'textarea',
            default => 'text',
        };
    }

    /**
     * @param list<array<string, mixed>> $fields
     *
     * @return list<string>
     */
    private function searchFields(array $fields): array
    {
        $search = [];

        foreach ($fields as $field) {
            $type = $field['type'] ?? null;
            $column = $field['column'] ?? null;
            if (is_string($column) && in_array($type, ['text', 'textarea', 'email'], true)) {
                $search[] = $column;
            }
        }

        return array_slice($search, 0, 3);
    }

    /**
     * @param list<array<string, mixed>> $fields
     *
     * @return list<array<string, mixed>>
     */
    private function filterFields(array $fields): array
    {
        $filters = [];

        foreach ($fields as $field) {
            $type = $field['type'] ?? null;
            $column = $field['column'] ?? null;
            if (is_string($column) && in_array($type, ['switcher', 'number'], true) && count($filters) < 3) {
                $filters[] = [
                    'column' => $column,
                    'label' => is_string($field['label'] ?? null) ? $field['label'] : Str::headline($column),
                    'type' => $type,
                ];
            }
        }

        return $filters;
    }
}
