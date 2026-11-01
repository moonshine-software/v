<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Unit;

use App\ApiAdmin\MCP\Generator\ResourceGenerator;
use App\ApiAdmin\MCP\Generator\ResourceValidator;
use Illuminate\Support\Facades\File;
use Orchestra\Testbench\TestCase;

/**
 * ResourceValidator runs immediately after every create_resource /
 * update_resource call. It has to catch both "silly typo in the stub"
 * mistakes (syntax) and "autoload layout drifted" mistakes (class can't
 * be loaded). Both are invisible until a user hits the panel in the
 * browser, so we surface them back to the agent inline.
 */
final class ResourceValidatorTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/moonshine-validator-'.uniqid('', true);
        config(['api-admin.resources_path' => $this->tmp]);
        File::ensureDirectoryExists($this->tmp);
    }

    protected function tearDown(): void
    {
        if (File::isDirectory($this->tmp)) {
            File::deleteDirectory($this->tmp);
        }

        parent::tearDown();
    }

    public function test_clean_generation_passes_validation(): void
    {
        $result = (new ResourceGenerator)->generate([
            'service' => 'validator_clean',
            'name' => 'post',
            'endpoint' => '/posts',
            'fields' => [
                ['column' => 'id', 'type' => 'id'],
                ['column' => 'title', 'type' => 'text', 'methods' => ['required']],
            ],
        ]);

        // The generated classes live outside the autoloader's directories.
        foreach (File::allFiles($this->tmp) as $file) {
            require_once $file->getPathname();
        }

        $validation = (new ResourceValidator)->validate($result);

        $this->assertTrue($validation['ok'], 'Expected validation to pass for clean output, got: '.json_encode($validation['issues']));
        $this->assertSame([], $validation['issues']);
        $this->assertGreaterThanOrEqual(4, $validation['files_checked']); // resource + 3 pages
    }

    public function test_syntax_error_in_generated_file_is_surfaced(): void
    {
        $result = (new ResourceGenerator)->generate([
            'service' => 'blog',
            'name' => 'post',
            'endpoint' => '/posts',
            'fields' => [['column' => 'id', 'type' => 'id']],
        ]);

        // Corrupt one page to simulate a stub placeholder that didn't
        // substitute (the most common cause of broken generator output).
        $formPath = $result['pages']['form'];
        file_put_contents(
            $formPath,
            str_replace('protected function fields(): iterable', 'protected function fields(): iterable {', File::get($formPath)),
        );

        $validation = (new ResourceValidator)->validate($result);

        $this->assertFalse($validation['ok']);
        $this->assertNotEmpty($validation['issues']);

        $syntaxIssue = array_filter(
            $validation['issues'],
            static fn (array $issue): bool => $issue['file'] === $formPath,
        )
            |> current(...);

        $this->assertNotFalse($syntaxIssue);
        $this->assertSame('error', $syntaxIssue['level']);
        $this->assertStringContainsString('syntax', strtolower($syntaxIssue['message']));
    }

    public function test_validation_skips_class_reflection_when_syntax_broken(): void
    {
        // When PHP can't parse a file, class_exists() would fatal out of
        // the worker instead of returning false. The validator must stop
        // at the syntax layer in that case.
        $result = (new ResourceGenerator)->generate([
            'service' => 'blog',
            'name' => 'post',
            'endpoint' => '/posts',
            'fields' => [['column' => 'id', 'type' => 'id']],
        ]);

        file_put_contents($result['file'], '<?php this is not php');

        $validation = (new ResourceValidator)->validate($result);

        $this->assertFalse($validation['ok']);
        // No "class could not be autoloaded" message — only the syntax
        // error, because we bail before trying reflection.
        foreach ($validation['issues'] as $issue) {
            $this->assertStringNotContainsString('could not be autoloaded', $issue['message']);
        }
    }
}
