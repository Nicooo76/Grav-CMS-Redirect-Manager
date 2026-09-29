<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Security;

use Grav\Plugin\RedirectManager\Domain\TargetType;
use Grav\Plugin\RedirectManager\Security\TargetGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(TargetGuard::class)]
final class TargetGuardTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: TargetType, 2: string|null}>
     */
    public static function internalCases(): iterable
    {
        foreach ([TargetType::Route, TargetType::Page] as $type) {
            $t = $type->value;
            yield "$t: plain path" => ['/new-page', $type, null];
            yield "$t: root" => ['/', $type, null];
            yield "$t: with query and fragment" => ['/a/b?x=1#top', $type, null];
            yield "$t: numbered placeholder" => ['/blog/$1', $type, null];
            yield "$t: braced placeholder" => ['/blog/${10}', $type, null];
            yield "$t: named placeholder" => ['/blog/{slug}', $type, null];
            yield "$t: lang placeholder" => ['/{lang}/about', $type, null];
            yield "$t: encoded characters inside" => ['/a%2Fb/c%20d', $type, null];
            yield "$t: empty" => ['', $type, TargetGuard::ERR_EMPTY];
            yield "$t: blank" => ['   ', $type, TargetGuard::ERR_EMPTY];
            yield "$t: protocol-relative" => ['//evil.com', $type, TargetGuard::ERR_PROTOCOL_RELATIVE];
            yield "$t: three slashes" => ['///evil.com', $type, TargetGuard::ERR_PROTOCOL_RELATIVE];
            yield "$t: slash backslash" => ['/\\evil.com', $type, TargetGuard::ERR_PROTOCOL_RELATIVE];
            yield "$t: backslash slash" => ['\\/evil.com', $type, TargetGuard::ERR_PROTOCOL_RELATIVE];
            yield "$t: double backslash" => ['\\\\evil.com', $type, TargetGuard::ERR_PROTOCOL_RELATIVE];
            yield "$t: encoded slashes" => ['/%2F%2Fevil.com', $type, TargetGuard::ERR_PROTOCOL_RELATIVE];
            yield "$t: one encoded slash" => ['/%2Fevil.com', $type, TargetGuard::ERR_PROTOCOL_RELATIVE];
            yield "$t: encoded backslash" => ['/%5Cevil.com', $type, TargetGuard::ERR_PROTOCOL_RELATIVE];
            yield "$t: double encoded slash" => ['/%252Fevil.com', $type, TargetGuard::ERR_PROTOCOL_RELATIVE];
            yield "$t: javascript scheme" => ['javascript:alert(1)', $type, TargetGuard::ERR_SCHEME];
            yield "$t: data scheme" => ['data:text/html,x', $type, TargetGuard::ERR_SCHEME];
            yield "$t: https without slashes" => ['https:evil.com', $type, TargetGuard::ERR_SCHEME];
            yield "$t: http with one slash" => ['http:/evil.com', $type, TargetGuard::ERR_SCHEME];
            yield "$t: absolute url" => ['https://evil.com/', $type, TargetGuard::ERR_SCHEME];
            yield "$t: mixed case scheme" => ['JaVaScRiPt:alert(1)', $type, TargetGuard::ERR_SCHEME];
            yield "$t: relative path" => ['new-page', $type, TargetGuard::ERR_INVALID];
            yield "$t: dot path" => ['./new', $type, TargetGuard::ERR_INVALID];
            yield "$t: leading space" => [' /new', $type, TargetGuard::ERR_INVALID];
            yield "$t: leading tab before slashes" => ["\t//evil.com", $type, TargetGuard::ERR_INVALID];
            yield "$t: tab inside" => ["/\t/evil.com", $type, TargetGuard::ERR_INVALID];
            yield "$t: newline" => ["/a\nb", $type, TargetGuard::ERR_INVALID];
            yield "$t: NUL" => ["/a\0b", $type, TargetGuard::ERR_INVALID];
            yield "$t: encoded NUL" => ['/a%00b', $type, TargetGuard::ERR_INVALID];
            yield "$t: encoded CRLF" => ['/a%0d%0aSet-Cookie:x', $type, TargetGuard::ERR_INVALID];
        }
    }

    #[DataProvider('internalCases')]
    public function testInternalTargets(string $target, TargetType $type, ?string $expected): void
    {
        self::assertSame($expected, (new TargetGuard())->checkTarget($target, $type));
    }

    /**
     * @return iterable<string, array{0: string, 1: list<string>, 2: bool, 3: string|null}>
     */
    public static function urlCases(): iterable
    {
        yield 'allowed host' => ['https://partner.example.org/x', ['partner.example.org'], false, null];
        yield 'allowed host, http' => ['http://partner.example.org/x', ['partner.example.org'], false, null];
        yield 'allowed host with port' => ['https://partner.example.org:8443/x', ['partner.example.org'], false, null];
        yield 'allowed host, case' => ['HTTPS://Partner.Example.ORG/x', ['partner.example.org'], false, null];
        yield 'allowed host, trailing dot' => ['https://partner.example.org./x', ['partner.example.org'], false, null];
        yield 'allowlist entry in upper case' => ['https://partner.example.org/x', ['PARTNER.example.org'], false, null];
        yield 'host without path' => ['https://partner.example.org', ['partner.example.org'], false, null];
        yield 'query directly after host' => ['https://partner.example.org?x=1', ['partner.example.org'], false, null];
        yield 'placeholders in the path' => ['https://partner.example.org/$1/{lang}', ['partner.example.org'], false, null];
        yield 'wildcard entry matches a subdomain' => ['https://a.partner.org/', ['*.partner.org'], false, null];
        yield 'wildcard entry matches nested subdomains' => ['https://a.b.partner.org/', ['*.partner.org'], false, null];
        yield 'wildcard entry does not match the apex' => ['https://partner.org/', ['*.partner.org'], false, TargetGuard::ERR_HOST_NOT_ALLOWED];
        yield 'wildcard entry does not match a look-alike' => ['https://evilpartner.org/', ['*.partner.org'], false, TargetGuard::ERR_HOST_NOT_ALLOWED];
        yield 'exact entry does not match subdomains' => ['https://www.partner.org/', ['partner.org'], false, TargetGuard::ERR_HOST_NOT_ALLOWED];
        yield 'wildcard and apex entries together' => ['https://partner.org/', ['*.partner.org', 'partner.org'], false, null];
        yield 'idn host against unicode allowlist' => ['https://bücher.example/', ['bücher.example'], false, null];
        yield 'idn host against punycode allowlist' => ['https://bücher.example/', ['xn--bcher-kva.example'], false, null];
        yield 'not on the allowlist' => ['https://evil.com/', ['partner.example.org'], false, TargetGuard::ERR_HOST_NOT_ALLOWED];
        yield 'empty allowlist' => ['https://evil.com/', [], false, TargetGuard::ERR_HOST_NOT_ALLOWED];
        yield 'allowlist ignores blank entries' => ['https://evil.com/', ['', '  ', '*.'], false, TargetGuard::ERR_HOST_NOT_ALLOWED];
        yield 'any external allowed' => ['https://anything.example.net/', [], true, null];
        yield 'ipv6 host is not allowed by default' => ['https://[::1]/', [], false, TargetGuard::ERR_HOST_NOT_ALLOWED];
        yield 'ipv6 host with allow any' => ['https://[::1]:8080/x', [], true, null];
        yield 'javascript scheme' => ['javascript:alert(1)', [], true, TargetGuard::ERR_SCHEME];
        yield 'data scheme' => ['data:text/html,x', [], true, TargetGuard::ERR_SCHEME];
        yield 'ftp scheme' => ['ftp://partner.example.org/', ['partner.example.org'], true, TargetGuard::ERR_SCHEME];
        yield 'mailto scheme' => ['mailto:a@b.c', [], true, TargetGuard::ERR_SCHEME];
        yield 'protocol-relative' => ['//partner.example.org/', ['partner.example.org'], true, TargetGuard::ERR_PROTOCOL_RELATIVE];
        yield 'plain path is not a url' => ['/new', ['partner.example.org'], true, TargetGuard::ERR_INVALID];
        yield 'host only' => ['partner.example.org', ['partner.example.org'], true, TargetGuard::ERR_INVALID];
        yield 'scheme without slashes' => ['https:partner.example.org', ['partner.example.org'], true, TargetGuard::ERR_INVALID];
        yield 'scheme with one slash' => ['https:/partner.example.org', ['partner.example.org'], true, TargetGuard::ERR_INVALID];
        yield 'missing host' => ['https:///path', [], true, TargetGuard::ERR_INVALID];
        yield 'user info trick' => ['https://partner.example.org@evil.com/', ['partner.example.org'], true, TargetGuard::ERR_INVALID];
        yield 'user info with password' => ['https://user:pw@partner.example.org/', ['partner.example.org'], true, TargetGuard::ERR_INVALID];
        yield 'backslash trick' => ['https://evil.com\\@partner.example.org/', ['partner.example.org'], true, TargetGuard::ERR_INVALID];
        yield 'backslash in path' => ['https://partner.example.org/a\\b', ['partner.example.org'], true, TargetGuard::ERR_INVALID];
        yield 'placeholder as host' => ['https://$1.partner.org/', ['*.partner.org'], true, TargetGuard::ERR_INVALID];
        yield 'placeholder after host' => ['https://partner.org$1', ['partner.org'], true, TargetGuard::ERR_INVALID];
        yield 'braced placeholder in host' => ['https://{name}.partner.org/', ['*.partner.org'], true, TargetGuard::ERR_INVALID];
        yield 'space in host' => ['https://partner .org/', ['partner .org'], true, TargetGuard::ERR_INVALID];
        yield 'bracket garbage in host' => ['https://[::1/', [], true, TargetGuard::ERR_INVALID];
        yield 'control character' => ["https://partner.org/a\nb", ['partner.org'], true, TargetGuard::ERR_INVALID];
        yield 'empty' => ['', [], true, TargetGuard::ERR_EMPTY];
    }

    /**
     * @param list<string> $allowedHosts
     */
    #[DataProvider('urlCases')]
    public function testUrlTargets(string $target, array $allowedHosts, bool $allowAny, ?string $expected): void
    {
        self::assertSame($expected, (new TargetGuard($allowedHosts, $allowAny))->checkTarget($target, TargetType::Url));
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: bool}>
     */
    public static function locationCases(): iterable
    {
        yield 'relative path' => ['/new', 'example.com', true];
        yield 'root' => ['/', 'example.com', true];
        yield 'relative with query' => ['/new?x=//evil.com', 'example.com', true];
        yield 'relative with encoded slashes later on' => ['/a%2F%2Fb', 'example.com', true];
        yield 'relative with spaces' => ['/new page', 'example.com', true];
        yield 'empty' => ['', 'example.com', false];
        yield 'protocol-relative' => ['//evil.com', 'example.com', false];
        yield 'protocol-relative to the own host' => ['//example.com/x', 'example.com', false];
        yield 'slash backslash' => ['/\\evil.com', 'example.com', false];
        yield 'encoded slash' => ['/%2Fevil.com', 'example.com', false];
        yield 'encoded backslash' => ['/%5Cevil.com', 'example.com', false];
        yield 'relative without slash' => ['new', 'example.com', false];
        yield 'query only' => ['?x=1', 'example.com', false];
        yield 'javascript' => ['javascript:alert(1)', 'example.com', false];
        yield 'scheme without slashes' => ['https:evil.com', 'example.com', false];
        yield 'scheme with one slash' => ['http:/evil.com', 'example.com', false];
        yield 'control character' => ["/a\nb", 'example.com', false];
        yield 'tab' => ["/\t/evil.com", 'example.com', false];
        yield 'absolute, own host' => ['https://example.com/x', 'example.com', true];
        yield 'absolute, own host in other case' => ['https://EXAMPLE.com/x', 'example.COM', true];
        yield 'absolute, own host with port on both' => ['https://example.com:8443/x', 'example.com:8443', true];
        yield 'absolute, own host, request host with port' => ['https://example.com/x', 'example.com:8443', true];
        yield 'absolute, other host' => ['https://evil.com/x', 'example.com', false];
        yield 'absolute, no current host' => ['https://example.com/x', '', false];
        yield 'absolute, user info' => ['https://example.com@evil.com/', 'example.com', false];
        yield 'absolute, backslash' => ['https://evil.com\\@example.com/', 'example.com', false];
        yield 'absolute, ftp' => ['ftp://example.com/', 'example.com', false];
        yield 'absolute, empty host' => ['https:///x', 'example.com', false];
    }

    #[DataProvider('locationCases')]
    public function testIsSafeLocation(string $location, string $currentHost, bool $expected): void
    {
        self::assertSame($expected, (new TargetGuard())->isSafeLocation($location, $currentHost));
    }

    public function testAllowedHostsApplyToLocations(): void
    {
        $guard = new TargetGuard(['partner.example.org', '*.cdn.example.org']);

        self::assertTrue($guard->isSafeLocation('https://partner.example.org/x', 'example.com'));
        self::assertTrue($guard->isSafeLocation('https://a.cdn.example.org/x', 'example.com'));
        self::assertFalse($guard->isSafeLocation('https://cdn.example.org/x', 'example.com'));
        self::assertFalse($guard->isSafeLocation('https://evil.com/x', 'example.com'));
    }

    public function testAllowAnyExternalStillRequiresHttp(): void
    {
        $guard = new TargetGuard([], true);

        self::assertTrue($guard->isSafeLocation('https://anything.net/x', 'example.com'));
        self::assertFalse($guard->isSafeLocation('javascript:alert(1)', 'example.com'));
        self::assertFalse($guard->isSafeLocation('//anything.net/x', 'example.com'));
    }

    public function testIsHostAllowed(): void
    {
        $guard = new TargetGuard(['a.example.org', '*.b.example.org']);

        self::assertTrue($guard->isHostAllowed('A.example.org'));
        self::assertTrue($guard->isHostAllowed('x.b.example.org'));
        self::assertFalse($guard->isHostAllowed('b.example.org'));
        self::assertFalse($guard->isHostAllowed(''));
        self::assertFalse($guard->isHostAllowed('example.org'));
    }
}
