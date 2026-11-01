<?php

declare(strict_types=1);

namespace App\ApiAdmin\MCP\Generator;

use App\ApiAdmin\Resources\AbstractRestResource;
use Illuminate\Support\Facades\Log;
use ReflectionClass;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Post-generation validator for resources emitted by ResourceGenerator.
 *
 * Runs a short battery of checks against each generated file + class and
 * returns a structured issue list. The MCP tool layer surfaces this back
 * to the calling agent so it can notice regressions (unknown field
 * method, broken autoload, bad route) immediately rather than after the
 * user hits 500 in the browser.
 *
 * Each issue:
 *   ['level' => 'error'|'warning', 'file' => string, 'message' => string, 'hint'?: string]
 */
final class ResourceValidator
{
    private const int PHP_LINT_TIMEOUT_SECONDS = 10;

    /**
     * Validate the result of ResourceGenerator::generate().
     *
     * @param array<string, mixed> $generateResult raw return of generate()
     *
     * @return array{ok: bool, issues: list<array<string, string>>, files_checked: int}
     */
    public function validate(array $generateResult): array
    {
        $issues = [];
        $files = $this->collectFiles($generateResult);

        foreach ($files as $file) {
            if (! is_file($file)) {
                continue;
            }

            // Scan for unreplaced Blade-style placeholders FIRST. php -l
            // catches most of those via syntax error, but a stub slot
            // embedded inside a comment or a string literal slips past
            // the parser and makes the generated code behave wrongly at
            // runtime. The most common cause is a version mismatch
            // between the stub on disk and the running MCP process —
            // the process has opcache'd an older generator that doesn't
            // know about a new placeholder, so `str_replace` leaves it
            // literal. Rebuilding / reconnecting the MCP server picks
            // up the new generator.
            $placeholderIssue = $this->checkForUnreplacedPlaceholders($file);
            if ($placeholderIssue !== null) {
                $issues[] = $placeholderIssue;
            }

            $lint = $this->lintSyntax($file);
            if ($lint !== null) {
                $issues[] = $lint;
            }
        }

        // Resource + pages class-level checks. Skipped when syntax already
        // failed — loading broken PHP would fatal out of the whole worker.
        $hasSyntaxError = (bool) array_filter(
            $issues,
            static fn (array $issue): bool => ($issue['level'] ?? '') === 'error',
        );

        if (! $hasSyntaxError && isset($generateResult['class']) && is_string($generateResult['class'])) {
            $classIssues = $this->validateResourceClass($generateResult['class']);
            $issues = array_merge($issues, $classIssues);
        }

        $ok = array_filter($issues, static fn (array $issue): bool => ($issue['level'] ?? '') === 'error') === [];

        Log::info('ResourceValidator: completed', [
            'ok' => $ok,
            'issues' => count($issues),
            'files_checked' => count($files),
        ]);

        return [
            'ok' => $ok,
            'issues' => $issues,
            'files_checked' => count($files),
        ];
    }

    /**
     * Collect every file we should lint — the resource itself plus pages
     * plus any relation sub-resources with their own pages.
     *
     * @param array<string, mixed> $generateResult
     *
     * @return list<string>
     */
    private function collectFiles(array $generateResult): array
    {
        $files = [];

        if (isset($generateResult['file']) && is_string($generateResult['file'])) {
            $files[] = $generateResult['file'];
        }

        foreach ((array) ($generateResult['pages'] ?? []) as $page) {
            if (is_string($page)) {
                $files[] = $page;
            }
        }

        foreach ((array) ($generateResult['relationResources'] ?? []) as $relation) {
            if (is_array($relation) && isset($relation['file']) && is_string($relation['file'])) {
                $files[] = $relation['file'];
            }
        }

        return $files;
    }

    /**
     * Run `php -l` on a generated file. Returns an issue array on failure
     * or null when the file parses clean.
     *
     * @return array<string, string>|null
     */
    private function lintSyntax(string $file): ?array
    {
        try {
            $process = new Process([PHP_BINARY, '-l', $file]);
            $process->setTimeout(self::PHP_LINT_TIMEOUT_SECONDS);
            $process->run();
        } catch (Throwable $e) {
            return [
                'level' => 'error',
                'file' => $file,
                'message' => 'Could not spawn php -l: '.$e->getMessage(),
            ];
        }

        if ($process->isSuccessful()) {
            return null;
        }

        $output = trim($process->getErrorOutput().' '.$process->getOutput());

        return [
            'level' => 'error',
            'file' => $file,
            'message' => 'PHP syntax error — '.$this->firstLine($output),
            'hint' => 'The generated file did not pass php -l. Most likely cause: a stub placeholder was not substituted, or a generated method body contains an unescaped quote.',
        ];
    }

    /**
     * Load + reflect on the generated Resource class to catch issues that
     * the parser misses: missing autoload, parent-class mismatches,
     * required-method absences.
     *
     * @return list<array<string, string>>
     */
    private function validateResourceClass(string $fqcn): array
    {
        $issues = [];

        if (! class_exists($fqcn)) {
            $issues[] = [
                'level' => 'error',
                'file' => '',
                'message' => "Generated class '{$fqcn}' could not be autoloaded.",
                'hint' => 'Check that the namespace declaration in the file matches the directory layout under app/MoonShine/ApiAdmin/Resources.',
            ];

            return $issues;
        }

        if (! is_subclass_of($fqcn, AbstractRestResource::class)) {
            $issues[] = [
                'level' => 'error',
                'file' => '',
                'message' => "Class '{$fqcn}' does not extend AbstractRestResource.",
                'hint' => 'Regenerate the resource — the main-resource stub must be used for top-level resources.',
            ];
        }

        try {
            $ref = new ReflectionClass($fqcn);

            foreach (['resolveServiceConfig', 'endpoint', 'pages'] as $required) {
                if (! $ref->hasMethod($required)) {
                    $issues[] = [
                        'level' => 'error',
                        'file' => '',
                        'message' => "Resource class '{$fqcn}' is missing required method {$required}().",
                        'hint' => 'AbstractRestResource expects this method — stubs must declare it. Regenerate.',
                    ];
                }
            }

            // Optional: verify $mutations shape if declared.
            if ($ref->hasProperty('mutations')) {
                $prop = $ref->getProperty('mutations');
                $value = $prop->getDefaultValue();
                if (! is_array($value)) {
                    $issues[] = [
                        'level' => 'warning',
                        'file' => '',
                        'message' => "Resource class '{$fqcn}' declares \$mutations but its value isn't an array.",
                        'hint' => 'Generator emitted an invalid mutations literal. Re-run create_resource/update_resource with a clean mutations spec.',
                    ];
                }
            }
        } catch (Throwable $e) {
            $issues[] = [
                'level' => 'error',
                'file' => '',
                'message' => "Reflection on '{$fqcn}' failed: {$e->getMessage()}",
            ];
        }

        return $issues;
    }

    /**
     * Detect any `{{ something }}` slot that survived stub substitution.
     * Returns null when the file is clean.
     *
     * @return array<string, string>|null
     */
    private function checkForUnreplacedPlaceholders(string $file): ?array
    {
        $content = @file_get_contents($file);
        if ($content === false) {
            return null;
        }

        if (preg_match_all('/\{\{\s*[a-zA-Z_]\w*\s*\}\}/', $content, $matches) < 1) {
            return null;
        }

        $unique = $matches[0]
            |> array_unique(...)
            |> array_values(...);
        $joined = implode(', ', $unique);

        return [
            'level' => 'error',
            'file' => $file,
            'message' => "Generated file still contains unreplaced stub placeholder(s): {$joined}",
            'hint' => 'A stub slot was added to the template but the running MCP process has an opcache-cached older ResourceGenerator that does not know about it. Reconnect the MCP server (kill the `php artisan api-admin:mcp-server` process and let the client respawn it) so Laravel rebootstraps with the current generator. If reconnecting is not enough, also run view:clear / route:clear to flush Laravel caches.',
        ];
    }

    private function firstLine(string $text): string
    {
        $text = trim($text);
        $nl = strpos($text, "\n");

        return $nl === false ? $text : substr($text, 0, $nl);
    }
}
