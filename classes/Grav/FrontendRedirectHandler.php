<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Grav;

use Closure;
use Grav\Common\Grav;
use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Common\Page\Pages;
use Grav\Events\PageEvent;
use Grav\Plugin\RedirectManager\Domain\MatchPhase;
use Grav\Plugin\RedirectManager\Domain\MatchResult;
use Grav\Plugin\RedirectManager\Domain\StatusCode;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * The frontend request flow of the plugin: matches the request against the rules, sends 30x/410/451 answers,
 * hands pass-through matches to Grav and logs 404s. The plugin class only forwards its three events here.
 *
 * Anything that goes wrong is logged and Grav carries on: a broken rule file never takes the site down.
 */
final class FrontendRedirectHandler
{
    /** Request as seen at PluginsLoadedEvent; false = nothing to do for this request (excluded, invalid, disabled). */
    private RequestContextResult|false|null $request = null;

    /** A 410, 451 or pass-through match waiting for onPagesInitialized. */
    private ?MatchResult $pending = null;

    /**
     * @param Closure(): ServiceFactory $services
     * @param Closure(): RuleEvents     $events
     * @param array<string, mixed>      $server   $_SERVER of the request
     * @param bool                      $isCli    a command line run is never a frontend request
     */
    public function __construct(
        private readonly Grav $grav,
        private readonly Closure $services,
        private readonly Closure $events,
        private readonly array $server = [],
        private readonly bool $isCli = PHP_SAPI === 'cli',
    ) {
    }

    /**
     * Early phase: rules without "only if not found". 30x answers are sent from here (no session yet, so
     * no cookie and cacheable); 410, 451 and pass-through wait for onPagesInitialized.
     */
    public function onPluginsLoaded(): void
    {
        if ($this->isCli) {
            return;
        }
        // Only with REDIRECT_MANAGER_TIMING=1 or `debug_timing: true`: one clock read here, one below, no other cost.
        $timer = $this->timingEnabled() ? hrtime(true) : null;
        try {
            $services = ($this->services)();
            if (!$services->enabled()) {
                $this->request = false;

                return;
            }
            $request = $this->requestContext();
            if ($request === null) {
                return;
            }

            $result = $services->matcher()->match($request->context, MatchPhase::Early);
            if ($timer !== null) {
                $this->emitTiming($timer);
            }
            if ($result === null) {
                return;
            }
            $result = $this->dispatchMatched($result, $request);
            if ($result === null) {
                return;
            }
            if ($result->status->isRedirect()) {
                $this->send($result, $request, false);

                return;
            }
            $this->pending = $result;
        } catch (Throwable $e) {
            $this->logFailure('redirect matching', $e);
        }
    }

    /** Finishes 410, 451 and pass-through matches from the early phase. */
    public function onPagesInitialized(): void
    {
        $result = $this->pending;
        if ($result === null) {
            return;
        }
        $this->pending = null;
        try {
            $request = $this->requestContext();
            if ($request === null) {
                return;
            }
            if ($result->status->isError()) {
                // The session started before onPagesInitialized: strip its headers again.
                $this->send($result, $request, true);

                return;
            }
            $page = $this->findTargetPage($result);
            if ($page === null) {
                $this->warnNotRoutable($result, ' Falling through.');

                return;
            }
            $this->recordHits($result);
            unset($this->grav['page']);
            $this->grav['page'] = $page;
        } catch (Throwable $e) {
            $this->logFailure('redirect handling', $e);
        }
    }

    /** Logs the 404, then applies "only if not found" rules. */
    public function onPageNotFound(PageEvent $event): void
    {
        try {
            $services = ($this->services)();
            if (!$services->enabled()) {
                return;
            }
            $request = $this->requestContext();
            if ($request === null) {
                return;
            }

            $this->logNotFound($request);

            $result = $services->matcher()->match($request->context, MatchPhase::NotFound);
            if ($result === null) {
                return;
            }
            $result = $this->dispatchMatched($result, $request);
            if ($result === null) {
                return;
            }
            if ($result->status === StatusCode::PassThrough) {
                $page = $this->findTargetPage($result);
                if ($page === null) {
                    $this->warnNotRoutable($result, '');

                    return;
                }
                $this->recordHits($result);
                $event->page = $page;
                $event->stopPropagation();

                return;
            }
            // The session is running by now: strip what PHP added so the redirect stays cacheable.
            $this->send($result, $request, true);
        } catch (Throwable $e) {
            $this->logFailure('404 handling', $e);
        }
    }

    private function timingEnabled(): bool
    {
        $env = getenv('REDIRECT_MANAGER_TIMING');
        if (is_string($env) && $env !== '' && $env !== '0') {
            return true;
        }

        return (bool) $this->grav['config']->get('plugins.' . GravBootstrap::SLUG . '.debug_timing', false);
    }

    /**
     * X-Redirect-Manager-Time: microseconds the early phase took (request context, compiled rule cache, match).
     * Set before the response is built, so it is on redirects and on pages Grav serves afterwards.
     */
    private function emitTiming(int|float $startedAt): void
    {
        if (!headers_sent()) {
            header('X-Redirect-Manager-Time: ' . number_format((hrtime(true) - $startedAt) / 1000, 1, '.', ''));
        }
    }

    private function requestContext(): ?RequestContextResult
    {
        if ($this->request === null) {
            $factory = ($this->services)()->requestFactory();
            /** @var ServerRequestInterface $psr */
            $psr = $this->grav['request'];
            $request = $factory->fromRequest($psr, $this->server);
            $this->request = $request === null || $factory->isExcluded($request->context->path) ? false : $request;
        }

        return $this->request === false ? null : $this->request;
    }

    /**
     * onRedirectMatched: listeners may replace the result or cancel. Returns null when cancelled.
     */
    private function dispatchMatched(MatchResult $result, RequestContextResult $request): ?MatchResult
    {
        $payload = ($this->events)()->matched(['result' => $result, 'context' => $request->context, 'request' => $request]);
        if (!empty($payload['cancel'])) {
            return null;
        }

        return ($payload['result'] ?? null) instanceof MatchResult ? $payload['result'] : $result;
    }

    /**
     * Sends the redirect or error response and ends the request.
     */
    private function send(MatchResult $result, RequestContextResult $request, bool $sessionRunning): void
    {
        $this->recordHits($result);

        $html = '';
        if ($result->status->isError()) {
            $template = $result->status === StatusCode::Gone ? 'gone' : 'unavailable';
            $html = $this->grav['twig']->processTemplate('redirect-manager/' . $template . '.html.twig', [
                'redirect_status' => $result->status->value,
                'redirect_path' => $request->context->path,
            ]);
        }
        $response = ($this->services)()->responder()->respond($result, $request, $html);

        if ($sessionRunning && !headers_sent()) {
            foreach (['Set-Cookie', 'Expires', 'Pragma', 'Cache-Control'] as $name) {
                header_remove($name);
            }
        }
        $this->grav->close($response->toPsr7());
    }

    private function recordHits(MatchResult $result): void
    {
        try {
            $recorder = ($this->services)()->hitRecorder();
            foreach ($result->rules as $rule) {
                $recorder->record($rule->id);
            }
        } catch (Throwable $e) {
            $this->logFailure('hit recording', $e);
        }
    }

    private function logNotFound(RequestContextResult $request): void
    {
        try {
            $services = ($this->services)();
            $context = $request->context;
            $ip = (string) ($this->server['REMOTE_ADDR'] ?? '');
            if ($services->bool('security.trust_proxy_headers', false) && $context->header('x-forwarded-for') !== null) {
                $ip = trim(explode(',', (string) $context->header('x-forwarded-for'))[0]);
            }
            $entry = $services->notFoundLogger()->log(
                $context->path,
                $request->rawQuery,
                $context->header('referer'),
                $context->header('user-agent'),
                $ip !== '' ? $ip : null,
                $context->language,
                $context->host,
                $request->method,
            );
            if ($entry !== null) {
                ($this->events)()->notFoundLogged(['entry' => $entry]);
            }
        } catch (Throwable $e) {
            $this->logFailure('404 logging', $e);
        }
    }

    private function findTargetPage(MatchResult $result): ?PageInterface
    {
        $route = (string) preg_replace('/[?#].*$/', '', $result->location);
        if ($route === '' || $route[0] !== '/' || $result->isExternal()) {
            return null;
        }
        /** @var Pages $pages */
        $pages = $this->grav['pages'];
        $page = $pages->find(rawurldecode($route));

        return $page instanceof PageInterface && $page->routable() ? $page : null;
    }

    private function warnNotRoutable(MatchResult $result, string $suffix): void
    {
        $this->grav['log']->warning(sprintf(
            'Redirect Manager: pass-through rule %s points to "%s", which is not a routable page.%s',
            $result->rule->id,
            $result->location,
            $suffix,
        ));
    }

    private function logFailure(string $what, Throwable $e): void
    {
        try {
            $this->grav['log']->error(sprintf('Redirect Manager: %s failed: %s (%s:%d)', $what, $e->getMessage(), basename($e->getFile()), $e->getLine()));
        } catch (Throwable) {
            // nothing left to do
        }
    }
}
