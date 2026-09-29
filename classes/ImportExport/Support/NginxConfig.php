<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\ImportExport\Support;

use Grav\Plugin\RedirectManager\ImportExport\ImportException;
use Grav\Plugin\RedirectManager\ImportExport\ImportIssue;

/** Minimal nginx config reader: tokens, quotes, comments, nested blocks. Nothing is evaluated. */
final class NginxConfig
{
    public const MAX_DEPTH = 32;

    /**
     * @return list<NginxNode>
     */
    public static function parse(string $content): array
    {
        $stack = [new NginxFrame('', [], 0)];
        $words = [];
        $line = 0;

        foreach (self::tokenize($content) as [$type, $value, $tokenLine]) {
            if ($type === 'word') {
                if ($words === []) {
                    $line = $tokenLine;
                }
                $words[] = $value;
                continue;
            }
            $name = (string) array_shift($words);
            if ($type === ';') {
                if ($name !== '') {
                    $stack[count($stack) - 1]->children[] = new NginxNode(strtolower($name), $words, $line);
                }
                $words = [];
                continue;
            }
            if ($type === '{') {
                if (count($stack) >= self::MAX_DEPTH) {
                    throw new ImportException(new ImportIssue('too_deep', 'The config is nested too deeply.', ['max' => self::MAX_DEPTH]));
                }
                $stack[] = new NginxFrame(strtolower($name), $words, $line);
                $words = [];
                continue;
            }
            // '}'
            if (count($stack) === 1) {
                throw new ImportException(new ImportIssue('invalid_nginx', 'Unbalanced "}" in the config.', ['line' => $tokenLine]));
            }
            $done = array_pop($stack);
            if ($done !== null) {
                $stack[count($stack) - 1]->children[] = $done->toNode();
            }
            $words = [];
        }
        if (count($stack) !== 1) {
            throw new ImportException(new ImportIssue('invalid_nginx', 'A block is not closed.'));
        }

        return $stack[0]->children;
    }

    /**
     * @return \Generator<int, array{0: string, 1: string, 2: int}> type (word, ;, {, }), value, line
     */
    private static function tokenize(string $content): \Generator
    {
        $length = strlen($content);
        $line = 1;
        $i = 0;
        while ($i < $length) {
            $c = $content[$i];
            if ($c === "\n") {
                ++$line;
                ++$i;
            } elseif (ctype_space($c)) {
                ++$i;
            } elseif ($c === '#') {
                while ($i < $length && $content[$i] !== "\n") {
                    ++$i;
                }
            } elseif ($c === ';' || $c === '{' || $c === '}') {
                yield [$c, $c, $line];
                ++$i;
            } elseif ($c === '"' || $c === "'") {
                $word = '';
                ++$i;
                while ($i < $length && $content[$i] !== $c) {
                    if ($content[$i] === '\\' && $i + 1 < $length && in_array($content[$i + 1], [$c, '\\'], true)) {
                        ++$i;
                    }
                    if ($content[$i] === "\n") {
                        ++$line;
                    }
                    $word .= $content[$i++];
                }
                ++$i;
                yield ['word', $word, $line];
            } else {
                $word = '';
                while ($i < $length && !ctype_space($content[$i]) && $content[$i] !== ';' && $content[$i] !== '}') {
                    if ($content[$i] === '{') {
                        if ($word !== '' && str_ends_with($word, '$')) {
                            $end = strpos($content, '}', $i);
                            if ($end !== false) {
                                $word .= substr($content, $i, $end - $i + 1);
                                $i = $end + 1;
                                continue;
                            }
                        }
                        break;
                    }
                    $word .= $content[$i++];
                }
                yield ['word', $word, $line];
            }
        }

    }
}
