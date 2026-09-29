<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Suggest;

use XMLReader;

/**
 * Reads sitemap XML safely and finds old URLs that neither exist nor are redirected.
 *
 * Safety: the input size is checked before anything else; gzip input (magic bytes 1f 8b) is inflated in
 * chunks and aborted once the decoded size exceeds the limit (zip bomb); the XML is streamed with XMLReader,
 * LIBXML_NONET, without entity substitution, and any DOCTYPE is rejected outright (no XXE, no billion laughs):
 * a cheap text scan rejects it before parsing, and the reader's DOC_TYPE node is checked as a second line.
 * Failures throw SitemapException.
 *
 * Only <loc> elements that are direct children of <url> (URL set) or <sitemap> (index) count, so extension
 * elements such as <image:loc> are ignored. Namespaces (default, prefixed, legacy 0.84 or none) do not matter.
 *
 * URLs become paths: scheme and host are cut off, the query string and fragment are dropped (redirect
 * sources are matched on the path; ?id=1 style sitemaps therefore collapse), the path is percent-decoded and
 * duplicate slashes are collapsed. A trailing slash is kept. Non-http(s) URLs and values with whitespace
 * or control characters are skipped.
 */
final class SitemapDiff
{
    public const DEFAULT_MAX_BYTES = 20 * 1024 * 1024;

    private const MAX_URL_LENGTH = 8192;
    private const CHUNK = 8192;

    /**
     * Paths of the <url><loc> entries of a URL set (empty for a sitemap index).
     *
     * @return list<string>
     */
    public function parse(string $xml, int $maxBytes = self::DEFAULT_MAX_BYTES): array
    {
        $document = $this->read($xml, $maxBytes);
        if ($document->isIndex) {
            return [];
        }

        $paths = [];
        foreach ($document->locations as $location) {
            $path = $this->toPath($location);
            if ($path !== null) {
                $paths[$path] = $path;
            }
        }

        return array_values($paths);
    }

    /**
     * Full URLs of the nested sitemaps of a sitemap index (empty for a URL set).
     *
     * @return list<string>
     */
    public function parseIndex(string $xml, int $maxBytes = self::DEFAULT_MAX_BYTES): array
    {
        $document = $this->read($xml, $maxBytes);

        return $document->isIndex ? $document->locations : [];
    }

    /** Reads either kind of sitemap: raw <loc> values and whether it is an index. */
    public function read(string $xml, int $maxBytes = self::DEFAULT_MAX_BYTES): SitemapDocument
    {
        $xml = $this->decode($xml, $maxBytes);
        if (str_starts_with($xml, "\xEF\xBB\xBF")) {
            $xml = substr($xml, 3);
        }
        $xml = ltrim($xml, " \t\r\n");
        if ($xml === '') {
            throw new SitemapException('The sitemap is empty.');
        }
        if (stripos($xml, '<!DOCTYPE') !== false) {
            throw new SitemapException('Sitemaps with a DOCTYPE are rejected.');
        }

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            return $this->stream($xml);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * Converts a URL to a path as described in the class docblock. Null when it cannot be a page URL.
     */
    public function toPath(string $url): ?string
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > self::MAX_URL_LENGTH || preg_match('/[\s\x00-\x1F\x7F]/', $url) === 1) {
            return null;
        }

        if (preg_match('#^([a-z][a-z0-9+.-]*):#i', $url, $m) === 1) {
            if (!in_array(strtolower($m[1]), ['http', 'https'], true)) {
                return null;
            }
            $url = (string) preg_replace('#^[a-z][a-z0-9+.-]*:#i', '', $url);
        }
        if (str_starts_with($url, '//')) {
            $slash = strcspn($url, '/?#', 2);
            $url = substr($url, 2 + $slash);
        }

        $end = strcspn($url, '?#');
        $path = substr($url, 0, $end);
        $path = rawurldecode($path);
        if (preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            return null;
        }
        if (preg_match('//u', $path) !== 1) {
            $path = mb_scrub($path, 'UTF-8');
        }
        $path = preg_replace('#/+#', '/', '/' . $path) ?? $path;

        return $path;
    }

    /**
     * Compares old sitemap paths with the current pages.
     *
     * A path exists when, after removing a leading language segment, it is the route of a target page in any
     * language (exact match, trailing slash ignored). Existing paths are never reported as redirected.
     *
     * @param list<string>                            $oldPaths
     * @param callable(string, string|null): bool     $isRedirected called with the route as rules see it
     *                                                (no language prefix) and the language taken from the prefix
     * @param list<string>|null                       $languages language codes; null uses the index's languages
     */
    public function diff(array $oldPaths, PageIndex $index, callable $isRedirected, ?array $languages = null): SitemapDiffResult
    {
        $known = [];
        foreach ($languages ?? $index->languages() as $language) {
            $known[strtolower($language)] = $language;
        }

        $seen = [];
        $existing = 0;
        $redirected = 0;
        $missing = [];
        foreach ($oldPaths as $oldPath) {
            $path = $this->normalizeForDiff($oldPath);
            if (isset($seen[$path])) {
                continue;
            }
            $seen[$path] = true;

            $language = null;
            $route = $path;
            $segments = explode('/', ltrim($path, '/'));
            if (isset($known[strtolower($segments[0])]) && $index->byRoute($path) === null) {
                $language = $known[strtolower($segments[0])];
                $route = '/' . implode('/', array_slice($segments, 1));
                $route = $route === '/' ? '/' : rtrim($route, '/');
            }

            if ($index->byRoute($route) !== null) {
                ++$existing;
            } elseif ($isRedirected($route, $language)) {
                ++$redirected;
            } else {
                $missing[] = $path;
            }
        }

        return new SitemapDiffResult(count($seen), $existing, $redirected, count($missing), $missing);
    }

    private function normalizeForDiff(string $path): string
    {
        $path = preg_replace('/[?#].*$/s', '', $path) ?? $path;
        $path = preg_replace('#/+#', '/', '/' . $path) ?? $path;

        return $path === '/' ? '/' : rtrim($path, '/');
    }

    private function decode(string $data, int $maxBytes): string
    {
        if (strlen($data) > $maxBytes) {
            throw new SitemapException(sprintf('The sitemap is larger than %d bytes.', $maxBytes));
        }
        if (strncmp($data, "\x1f\x8b", 2) !== 0) {
            return $data;
        }

        $error = null;
        set_error_handler(static function (int $no, string $message) use (&$error): bool {
            $error = $message;

            return true;
        });
        try {
            $context = inflate_init(ZLIB_ENCODING_GZIP);
            if ($context === false) {
                throw new SitemapException('Cannot decompress the sitemap.');
            }

            $out = '';
            $length = strlen($data);
            for ($offset = 0; $offset < $length; $offset += self::CHUNK) {
                $piece = inflate_add($context, substr($data, $offset, self::CHUNK), ZLIB_NO_FLUSH);
                if ($piece === false) {
                    throw new SitemapException('The sitemap is not valid gzip data' . ($error !== null ? ': ' . $error : '.'));
                }
                $out .= $piece;
                if (strlen($out) > $maxBytes) {
                    throw new SitemapException(sprintf('The decompressed sitemap is larger than %d bytes.', $maxBytes));
                }
            }
            $piece = inflate_add($context, '', ZLIB_FINISH);
            if ($piece === false) {
                throw new SitemapException('The sitemap is not valid gzip data' . ($error !== null ? ': ' . $error : '.'));
            }
            $out .= $piece;
            if (strlen($out) > $maxBytes) {
                throw new SitemapException(sprintf('The decompressed sitemap is larger than %d bytes.', $maxBytes));
            }

            return $out;
        } finally {
            restore_error_handler();
        }
    }

    private function stream(string $xml): SitemapDocument
    {
        $reader = new XMLReader();
        if (!$reader->XML($xml, null, LIBXML_NONET)) {
            throw new SitemapException('Malformed sitemap XML: ' . $this->lastError());
        }

        try {
            /** @var array<int, string> $names local name per depth */
            $names = [];
            /** @var array<int, string> $namespaces */
            $namespaces = [];
            $isIndex = null;
            $locations = [];

            while ($reader->read()) {
                if ($reader->nodeType === XMLReader::DOC_TYPE) {
                    throw new SitemapException('Sitemaps with a DOCTYPE are rejected.');
                }
                if ($reader->nodeType !== XMLReader::ELEMENT) {
                    continue;
                }

                $depth = $reader->depth;
                $name = $reader->localName;
                $names[$depth] = $name;
                $namespaces[$depth] = $reader->namespaceURI;

                if ($depth === 0) {
                    if ($name !== 'urlset' && $name !== 'sitemapindex') {
                        throw new SitemapException('Not a sitemap: root element is <' . $name . '>.');
                    }
                    $isIndex = $name === 'sitemapindex';
                    continue;
                }

                if (
                    $name === 'loc'
                    && $depth === 2
                    && $names[1] === ($isIndex === true ? 'sitemap' : 'url')
                    && $namespaces[1] === $namespaces[2]
                    && !$reader->isEmptyElement
                ) {
                    $value = trim($reader->readString());
                    if ($value !== '') {
                        $locations[$value] = $value;
                    }
                }
            }

            if ($this->hasFatalError()) {
                throw new SitemapException('Malformed sitemap XML: ' . $this->lastError());
            }
            if ($isIndex === null) {
                throw new SitemapException('Not a sitemap: no root element.');
            }

            return new SitemapDocument($isIndex, array_values($locations));
        } finally {
            $reader->close();
        }
    }

    private function hasFatalError(): bool
    {
        foreach (libxml_get_errors() as $error) {
            if ($error->level >= LIBXML_ERR_ERROR) {
                return true;
            }
        }

        return false;
    }

    private function lastError(): string
    {
        $error = libxml_get_last_error();

        return $error === false ? 'unknown error' : trim($error->message);
    }
}
