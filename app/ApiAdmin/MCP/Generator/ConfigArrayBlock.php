<?php

declare(strict_types=1);

namespace App\ApiAdmin\MCP\Generator;

use ParseError;
use PhpToken;
use RuntimeException;

/**
 * Edits one top-level array of a PHP config file through its tokens, so entries
 * containing brackets stay intact. Invalid PHP is rejected and never returned:
 * a broken config file stops the whole application, including the MCP server.
 */
final class ConfigArrayBlock
{
    /**
     * Adds or replaces `$entry` under `$entryKey`, or removes the key when `$entry` is null.
     *
     * @param string|null $entry PHP source of one array element, without a trailing comma
     */
    public static function rewrite(string $source, string $blockKey, string $entryKey, ?string $entry): string
    {
        $tokens = self::parse($source, 'The config file contains invalid PHP');
        $root = self::rootArray($tokens);
        $rootClose = self::closing($tokens, $root);
        $open = self::blockArray($tokens, $root, $rootClose, $blockKey);

        if ($open === null) {
            return $entry === null ? $source : self::appendBlock($tokens, $root, $rootClose, $blockKey, $entry);
        }

        $close = self::closing($tokens, $open);
        $elements = self::replaceElement(self::elements($tokens, $open, $close), $entryKey, $entry);
        $updated = self::text($tokens, 0, $open).self::render($elements).self::text($tokens, $close + 1, count($tokens));

        self::parse($updated, 'The config update would produce invalid PHP');

        return $updated;
    }

    /**
     * @return list<PhpToken>
     */
    private static function parse(string $source, string $message): array
    {
        try {
            return array_values(PhpToken::tokenize($source, TOKEN_PARSE));
        } catch (ParseError $e) {
            throw new RuntimeException($message.': '.$e->getMessage(), $e->getCode(), previous: $e);
        }
    }

    /**
     * @param list<PhpToken> $tokens
     */
    private static function rootArray(array $tokens): int
    {
        foreach ($tokens as $index => $token) {
            if ($token->is(T_RETURN)) {
                $open = self::nextCode($tokens, $index);
                if ($open !== null && $tokens[$open]->is('[')) {
                    return $open;
                }
            }
        }

        throw new RuntimeException('The config file must return a short array');
    }

    /**
     * @param list<PhpToken> $tokens
     */
    private static function blockArray(array $tokens, int $root, int $rootClose, string $blockKey): ?int
    {
        for ($index = $root + 1; $index < $rootClose; $index++) {
            $token = $tokens[$index];
            if (self::opens($token)) {
                $index = self::closing($tokens, $index);

                continue;
            }

            if (! $token->is(T_CONSTANT_ENCAPSED_STRING) || self::unquote($token) !== $blockKey) {
                continue;
            }

            $arrow = self::nextCode($tokens, $index);
            $open = $arrow === null ? null : self::nextCode($tokens, $arrow);
            if ($arrow !== null && $open !== null && $tokens[$arrow]->is(T_DOUBLE_ARROW) && $tokens[$open]->is('[')) {
                return $open;
            }
        }

        return null;
    }

    /**
     * @param list<PhpToken> $tokens
     *
     * @return list<array{key: string|null, text: string, code: bool}>
     */
    private static function elements(array $tokens, int $open, int $close): array
    {
        $elements = [];
        $text = '';
        $key = null;
        $code = false;

        for ($index = $open + 1; $index < $close; $index++) {
            $token = $tokens[$index];

            if ($token->is(',')) {
                $elements[] = ['key' => $key, 'text' => trim($text), 'code' => $code];
                $text = '';
                $key = null;
                $code = false;

                continue;
            }

            if (! $code && $token->is(T_CONSTANT_ENCAPSED_STRING)) {
                $key = self::unquote($token);
            }

            $code = $code || ! $token->isIgnorable();

            if (self::opens($token)) {
                $end = self::closing($tokens, $index);
                $text .= self::text($tokens, $index, $end + 1);
                $index = $end;

                continue;
            }

            $text .= $token->text;
        }

        if (trim($text) !== '') {
            $elements[] = ['key' => $key, 'text' => trim($text), 'code' => $code];
        }

        return $elements;
    }

    /**
     * @param list<array{key: string|null, text: string, code: bool}> $elements
     *
     * @return list<array{key: string|null, text: string, code: bool}>
     */
    private static function replaceElement(array $elements, string $entryKey, ?string $entry): array
    {
        $result = [];
        $replaced = false;

        foreach ($elements as $element) {
            if ($element['key'] !== $entryKey) {
                $result[] = $element;
            } elseif ($entry !== null && ! $replaced) {
                $result[] = ['key' => $entryKey, 'text' => $entry, 'code' => true];
                $replaced = true;
            }
        }

        if ($entry !== null && ! $replaced) {
            $result[] = ['key' => $entryKey, 'text' => $entry, 'code' => true];
        }

        return $result;
    }

    /**
     * @param list<array{key: string|null, text: string, code: bool}> $elements
     */
    private static function render(array $elements): string
    {
        if ($elements === []) {
            return '[]';
        }

        $lines = array_map(
            static fn (array $element): string => '        '.$element['text'].($element['code'] ? ',' : ''),
            $elements,
        );

        return "[\n".implode("\n", $lines)."\n    ]";
    }

    /**
     * @param list<PhpToken> $tokens
     */
    private static function appendBlock(array $tokens, int $root, int $rootClose, string $blockKey, string $entry): string
    {
        $last = $rootClose - 1;
        while ($last > $root && $tokens[$last]->isIgnorable()) {
            $last--;
        }

        $separator = $last === $root || $tokens[$last]->is(',') ? '' : ',';
        $block = '    '.var_export($blockKey, true)." => [\n        {$entry},\n    ],\n";
        $updated = rtrim(self::text($tokens, 0, $rootClose)).$separator."\n".$block.self::text($tokens, $rootClose, count($tokens));

        self::parse($updated, 'The config update would produce invalid PHP');

        return $updated;
    }

    /**
     * @param list<PhpToken> $tokens
     */
    private static function closing(array $tokens, int $open): int
    {
        $depth = 0;
        $count = count($tokens);

        for ($index = $open; $index < $count; $index++) {
            if (self::opens($tokens[$index])) {
                $depth++;
            } elseif ($tokens[$index]->is([']', ')', '}'])) {
                $depth--;
                if ($depth === 0) {
                    return $index;
                }
            }
        }

        throw new RuntimeException('The config file has an unbalanced array');
    }

    private static function opens(PhpToken $token): bool
    {
        return $token->is(['[', '(', '{', T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES, T_ATTRIBUTE]);
    }

    /**
     * @param list<PhpToken> $tokens
     */
    private static function nextCode(array $tokens, int $index): ?int
    {
        $count = count($tokens);
        for ($next = $index + 1; $next < $count; $next++) {
            if (! $tokens[$next]->isIgnorable()) {
                return $next;
            }
        }

        return null;
    }

    private static function unquote(PhpToken $token): string
    {
        return stripslashes(substr($token->text, 1, -1));
    }

    /**
     * @param list<PhpToken> $tokens
     */
    private static function text(array $tokens, int $from, int $to): string
    {
        $text = '';
        for ($index = $from; $index < $to; $index++) {
            $text .= $tokens[$index]->text;
        }

        return $text;
    }
}
