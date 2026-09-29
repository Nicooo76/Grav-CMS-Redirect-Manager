<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Suggest;

use Normalizer;
use Transliterator;

/**
 * Text normalization for URL comparison: lowercase, transliteration to ASCII
 * (ä→ae, ö→oe, ü→ue, ß→ss, other accents dropped), tokenization on "-_./ " and camelCase.
 *
 * The umlaut/accent table always applies first so that results do not depend on the
 * installed extensions. ext-intl's Transliterator (Latin-ASCII) only handles characters
 * the table does not know. ext-iconv is deliberately not used: its //TRANSLIT output
 * depends on the process locale. Non-Latin scripts stay as UTF-8 and still tokenize.
 */
final class TextNormalizer
{
    private const TABLE = [
        'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', 'ẞ' => 'ss',
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'å' => 'a', 'ā' => 'a', 'ă' => 'a', 'ą' => 'a',
        'æ' => 'ae', 'ç' => 'c', 'ć' => 'c', 'č' => 'c', 'ĉ' => 'c', 'ď' => 'd', 'đ' => 'd', 'ð' => 'd',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ē' => 'e', 'ė' => 'e', 'ę' => 'e', 'ě' => 'e',
        'ğ' => 'g', 'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ī' => 'i', 'ı' => 'i', 'į' => 'i',
        'ł' => 'l', 'ľ' => 'l', 'ñ' => 'n', 'ń' => 'n', 'ň' => 'n',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ø' => 'o', 'ō' => 'o', 'ő' => 'o', 'œ' => 'oe',
        'ř' => 'r', 'ś' => 's', 'š' => 's', 'ş' => 's', 'ș' => 's', 'ť' => 't', 'ț' => 't', 'þ' => 'th',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ū' => 'u', 'ů' => 'u', 'ű' => 'u', 'ų' => 'u',
        'ý' => 'y', 'ÿ' => 'y', 'ź' => 'z', 'ż' => 'z', 'ž' => 'z',
    ];

    /** Words too common to count as evidence in title/taxonomy matching (en, de). */
    private const STOP_WORDS = [
        'the', 'an', 'and', 'or', 'of', 'to', 'in', 'on', 'for', 'with', 'is', 'at', 'by',
        'der', 'die', 'das', 'und', 'oder', 'ein', 'eine', 'von', 'zu', 'im', 'mit', 'html', 'htm', 'php',
    ];

    private ?Transliterator $transliterator = null;
    private bool $transliteratorLoaded = false;

    /** Replaces invalid UTF-8 sequences. */
    public function scrub(string $text): string
    {
        if ($text === '' || preg_match('//u', $text) === 1) {
            return $text;
        }

        return mb_scrub($text, 'UTF-8');
    }

    /** Lowercase, transliterated to ASCII where possible. */
    public function ascii(string $text): string
    {
        $text = $this->scrub($text);
        if ($text === '') {
            return '';
        }

        $text = mb_strtolower($text, 'UTF-8');
        if (!$this->hasNonAscii($text)) {
            return $text;
        }

        if (class_exists(Normalizer::class)) {
            $composed = Normalizer::normalize($text, Normalizer::FORM_C);
            if (is_string($composed)) {
                $text = $composed;
            }
        }

        $text = strtr($text, self::TABLE);

        if ($this->hasNonAscii($text)) {
            $transliterator = $this->transliterator();
            if ($transliterator !== null) {
                $converted = $transliterator->transliterate($text);
                if (is_string($converted)) {
                    $text = mb_strtolower($converted, 'UTF-8');
                }
            }
        }

        return $text;
    }

    /**
     * Splits camelCase and any run of non-alphanumerics, then normalizes each token.
     *
     * @return list<string>
     */
    public function tokens(string $text): array
    {
        $text = $this->scrub($text);
        if ($text === '') {
            return [];
        }

        $text = preg_replace('/(?<=[\p{Ll}\p{N}])(?=\p{Lu})/u', ' ', $text) ?? $text;
        $parts = preg_split('/[^\p{L}\p{N}]+/u', $this->ascii($text), -1, PREG_SPLIT_NO_EMPTY);

        return $parts === false ? [] : $parts;
    }

    /**
     * Unique tokens that carry meaning: no stop words, no single letters.
     *
     * @return list<string>
     */
    public function contentTokens(string $text): array
    {
        $out = [];
        foreach ($this->tokens($text) as $token) {
            if (strlen($token) < 2 || in_array($token, self::STOP_WORDS, true)) {
                continue;
            }
            $out[$token] = $token;
        }

        return array_values($out);
    }

    /** Tokens joined by "-": the comparable form of one path segment. */
    public function slug(string $text): string
    {
        return implode('-', $this->tokens($text));
    }

    /** Normalized route: every segment slugged, empty segments dropped. "/Über Uns/" becomes "/ueber-uns". */
    public function route(string $path): string
    {
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            $slug = $this->slug($segment);
            if ($slug !== '') {
                $segments[] = $slug;
            }
        }

        return '/' . implode('/', $segments);
    }

    /**
     * Unique trigrams of "^text$" as a set (keys). Very short strings yield themselves.
     * Numeric-looking trigrams become integer keys, which is harmless for set operations.
     *
     * @return array<int|string, true>
     */
    public function trigramSet(string $text): array
    {
        $padded = '^' . $text . '$';
        $length = strlen($padded);
        if ($length < 3) {
            return [$padded => true];
        }

        $set = [];
        for ($i = 0, $max = $length - 2; $i < $max; ++$i) {
            $set[substr($padded, $i, 3)] = true;
        }

        return $set;
    }

    private function hasNonAscii(string $text): bool
    {
        return preg_match('/[^\x00-\x7F]/', $text) === 1;
    }

    private function transliterator(): ?Transliterator
    {
        if (!$this->transliteratorLoaded) {
            $this->transliteratorLoaded = true;
            if (class_exists(Transliterator::class)) {
                $this->transliterator = Transliterator::create('Latin-ASCII');
            }
        }

        return $this->transliterator;
    }
}
