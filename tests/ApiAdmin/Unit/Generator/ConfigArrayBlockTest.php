<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Unit\Generator;

use App\ApiAdmin\MCP\Generator\ConfigArrayBlock;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ConfigArrayBlockTest extends TestCase
{
    private const string EMPTY_CONFIG = <<<'PHP'
<?php

return [
    'nested' => ['custom_fields' => ['untouched' => 1]],
    'custom_fields' => [],
    'custom_components' => [],
];

PHP;

    public function test_entries_with_brackets_survive_every_later_change(): void
    {
        $first = "'first' => ['class' => \\App\\First::class, 'alias' => 'First']";
        $second = "'second' => ['class' => \\App\\Second::class, 'alias' => 'Second']";
        $source = ConfigArrayBlock::rewrite(self::EMPTY_CONFIG, 'custom_fields', 'first', $first);
        $source = ConfigArrayBlock::rewrite($source, 'custom_fields', 'second', $second);

        $config = $this->evaluate($source);
        $this->assertSame(['first', 'second'], array_keys($this->block($config, 'custom_fields')));
        $this->assertSame([], $config['custom_components']);
        $this->assertSame(['custom_fields' => ['untouched' => 1]], $config['nested']);

        $updated = ConfigArrayBlock::rewrite($source, 'custom_fields', 'first', "'first' => ['alias' => 'Changed']");
        $this->assertSame(
            ['first' => ['alias' => 'Changed'], 'second' => ['class' => 'App\\Second', 'alias' => 'Second']],
            $this->evaluate($updated)['custom_fields'],
        );

        $removed = ConfigArrayBlock::rewrite($updated, 'custom_fields', 'first', null);
        $this->assertSame(['second'], array_keys($this->block($this->evaluate($removed), 'custom_fields')));
    }

    public function test_removing_the_last_entry_restores_the_original_file(): void
    {
        $added = ConfigArrayBlock::rewrite(self::EMPTY_CONFIG, 'custom_fields', 'only', "'only' => ['alias' => 'x']");

        $this->assertSame(self::EMPTY_CONFIG, ConfigArrayBlock::rewrite($added, 'custom_fields', 'only', null));
    }

    public function test_removing_an_unknown_entry_changes_nothing(): void
    {
        $source = <<<'PHP'
<?php

return [
    'custom_fields' => [
        'kept' => ['alias' => 'x'],
    ],
];

PHP;

        $this->assertSame($source, ConfigArrayBlock::rewrite($source, 'custom_fields', 'absent', null));
        $this->assertSame($source, ConfigArrayBlock::rewrite($source, 'custom_components', 'absent', null));
    }

    public function test_multiline_entries_comments_and_bracket_strings_are_preserved(): void
    {
        $source = <<<'PHP'
<?php

return [
    'custom_fields' => [
        // Keep this note.
        'multi' => [
            'alias' => 'with ] bracket, and comma',
            'list' => [1, 2],
        ],
    ],
];

PHP;

        $updated = ConfigArrayBlock::rewrite($source, 'custom_fields', 'next', "'next' => ['alias' => 'n']");

        $this->assertStringContainsString('// Keep this note.', $updated);
        $this->assertSame(
            ['multi' => ['alias' => 'with ] bracket, and comma', 'list' => [1, 2]], 'next' => ['alias' => 'n']],
            $this->evaluate($updated)['custom_fields'],
        );
    }

    public function test_missing_block_is_added_with_or_without_a_trailing_comma(): void
    {
        foreach (["<?php\n\nreturn [\n    'a' => 1,\n];\n", "<?php\n\nreturn [\n    'a' => 1\n];\n", "<?php\n\nreturn [];\n"] as $source) {
            $config = $this->evaluate(ConfigArrayBlock::rewrite($source, 'custom_fields', 'new', "'new' => \\App\\New1::class"));

            $this->assertSame(['new' => 'App\\New1'], $config['custom_fields']);
        }
    }

    public function test_invalid_source_and_invalid_entries_are_rejected(): void
    {
        $broken = "<?php\n\nreturn [\n    'custom_fields' => [\n    ],\n    ],\n];\n";

        try {
            ConfigArrayBlock::rewrite($broken, 'custom_fields', 'x', "'x' => 1");
            $this->fail('A broken config must not be rewritten.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('invalid PHP', $e->getMessage());
        }

        $this->expectException(RuntimeException::class);
        ConfigArrayBlock::rewrite(self::EMPTY_CONFIG, 'custom_fields', 'x', "'x' => ['unterminated'");
    }

    /**
     * @return array<string, mixed>
     */
    private function evaluate(string $source): array
    {
        $path = tempnam(sys_get_temp_dir(), 'moonshine-config-');
        $this->assertIsString($path);

        try {
            file_put_contents($path, $source);
            $config = include $path;
        } finally {
            unlink($path);
        }

        $this->assertIsArray($config);

        /** @var array<string, mixed> $config */
        return $config;
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<array-key, mixed>
     */
    private function block(array $config, string $key): array
    {
        $this->assertIsArray($config[$key]);

        return $config[$key];
    }
}
