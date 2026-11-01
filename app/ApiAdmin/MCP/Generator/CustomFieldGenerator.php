<?php

declare(strict_types=1);

namespace App\ApiAdmin\MCP\Generator;

use App\ApiAdmin\MCP\AtomicFileWriter;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Emits a caller-generated custom field class under
 * `app/ApiAdmin/Fields/Custom/<Name>.php` and registers it in the
 * `api-admin.custom_fields` config map so `fieldTypeCatalog()` picks
 * it up on the next call.
 *
 * Paired with the `create_field` MCP tool — keeps the filesystem +
 * config write in one transactional step so the two never drift.
 */
final class CustomFieldGenerator
{
    /**
     * @param array{
     *     name: string,
     *     extends: string,
     *     alias?: string,
     *     methods_override?: list<array{name: string, body: string, return?: string}>,
     *     properties?: list<array{name: string, type: string, default?: string}>,
     * } $params
     *
     * @return array{file: string, class: string, alias: string, type: string}
     */
    public function generate(array $params): array
    {
        $name = Str::studly($params['name']);
        if ($name === '') {
            throw new RuntimeException('Custom field name must be non-empty');
        }

        $parentClass = $this->resolveParentClass($params['extends']);
        $typeKey = Str::snake($params['name']);
        $alias = isset($params['alias']) && $params['alias'] !== '' ? $params['alias'] : $name;

        $useStatements = ['use '.$parentClass.';'];
        $parentBasename = $this->classBaseName($parentClass);

        $properties = $this->renderProperties($params['properties'] ?? []);
        $overrides = $this->renderOverrideMethods($params['methods_override'] ?? []);

        $stub = File::get(base_path('stubs/api-admin/custom-field.stub'));
        $rendered = strtr($stub, [
            '{{className}}' => $name,
            '{{parentClass}}' => $parentBasename,
            '{{useStatements}}' => implode("\n", $useStatements),
            '{{properties}}' => $properties,
            '{{overrideMethods}}' => $overrides,
        ]);

        $dir = base_path('app/ApiAdmin/Fields/Custom');
        AtomicFileWriter::ensureDirectoryExists($dir);
        $file = $dir.'/'.$name.'.php';
        AtomicFileWriter::put($file, $rendered);

        $fqcn = 'App\\ApiAdmin\\Fields\\Custom\\'.$name;
        $this->registerInConfig($typeKey, $fqcn, $alias);

        Log::info('CustomFieldGenerator::generate', [
            'name' => $name,
            'file' => $file,
            'type_key' => $typeKey,
            'parent' => $parentClass,
        ]);

        return [
            'file' => $file,
            'class' => $fqcn,
            'alias' => $alias,
            'type' => $typeKey,
        ];
    }

    /**
     * Remove a custom field — drops the PHP file and the config entry.
     * Returns true if anything was removed, false if the field did not
     * exist to begin with.
     */
    public function delete(string $name): bool
    {
        $studly = Str::studly($name);
        $file = base_path('app/ApiAdmin/Fields/Custom/'.$studly.'.php');
        $existed = false;

        if (File::exists($file)) {
            AtomicFileWriter::delete($file);
            $existed = true;
        }

        $typeKey = Str::snake($name);
        if ($this->unregisterFromConfig($typeKey)) {
            $existed = true;
        }

        Log::info('CustomFieldGenerator::delete', [
            'name' => $name,
            'existed' => $existed,
        ]);

        return $existed;
    }

    /**
     * Accept either a built-in `type` string (e.g. `text`) or an explicit
     * class-string. Built-ins route through the catalog so adding a new
     * type to FIELD_TYPE_MAP auto-applies here.
     */
    private function resolveParentClass(string $extends): string
    {
        $catalog = ResourceGenerator::fieldTypeCatalog();
        if (isset($catalog[$extends])) {
            return $catalog[$extends]['class'];
        }

        if (class_exists($extends)) {
            return $extends;
        }

        throw new RuntimeException("Unknown parent field type/class: {$extends}");
    }

    /**
     * @param list<array{name: string, type: string, default?: string}> $properties
     */
    private function renderProperties(array $properties): string
    {
        if ($properties === []) {
            return '';
        }

        $lines = [];
        foreach ($properties as $prop) {
            $default = isset($prop['default']) && $prop['default'] !== '' ? ' = '.$prop['default'] : '';
            $lines[] = '    protected '.$prop['type'].' $'.$prop['name'].$default.';';
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @param list<array{name: string, body: string, return?: string}> $methods
     */
    private function renderOverrideMethods(array $methods): string
    {
        if ($methods === []) {
            return '';
        }

        $blocks = [];
        foreach ($methods as $method) {
            $return = isset($method['return']) && $method['return'] !== '' ? ': '.$method['return'] : '';
            $body = trim($method['body']);
            $body = $body === '' ? '' : '        '.str_replace("\n", "\n        ", $body);
            $blocks[] = "    protected function {$method['name']}(){$return}\n    {\n{$body}\n    }";
        }

        return implode("\n\n", $blocks)."\n";
    }

    private function registerInConfig(string $typeKey, string $fqcn, string $alias): void
    {
        $path = config_path('api-admin.php');
        $source = File::get($path);

        $entry = "    '{$typeKey}' => ['class' => \\{$fqcn}::class, 'alias' => '{$alias}'],";

        $updated = $this->rewriteCustomFieldsArray($source, $typeKey, $entry);
        AtomicFileWriter::put($path, $updated);

        // Clear config cache so fieldTypeCatalog() sees the new entry
        // in the current process (the MCP server is long-running).
        config(['api-admin.custom_fields' => array_merge(
            (array) config('api-admin.custom_fields', []),
            [$typeKey => $fqcn],
        )]);
    }

    private function unregisterFromConfig(string $typeKey): bool
    {
        $path = config_path('api-admin.php');
        $source = File::get($path);
        $updated = $this->rewriteCustomFieldsArray($source, $typeKey, null);

        if ($updated === $source) {
            return false;
        }

        AtomicFileWriter::put($path, $updated);

        $current = (array) config('api-admin.custom_fields', []);
        unset($current[$typeKey]);
        config(['api-admin.custom_fields' => $current]);

        return true;
    }

    /**
     * Rewrite the `custom_fields => [...]` block in config/api-admin.php
     * idempotently: add the `$addEntry` line (replacing any pre-existing
     * one with the same `$typeKey`), or remove any line with that key if
     * `$addEntry` is null.
     */
    private function rewriteCustomFieldsArray(string $source, string $typeKey, ?string $addEntry): string
    {
        if (! preg_match("/('custom_fields'\s*=>\s*\[)([^\]]*)(\],)/s", $source, $match, PREG_OFFSET_CAPTURE)) {
            // Block missing — nothing to do for delete; for add, prepend
            // a fresh block before the closing `];` of the returned array.
            if ($addEntry === null) {
                return $source;
            }
            $fresh = "    'custom_fields' => [\n".$addEntry."\n    ],\n];";

            return preg_replace('/\];\s*$/', $fresh, $source) ?? $source;
        }

        $prefix = $match[1][0];
        $body = $match[2][0];
        $suffix = $match[3][0];
        $offset = $match[0][1];
        $length = strlen($match[0][0]);

        // Parse existing entries by splitting on line-break; preserve order.
        $lines = array_filter(
            array_map(rtrim(...), explode("\n", $body)),
            static fn (string $l): bool => trim($l) !== '',
        )
            |> array_values(...);

        $filtered = [];
        foreach ($lines as $line) {
            if (preg_match("/'{$typeKey}'\s*=>/", $line)) {
                continue;
            }
            $filtered[] = $line;
        }

        if ($addEntry !== null) {
            $filtered[] = $addEntry;
        }

        $newBody = $filtered === []
            ? "\n    "
            : "\n".implode("\n", $filtered)."\n    ";

        $replacement = $prefix.$newBody.$suffix;

        return substr($source, 0, $offset).$replacement.substr($source, $offset + $length);
    }

    private function classBaseName(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');

        return $pos === false ? $fqcn : substr($fqcn, $pos + 1);
    }
}
