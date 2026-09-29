<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Grav;

use Grav\Plugin\RedirectManager\Domain\MatchResult;
use Grav\Plugin\RedirectManager\Domain\RequestContext;
use Grav\Plugin\RedirectManager\Domain\Rule;
use Grav\Plugin\RedirectManager\Domain\StatusCode;
use Grav\Plugin\RedirectManager\Grav\RedirectResponder;
use Grav\Plugin\RedirectManager\Grav\RedirectResponderOptions;
use Grav\Plugin\RedirectManager\Grav\RequestContextResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(RedirectResponder::class)]
#[CoversClass(RedirectResponderOptions::class)]
#[Group('grav')]
final class RedirectResponderTest extends TestCase
{
    private function matchOf(string $location, StatusCode $status = StatusCode::MovedPermanently, ?Rule $rule = null): MatchResult
    {
        $rule ??= new Rule('r1', '/old', $location, status: $status);

        return new MatchResult($rule, $status, $location, [$rule]);
    }

    private function request(string $prefix = '', string $base = '', string $method = 'GET'): RequestContextResult
    {
        return new RequestContextResult(new RequestContext('/old', language: $prefix === '' ? null : ltrim($prefix, '/')), $base, $prefix, $base . $prefix . '/old', '', $method);
    }

    private function responder(bool $keep = true): RedirectResponder
    {
        return new RedirectResponder(new RedirectResponderOptions($keep, 'public, max-age=3600', 'no-store', ['en', 'de']));
    }

    public function testRedirectHeaders(): void
    {
        $r = $this->responder()->redirect($this->matchOf('/new'), $this->request());
        self::assertSame(301, $r->status);
        self::assertSame('/new', $r->header('location'));
        self::assertSame('public, max-age=3600', $r->header('cache-control'));
        self::assertSame('Grav Redirect Manager', $r->header('x-redirect-by'));
        self::assertNull($r->header('set-cookie'));
        self::assertSame('', $r->body);
        self::assertNull($r->header('vary'));
    }

    /** @return iterable<string, array{StatusCode, string}> */
    public static function cacheByStatus(): iterable
    {
        yield '301' => [StatusCode::MovedPermanently, 'public, max-age=3600'];
        yield '308' => [StatusCode::PermanentRedirect, 'public, max-age=3600'];
        yield '302' => [StatusCode::Found, 'no-store'];
        yield '307' => [StatusCode::TemporaryRedirect, 'no-store'];
    }

    #[DataProvider('cacheByStatus')]
    public function testCacheControlFollowsPermanence(StatusCode $status, string $expected): void
    {
        $r = $this->responder()->redirect($this->matchOf('/new', $status), $this->request());
        self::assertSame($status->value, $r->status);
        self::assertSame($expected, $r->header('Cache-Control'));
    }

    public function testHeadGetsTheSameRedirect(): void
    {
        $get = $this->responder()->redirect($this->matchOf('/new'), $this->request());
        $head = $this->responder()->redirect($this->matchOf('/new'), $this->request(method: 'HEAD'));
        self::assertEquals($get, $head);
    }

    public function testLanguagePrefixIsKept(): void
    {
        $responder = $this->responder();
        self::assertSame('/de/neu', $responder->location($this->matchOf('/neu'), $this->request('/de')));
        self::assertSame('/de', $responder->location($this->matchOf('/'), $this->request('/de')));
        self::assertSame('/de/neu?a=1#x', $responder->location($this->matchOf('/neu?a=1#x'), $this->request('/de')));
    }

    public function testLanguagePrefixIsNotAddedTwiceOrWhenDisabled(): void
    {
        self::assertSame('/en/new', $this->responder()->location($this->matchOf('/en/new'), $this->request('/de')), 'target has its own prefix');
        self::assertSame('/EN', $this->responder()->location($this->matchOf('/EN'), $this->request('/de')));
        self::assertSame('/neu', $this->responder(false)->location($this->matchOf('/neu'), $this->request('/de')));
        self::assertSame('/dessert', $this->responder()->location($this->matchOf('/dessert'), $this->request('')), 'no prefix on the request');
        self::assertSame('/de/dessert', $this->responder()->location($this->matchOf('/dessert'), $this->request('/de')), 'only whole segments are prefixes');
    }

    public function testLangPlaceholderMeansTheRuleHandlesTheLanguage(): void
    {
        $rule = new Rule('r1', '/old', '/{lang}/neu');
        $result = new MatchResult($rule, StatusCode::MovedPermanently, '/de/neu', [$rule]);
        self::assertSame('/de/neu', $this->responder()->location($result, $this->request('/de')));

        $fallback = new MatchResult($rule, StatusCode::MovedPermanently, '/en/neu', [$rule]);
        self::assertSame('/en/neu', $this->responder()->location($fallback, $this->request('/de')));
    }

    public function testBasePathIsAddedToInternalTargetsOnly(): void
    {
        $responder = $this->responder();
        self::assertSame('/shop/neu', $responder->location($this->matchOf('/neu'), $this->request('', '/shop')));
        self::assertSame('/shop/de/neu', $responder->location($this->matchOf('/neu'), $this->request('/de', '/shop')));
        self::assertSame('/shop/', $responder->location($this->matchOf('/'), $this->request('', '/shop')));
        self::assertSame('https://example.org/x', $responder->location($this->matchOf('https://example.org/x'), $this->request('/de', '/shop')));
    }

    public function testLocationIsEncoded(): void
    {
        $responder = $this->responder();
        self::assertSame('/%C3%BCber%20uns', $responder->location($this->matchOf('/über uns'), $this->request()));
        self::assertSame('https://xn--bcher-kva.example/%C3%A4', $responder->location($this->matchOf('https://bücher.example/ä'), $this->request()));
        self::assertSame('/a%25b', $responder->location($this->matchOf('/a%b'), $this->request()));
    }

    public function testErrorPagesCarryTheHtmlAndNoIndexHeaders(): void
    {
        $gone = $this->responder()->error($this->matchOf('', StatusCode::Gone), $this->request(), '<h1>gone</h1>');
        self::assertSame(410, $gone->status);
        self::assertSame('<h1>gone</h1>', $gone->body);
        self::assertSame('text/html; charset=utf-8', $gone->header('content-type'));
        self::assertSame('public, max-age=3600', $gone->header('cache-control'));
        self::assertSame('noindex', $gone->header('x-robots-tag'));
        self::assertNull($gone->header('location'));

        $legal = $this->responder()->respond($this->matchOf('', StatusCode::UnavailableForLegalReasons), $this->request(), '<h1>legal</h1>');
        self::assertSame(451, $legal->status);
        self::assertSame('no-store', $legal->header('cache-control'));

        $head = $this->responder()->respond($this->matchOf('', StatusCode::Gone), $this->request(method: 'HEAD'), '<h1>gone</h1>');
        self::assertSame(410, $head->status);
        self::assertSame('', $head->body);
    }

    public function testRespondPicksRedirectOrError(): void
    {
        self::assertSame(302, $this->responder()->respond($this->matchOf('/x', StatusCode::Found), $this->request())->status);
        self::assertSame(410, $this->responder()->respond($this->matchOf('', StatusCode::Gone), $this->request(), 'x')->status);
    }

    public function testVaryFollowsConditions(): void
    {
        $rule = Rule::fromArray([
            'id' => 'c', 'source' => '/old', 'target' => '/new',
            'conditions' => [
                'hosts' => ['example.org'],
                'rules' => [
                    ['kind' => 'header', 'name' => 'user-agent', 'operator' => 'contains', 'value' => 'bot'],
                    ['kind' => 'cookie', 'name' => 'beta', 'operator' => 'exists'],
                    ['kind' => 'header', 'name' => 'accept-language', 'operator' => 'exists'],
                ],
            ],
        ]);
        $r = $this->responder()->redirect(new MatchResult($rule, StatusCode::MovedPermanently, '/new', [$rule]), $this->request());
        self::assertSame('Host, User-Agent, Cookie, Accept-Language', $r->header('Vary'));
    }
}
