<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Grav;

use Grav\Plugin\RedirectManager\Domain\ConditionKind;
use Grav\Plugin\RedirectManager\Domain\MatchResult;
use Grav\Plugin\RedirectManager\Domain\StatusCode;
use Grav\Plugin\RedirectManager\Matching\LocationEncoder;

/**
 * Turns a MatchResult into the HTTP answer. Pure: no Grav, no PSR-7 (see ResponseData).
 *
 * Redirects carry Location, Cache-Control (permanent or temporary from config), X-Redirect-By and an
 * empty body, for HEAD too. Internal targets get the base path and, when keep_language_prefix is on,
 * the language prefix of the request, unless the target already starts with a known prefix or the rule
 * used {lang}. 410 and 451 bodies come from Twig (rendered by the plugin) and are passed in.
 */
final class RedirectResponder
{
    public const REDIRECT_BY = 'Grav Redirect Manager';

    public function __construct(private readonly RedirectResponderOptions $options = new RedirectResponderOptions())
    {
    }

    /**
     * The final Location value, encoded, with base path and language prefix applied to internal targets.
     */
    public function location(MatchResult $result, RequestContextResult $request): string
    {
        $location = $result->location;
        if ($location === '' || $result->isExternal()) {
            return LocationEncoder::encode($location);
        }

        $hash = '';
        $pos = strpos($location, '#');
        if ($pos !== false) {
            $hash = substr($location, $pos);
            $location = substr($location, 0, $pos);
        }
        $query = '';
        $pos = strpos($location, '?');
        if ($pos !== false) {
            $query = substr($location, $pos);
            $location = substr($location, 0, $pos);
        }
        if ($location === '') {
            $location = '/';
        }

        if ($this->shouldPrefix($result, $request, $location)) {
            $location = $location === '/' ? $request->languagePrefix : $request->languagePrefix . $location;
        }
        $location = $request->basePath . $location;
        if ($location === '') {
            $location = '/';
        }

        return LocationEncoder::encode($location . $query . $hash);
    }

    /**
     * 301, 302, 307 and 308 responses.
     */
    public function redirect(MatchResult $result, RequestContextResult $request): ResponseData
    {
        $headers = [
            'Location' => $this->location($result, $request),
            'Cache-Control' => $result->status->isPermanent()
                ? $this->options->cacheControlPermanent
                : $this->options->cacheControlTemporary,
            'X-Redirect-By' => self::REDIRECT_BY,
        ];
        $vary = self::vary($result);
        if ($vary !== '') {
            $headers['Vary'] = $vary;
        }

        return new ResponseData($result->status->value, $headers);
    }

    /**
     * 410 and 451 with the rendered HTML page. HEAD requests get the headers without the body.
     */
    public function error(MatchResult $result, RequestContextResult $request, string $html): ResponseData
    {
        $headers = [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => $result->status === StatusCode::Gone
                ? $this->options->cacheControlPermanent
                : $this->options->cacheControlTemporary,
            'X-Redirect-By' => self::REDIRECT_BY,
            'X-Robots-Tag' => 'noindex',
        ];
        $vary = self::vary($result);
        if ($vary !== '') {
            $headers['Vary'] = $vary;
        }

        return new ResponseData($result->status->value, $headers, $request->isHead() ? '' : $html);
    }

    /** Response for any redirect or error status; $html is only used for 410 and 451. */
    public function respond(MatchResult $result, RequestContextResult $request, string $html = ''): ResponseData
    {
        return $result->status->isError()
            ? $this->error($result, $request, $html)
            : $this->redirect($result, $request);
    }

    private function shouldPrefix(MatchResult $result, RequestContextResult $request, string $path): bool
    {
        if (!$this->options->keepLanguagePrefix || $request->languagePrefix === '' || $path[0] !== '/') {
            return false;
        }
        foreach ($result->rules as $rule) {
            if (str_contains($rule->target, '{lang}')) {
                return false;
            }
        }
        $first = strtolower(explode('/', ltrim($path, '/'), 2)[0]);

        return !in_array($first, $this->options->languages, true);
    }

    /** Responses that depend on headers, cookies or the host must not be shared between visitors. */
    private static function vary(MatchResult $result): string
    {
        $vary = [];
        foreach ($result->rules as $rule) {
            if ($rule->conditions->hosts !== []) {
                $vary['Host'] = true;
            }
            foreach ($rule->conditions->rules as $condition) {
                $name = $condition->kind === ConditionKind::Cookie ? 'Cookie' : self::headerCase($condition->name);
                if ($name !== '') {
                    $vary[$name] = true;
                }
            }
        }

        return implode(', ', array_keys($vary));
    }

    private static function headerCase(string $name): string
    {
        return implode('-', array_map(ucfirst(...), explode('-', strtolower(trim($name)))));
    }
}
