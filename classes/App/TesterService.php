<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\App;

use Grav\Plugin\RedirectManager\App\Exception\InvalidInputException;
use Grav\Plugin\RedirectManager\Domain\MatchPhase;
use Grav\Plugin\RedirectManager\Domain\MatchResult;
use Grav\Plugin\RedirectManager\Domain\RequestContext;
use Grav\Plugin\RedirectManager\Domain\StatusCode;
use Grav\Plugin\RedirectManager\Domain\TraceStep;
use Grav\Plugin\RedirectManager\Grav\RequestContextResult;
use Grav\Plugin\RedirectManager\Grav\ServiceFactory;
use stdClass;

/**
 * The rule tester: "what happens to this URL?" without a real request.
 *
 * The URL (absolute or a path) goes through the same RequestContextFactory as a live request (base path, language
 * prefix, host, scheme), the compiled rules answer with the Matcher (phase "any" unless told otherwise), and the
 * redirect chain is followed the way a browser would: each Location becomes the next request until no rule matches,
 * a 410/451 or pass-through rule ends it, the target is external, a URL repeats or the chain depth is reached.
 * The final status is what the visitor ends up with: 200 when the last URL is a page, 404 when it is not, 410/451
 * from a rule. External targets are not requested: their final status is reported as 200 with `external: true`.
 */
final class TesterService
{
    public function __construct(
        private readonly ServiceFactory $services,
        private readonly SiteContext $site,
    ) {
    }

    /**
     * @param array<mixed> $body url (required), method, headers, cookies, language, phase (early|not_found|any)
     *
     * @return array<string, mixed> input, context, result, chain, final, trace, page_exists
     *
     * @throws InvalidInputException
     */
    public function test(array $body): array
    {
        $url = isset($body['url']) && is_string($body['url']) ? trim($body['url']) : '';
        if ($url === '') {
            throw new InvalidInputException('"url" is required.', field: 'url', errorCode: 'required');
        }
        $method = isset($body['method']) && is_string($body['method']) && $body['method'] !== '' ? strtoupper($body['method']) : 'GET';
        $phaseName = isset($body['phase']) && is_string($body['phase']) && $body['phase'] !== '' ? $body['phase'] : MatchPhase::Any->value;
        $phase = MatchPhase::tryFrom($phaseName);
        if ($phase === null) {
            throw new InvalidInputException('"phase" must be early, not_found or any.', field: 'phase');
        }
        $language = isset($body['language']) && is_string($body['language']) && trim($body['language']) !== '' ? strtolower(trim($body['language'])) : null;
        $headers = self::stringMap($body['headers'] ?? [], 'headers');
        $cookies = self::stringMap($body['cookies'] ?? [], 'cookies');

        [$path, $query, $host, $scheme] = $this->parseUrl($url);
        if ($host !== '' && !isset($headers['host']) && !isset($headers['Host'])) {
            $headers['host'] = $host;
        }

        $factory = $this->services->requestFactory();
        $request = $factory->create($path, $query, $headers, $cookies, $this->site->server, $method);
        if ($request === null) {
            throw new InvalidInputException('The URL path is not valid.', field: 'url', errorCode: 'invalid_url');
        }
        $request = $this->withOverrides($request, $scheme, $language);
        $excluded = $factory->isExcluded($request->context->path);

        $trace = [];
        $first = $excluded ? null : $this->services->matcher()->match($request->context, $phase, true, $trace);

        $context = [
            'path' => $request->context->path,
            'query' => $request->context->query === [] ? new stdClass() : $request->context->query,
            'host' => $request->context->host,
            'scheme' => $request->context->scheme,
            'language' => $request->context->language,
            'base_path' => $request->basePath,
            'language_prefix' => $request->languagePrefix,
            'excluded' => $excluded,
        ];

        [$chain, $final] = $excluded
            ? [[['url' => self::display($request), 'status' => $this->pageStatus($request), 'rule_id' => null, 'location' => null]], ['url' => self::display($request), 'status' => $this->pageStatus($request)]]
            : $this->follow($request, $first, $phase, $headers, $cookies, $method);

        return [
            'input' => ['url' => $url, 'method' => $method, 'phase' => $phase->value],
            'context' => $context,
            'result' => $first === null ? null : self::resultRow($first),
            'chain' => $chain,
            'final' => $final,
            'trace' => array_map(static fn (TraceStep $s): array => $s->toArray(), $trace ?? []),
            'page_exists' => $this->pageExists($request->context),
        ];
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, string> $cookies
     *
     * @return array{0: list<array{url: string, status: int, rule_id: string|null, location: string|null}>, 1: array<string, mixed>}
     */
    private function follow(RequestContextResult $request, ?MatchResult $first, MatchPhase $phase, array $headers, array $cookies, string $method): array
    {
        $factory = $this->services->requestFactory();
        $responder = $this->services->responder();
        $max = max(1, $this->services->matcherOptions()->maxChainDepth);

        $chain = [];
        $seen = [self::display($request) => true];
        $current = $request;
        $result = $first;

        for ($hop = 0; ; ++$hop) {
            $shown = self::display($current);
            if ($result === null) {
                $status = $this->pageStatus($current);
                $chain[] = ['url' => $shown, 'status' => $status, 'rule_id' => null, 'location' => null];

                return [$chain, ['url' => $shown, 'status' => $status]];
            }
            $location = $result->status->isError() || $result->status === StatusCode::PassThrough ? null : $responder->location($result, $current);
            $chain[] = ['url' => $shown, 'status' => $result->status->value, 'rule_id' => $result->rule->id, 'location' => $location];

            if ($result->status->isError()) {
                return [$chain, ['url' => $shown, 'status' => $result->status->value]];
            }
            if ($result->status === StatusCode::PassThrough || $location === null) {
                $target = $factory->create($result->location, '', $headers, $cookies, $this->site->server, $method);
                $status = $target !== null && $this->pageExists($target->context) ? 200 : 404;

                return [$chain, ['url' => $shown, 'status' => $status]];
            }
            if ($result->isExternal()) {
                return [$chain, ['url' => $location, 'status' => 200, 'external' => true]];
            }

            $parts = explode('?', explode('#', $location, 2)[0], 2);
            $next = $factory->create($parts[0], $parts[1] ?? '', $headers, $cookies, $this->site->server, $method);
            if ($next === null || $factory->isExcluded($next->context->path)) {
                return [$chain, ['url' => $location, 'status' => 200, 'excluded' => true]];
            }
            $next = $this->withOverrides($next, $request->context->scheme, null);
            $key = self::display($next);
            if (isset($seen[$key])) {
                return [$chain, ['url' => $key, 'status' => $result->status->value, 'loop' => true]];
            }
            $seen[$key] = true;
            if ($hop + 1 >= $max) {
                return [$chain, ['url' => $key, 'status' => $this->pageStatus($next), 'truncated' => true]];
            }
            $current = $next;
            $result = $this->services->matcher()->match($current->context, $phase);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function resultRow(MatchResult $result): array
    {
        $row = $result->toArray();
        if ($row['captures'] === []) {
            $row['captures'] = new stdClass();
        }

        return $row;
    }

    private function pageStatus(RequestContextResult $request): int
    {
        return $this->pageExists($request->context) ? 200 : 404;
    }

    private function pageExists(RequestContext $context): bool
    {
        $index = $this->services->pageIndex();
        $route = rtrim($context->path, '/') === '' ? '/' : rtrim($context->path, '/');

        return $index->byRoute($route, $context->language) !== null || $index->byRoute($route) !== null;
    }

    /** The request as a visitor's URL: base path, language prefix, path and query. */
    private static function display(RequestContextResult $request): string
    {
        $path = $request->basePath . $request->languagePrefix . ($request->context->path === '/' && $request->languagePrefix !== '' ? '' : $request->context->path);
        if ($path === '') {
            $path = '/';
        }
        $query = $request->context->queryString();

        return $path . ($query !== '' ? '?' . $query : '');
    }

    private function withOverrides(RequestContextResult $request, ?string $scheme, ?string $language): RequestContextResult
    {
        $c = $request->context;
        $context = new RequestContext(
            $c->path,
            $c->query,
            $c->host,
            $scheme ?? $c->scheme,
            $c->language ?? $language,
            $c->headers,
            $c->cookies,
        );

        return new RequestContextResult($context, $request->basePath, $request->languagePrefix, $request->rawPath, $request->rawQuery, $request->method);
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string|null} path, query, host, scheme
     *
     * @throws InvalidInputException
     */
    private function parseUrl(string $url): array
    {
        if (preg_match('/[\x00-\x1f\x7f]/', $url) === 1) {
            throw new InvalidInputException('The URL contains control characters.', field: 'url', errorCode: 'invalid_url');
        }
        if (str_starts_with($url, '//')) {
            $url = 'https:' . $url;
        }
        $parts = parse_url($url);
        if ($parts === false) {
            throw new InvalidInputException('The URL cannot be parsed.', field: 'url', errorCode: 'invalid_url');
        }
        $scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : null;
        if ($scheme !== null && $scheme !== 'http' && $scheme !== 'https') {
            if (isset($parts['host'])) {
                throw new InvalidInputException('Only http and https URLs can be tested.', field: 'url', errorCode: 'invalid_url');
            }
            // "path:with:colon" is a path, not a scheme.
            $parts = parse_url('/' . ltrim($url, '/')) ?: [];
            $scheme = null;
        }
        $path = $parts['path'] ?? '';
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . $path;
        }

        return [$path, $parts['query'] ?? '', strtolower($parts['host'] ?? ''), $scheme];
    }

    /**
     * @return array<string, string>
     */
    private static function stringMap(mixed $value, string $field): array
    {
        if ($value === null || $value === []) {
            return [];
        }
        if (!is_array($value)) {
            throw new InvalidInputException(sprintf('"%s" must be a map of strings.', $field), field: $field, errorCode: 'invalid_type');
        }
        $out = [];
        foreach ($value as $name => $item) {
            if (!is_scalar($item)) {
                throw new InvalidInputException(sprintf('"%s" must be a map of strings.', $field), field: $field, errorCode: 'invalid_type');
            }
            $out[(string) $name] = (string) $item;
        }

        return $out;
    }
}
