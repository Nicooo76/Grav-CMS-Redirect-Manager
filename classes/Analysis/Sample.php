<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Analysis;

/**
 * A concrete request path derived from a rule source, plus the stand-in values used for its captures.
 *
 * $tokens maps each stand-in value to the placeholder that produces it ("sample" => "$1", "9002" => "{id}"),
 * so a location found by the chain analysis can be turned back into a target template with templatize().
 * $templatable is false when a capture could not be given a unique stand-in.
 */
final readonly class Sample
{
    /**
     * @param array<string, mixed>                          $query
     * @param list<array{token: string, placeholder: string}> $tokens
     */
    public function __construct(
        public string $path,
        public array $query = [],
        public array $tokens = [],
        public bool $templatable = true,
    ) {
    }

    /**
     * Replaces the stand-in values in $location with their placeholders.
     */
    public function templatize(string $location): string
    {
        if ($this->tokens === []) {
            return $location;
        }
        $map = [];
        foreach ($this->tokens as $entry) {
            $map[$entry['token']] = $entry['placeholder'];
        }
        $tokens = array_keys($map);
        usort($tokens, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        $pattern = '/(' . implode('|', array_map(static fn (string $t): string => preg_quote($t, '/'), $tokens)) . ')(\d?)/';

        return (string) preg_replace_callback(
            $pattern,
            static function (array $m) use ($map): string {
                $placeholder = $map[$m[1]] ?? $m[1];
                $digit = $m[2] ?? '';
                if ($digit !== '' && preg_match('/^\$(\d+)$/', $placeholder, $n) === 1) {
                    $placeholder = '${' . $n[1] . '}';
                }

                return $placeholder . $digit;
            },
            $location,
        );
    }
}
