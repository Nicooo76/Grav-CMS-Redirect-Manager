<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\ImportExport;

use DateTimeImmutable;
use Grav\Plugin\RedirectManager\Domain\MatchType;
use Grav\Plugin\RedirectManager\Domain\QueryMode;
use Grav\Plugin\RedirectManager\Domain\RuleSource;
use Grav\Plugin\RedirectManager\Domain\StatusCode;
use Grav\Plugin\RedirectManager\Domain\TargetType;
use Grav\Plugin\RedirectManager\ImportExport\ImportOptions;
use Grav\Plugin\RedirectManager\ImportExport\ImportRow;
use Grav\Plugin\RedirectManager\ImportExport\Support\RowFactory;
use Grav\Plugin\RedirectManager\ImportExport\Support\StatusParser;
use Grav\Plugin\RedirectManager\Util\FixedClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(RowFactory::class)]
#[CoversClass(StatusParser::class)]
final class RowFactoryTest extends TestCase
{
    /**
     * @param array<string, mixed> $fields
     */
    private function make(array $fields, ?ImportOptions $options = null): ImportRow
    {
        $factory = new RowFactory($options ?? new ImportOptions(), new FixedClock(new DateTimeImmutable('2026-09-29T10:00:00+00:00')));

        return $factory->make(7, 'raw line', $fields);
    }

    /**
     * @return list<string>
     */
    private static function codes(ImportRow $row): array
    {
        return array_map(static fn ($i): string => $i->code, [...$row->errors, ...$row->warnings]);
    }

    public function testMinimalRule(): void
    {
        $row = $this->make(['source' => ' /old ', 'target' => '/new']);

        self::assertNotNull($row->rule);
        self::assertSame('/old', $row->rule->source);
        self::assertSame(MatchType::Exact, $row->rule->matchType);
        self::assertSame(StatusCode::MovedPermanently, $row->rule->status);
        self::assertSame(TargetType::Route, $row->rule->targetType);
        self::assertSame(RuleSource::Import, $row->rule->origin);
        self::assertSame(7, $row->line);
        self::assertSame('raw line', $row->raw);
    }

    public function testEmptySourceIsAnError(): void
    {
        $row = $this->make(['source' => '  ', 'target' => '/x']);

        self::assertNull($row->rule);
        self::assertSame(['missing_source'], self::codes($row));
    }

    /**
     * @return iterable<string, array{string, MatchType}>
     */
    public static function matchTypes(): iterable
    {
        yield 'auto exact' => ['', MatchType::Exact];
        yield 'explicit exact' => ['EXACT', MatchType::Exact];
        yield 'literal' => ['literal', MatchType::Exact];
        yield 'glob' => ['glob', MatchType::Wildcard];
        yield 'prefix' => ['prefix', MatchType::Wildcard];
        yield 'regexp' => ['regexp', MatchType::Regex];
    }

    #[DataProvider('matchTypes')]
    public function testMatchTypeWords(string $word, MatchType $expected): void
    {
        $source = $expected === MatchType::Regex ? '^/a$' : '/a';
        $row = $this->make(['source' => $source, 'target' => '/b', 'match_type' => $word]);

        self::assertSame($expected, $row->rule?->matchType);
    }

    public function testMatchTypeIsGuessedFromStar_only_when_unspecified(): void
    {
        self::assertSame(MatchType::Wildcard, $this->make(['source' => '/a/*', 'target' => '/b'])->rule?->matchType);
        self::assertSame(MatchType::Exact, $this->make(['source' => '/a/*', 'target' => '/b', 'match_type' => 'exact'])->rule?->matchType);
        self::assertSame(['invalid_match_type'], self::codes($this->make(['source' => '/a', 'target' => '/b', 'match_type' => 'fuzzy'])));
    }

    public function testInvalidRegexIsAnError(): void
    {
        $row = $this->make(['source' => '^/a/(unclosed', 'target' => '/b', 'match_type' => 'regex']);

        self::assertNull($row->rule);
        self::assertSame(['invalid_regex'], self::codes($row));
    }

    /**
     * @return iterable<string, array{mixed, int|null, list<string>}>
     */
    public static function statuses(): iterable
    {
        yield 'default' => [null, 301, []];
        yield 'empty string' => ['', 301, []];
        yield 'int' => [302, 302, []];
        yield 'string number' => [' 307 ', 307, []];
        yield 'permanent' => ['permanent', 301, []];
        yield 'temp' => ['TEMP', 302, []];
        yield 'gone' => ['gone', 410, []];
        yield 'see other maps to 302' => [303, 302, ['status_mapped']];
        yield 'seeother word' => ['seeother', 302, ['status_mapped']];
        yield 'not a redirect' => [404, null, ['invalid_status']];
        yield 'word unknown' => ['banana', null, ['invalid_status']];
        yield 'forced marker' => ['301!', 301, []];
    }

    /**
     * @param list<string> $codes
     */
    #[DataProvider('statuses')]
    public function testStatus(mixed $status, ?int $expected, array $codes): void
    {
        $row = $this->make(['source' => '/a', 'target' => $expected === 410 ? '' : '/b', 'status' => $status]);

        self::assertSame($expected, $row->rule?->status->value);
        self::assertSame($codes, self::codes($row));
    }

    public function testDefaultStatusFromOptions(): void
    {
        $row = $this->make(['source' => '/a', 'target' => '/b'], new ImportOptions(defaultStatus: 302));
        self::assertSame(302, $row->rule?->status->value);

        $override = $this->make(['source' => '/a', 'target' => '/b', 'default_status' => 307], new ImportOptions(defaultStatus: 302));
        self::assertSame(307, $override->rule?->status->value);
    }

    public function testTargets(): void
    {
        $gone = $this->make(['source' => '/a', 'target' => '/ignored', 'status' => 410]);
        self::assertSame('', $gone->rule?->target);

        self::assertSame(['missing_target'], self::codes($this->make(['source' => '/a', 'target' => ''])));
        self::assertSame(['missing_target'], self::codes($this->make(['source' => '/a', 'status' => 200])));

        $external = $this->make(['source' => '/a', 'target' => 'https://example.org/x']);
        self::assertSame(TargetType::Url, $external->rule?->targetType);
        self::assertSame(TargetType::Url, $this->make(['source' => '/a', 'target' => '//cdn.example.org/x'])->rule?->targetType);

        self::assertSame('/relative', $this->make(['source' => '/a', 'target' => 'relative'])->rule?->target);
        self::assertSame('/x/$1', $this->make(['source' => '/a/*', 'target' => '/x/$1'])->rule?->target);
        self::assertSame('{lang}/x', $this->make(['source' => '/a', 'target' => '{lang}/x'])->rule?->target);
        self::assertSame(['invalid_target'], self::codes($this->make(['source' => '/a', 'target' => '../up'])));
        self::assertSame(['invalid_target'], self::codes($this->make(['source' => '/a', 'target' => "/x y"])));
        self::assertSame(['invalid_target'], self::codes($this->make(['source' => '/a', 'target' => "/x\ny"])));
        self::assertSame(TargetType::Page, $this->make(['source' => '/a', 'target' => '/p', 'target_type' => 'page'])->rule?->targetType);
        self::assertSame(['invalid_target_type'], self::codes($this->make(['source' => '/a', 'target' => '/p', 'target_type' => 'moon'])));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsafeTargets(): iterable
    {
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'data' => ['data:text/html;base64,PHNjcmlwdD4='];
        yield 'file' => ['file:///etc/passwd'];
        yield 'vbscript upper' => ['VBScript:msgbox'];
        yield 'mailto' => ['mailto:a@example.com'];
    }

    #[DataProvider('unsafeTargets')]
    public function testUnsafeSchemesAreRejected(string $target): void
    {
        $row = $this->make(['source' => '/a', 'target' => $target]);

        self::assertNull($row->rule);
        self::assertSame(['unsafe_target'], self::codes($row));
    }

    public function testAbsoluteSourceUrls(): void
    {
        $noHosts = $this->make(['source' => 'https://Shop.Example.com:8443/old?x=1#frag', 'target' => '/new']);
        self::assertSame('/old?x=1', $noHosts->rule?->source);
        self::assertSame(['source_host_stripped', 'fragment_removed'], self::codes($noHosts));
        self::assertSame(QueryMode::Exact, $noHosts->rule?->queryMode);
        self::assertSame([], $noHosts->rule->conditions->hosts);

        $options = new ImportOptions(baseHosts: ['www.example.com']);
        $own = $this->make(['source' => 'http://example.com/old', 'target' => 'https://www.example.com/new?a=1'], $options);
        self::assertSame('/old', $own->rule?->source, 'www is ignored when comparing hosts');
        self::assertSame('/new?a=1', $own->rule->target, 'targets on the own site become routes');
        self::assertSame(TargetType::Route, $own->rule->targetType);
        self::assertSame([], self::codes($own));

        $foreign = $this->make(['source' => '//other.test/', 'target' => '/new'], $options);
        self::assertSame('/', $foreign->rule?->source);
        self::assertSame(['other.test'], $foreign->rule->conditions->hosts);
        self::assertSame(['source_host_condition'], self::codes($foreign));

        self::assertSame('/', $this->make(['source' => 'https://example.com', 'target' => '/n'], $options)->rule?->source);
    }

    public function testSourceIsNormalised(): void
    {
        self::assertSame('/no-slash', $this->make(['source' => 'no-slash', 'target' => '/b'])->rule?->source);
        self::assertSame('/café au lait', $this->make(['source' => '/caf%C3%A9%20au%20lait', 'target' => '/b'])->rule?->source);
        self::assertSame('/a%2Fb', $this->make(['source' => '/a%2Fb', 'target' => '/b'])->rule?->source, 'encoded slashes stay encoded');
        self::assertSame('/bad%FFbyte', $this->make(['source' => '/bad%FFbyte', 'target' => '/b'])->rule?->source);
    }

    public function testLanguagePrefixesMoveIntoConditions(): void
    {
        $options = new ImportOptions(siteLanguages: ['de', 'EN']);

        $de = $this->make(['source' => '/de/alt', 'target' => '/neu'], $options);
        self::assertSame('/alt', $de->rule?->source);
        self::assertSame(['de'], $de->rule->conditions->languages);

        $root = $this->make(['source' => '/en', 'target' => '/'], $options);
        self::assertSame('/', $root->rule?->source);
        self::assertSame(['en'], $root->rule->conditions->languages);

        $other = $this->make(['source' => '/deutsch/alt', 'target' => '/neu'], $options);
        self::assertSame('/deutsch/alt', $other->rule?->source);
        self::assertSame([], $other->rule->conditions->languages);

        $regex = $this->make(['source' => '^/de/(.*)$', 'target' => '/n', 'match_type' => 'regex'], $options);
        self::assertSame('^/de/(.*)$', $regex->rule?->source, 'regexes are left alone');
    }

    public function testQueryModes(): void
    {
        $exact = $this->make(['source' => '/a?x=1&y=2', 'target' => '/b']);
        self::assertSame(QueryMode::Exact, $exact->rule?->queryMode);
        self::assertSame('/a?x=1&y=2', $exact->rule->source);

        $params = $this->make(['source' => '/a?x=1&flag&e=', 'target' => '/b', 'query_mode' => 'params']);
        self::assertSame('/a', $params->rule?->source);
        self::assertSame(['x' => '1', 'flag' => null, 'e' => null], $params->rule->queryParams);

        $paramsFromString = $this->make(['source' => '/a', 'target' => '/b', 'query_mode' => 'params', 'query_params' => 'x=1&y']);
        self::assertSame(['x' => '1', 'y' => null], $paramsFromString->rule?->queryParams);

        $paramsFromArray = $this->make(['source' => '/a', 'target' => '/b', 'query_mode' => 'params', 'query_params' => ['x' => 1, 'y' => '', 'z' => null]]);
        self::assertSame(['x' => '1', 'y' => null, 'z' => null], $paramsFromArray->rule?->queryParams);

        $ignored = $this->make(['source' => '/a?x=1', 'target' => '/b', 'query_mode' => 'pass']);
        self::assertSame('/a', $ignored->rule?->source);
        self::assertSame(['source_query_dropped'], self::codes($ignored));

        self::assertSame(['invalid_query_mode'], self::codes($this->make(['source' => '/a', 'target' => '/b', 'query_mode' => 'weird'])));
    }

    public function testOptionalFieldsAndDefaults(): void
    {
        $options = new ImportOptions(defaultGroup: 'Import', origin: RuleSource::Auto);
        $row = $this->make([
            'source' => '/a', 'target' => '/b', 'enabled' => '', 'priority' => '5', 'note' => 'hi', 'tags' => ['a', 'b'],
            'case_sensitive' => 'true', 'ignore_trailing_slash' => 'false', 'expires_at' => '2030-01-01T00:00:00+00:00',
            'active_from' => 1893456000, 'created_at' => '2020-01-01T00:00:00+00:00',
        ], $options);

        $rule = $row->rule;
        self::assertNotNull($rule);
        self::assertTrue($rule->enabled, 'an empty cell keeps the default');
        self::assertSame(5, $rule->priority);
        self::assertSame('Import', $rule->group);
        self::assertSame(RuleSource::Auto, $rule->origin);
        self::assertTrue($rule->caseSensitive);
        self::assertFalse($rule->ignoreTrailingSlash);
        self::assertSame(['a', 'b'], $rule->tags);
        self::assertSame('2030-01-01T00:00:00+00:00', $rule->expiresAt?->format('c'));
        self::assertSame(1893456000, $rule->activeFrom?->getTimestamp());
        self::assertSame('2020-01-01T00:00:00+00:00', $rule->createdAt?->format('c'), 'given timestamps are kept');

        $own = $this->make(['source' => '/a', 'target' => '/b', 'group' => ' Mine '], $options);
        self::assertSame('Mine', $own->rule?->group);
    }

    public function testInvalidDatesAreDroppedWithAWarning(): void
    {
        $row = $this->make(['source' => '/a', 'target' => '/b', 'expires_at' => 'next tuesday-ish', 'active_from' => 'nonsense!']);

        self::assertNotNull($row->rule);
        self::assertNull($row->rule->expiresAt);
        self::assertSame(['invalid_date', 'invalid_date'], self::codes($row));
        self::assertSame(['active_from', 'expires_at'], array_map(static fn ($w) => $w->params['field'], $row->warnings));
    }

    public function testConditionsAreMerged(): void
    {
        $row = $this->make([
            'source' => 'https://a.test/x', 'target' => '/b',
            'conditions' => ['hosts' => ['B.test'], 'rules' => [['kind' => 'header', 'name' => 'X', 'operator' => 'exists']]],
        ], new ImportOptions(baseHosts: ['mine.test']));

        self::assertSame(['b.test', 'a.test'], $row->rule?->conditions->hosts);
        self::assertCount(1, $row->rule->conditions->rules);
    }

    public function testErrorAndSkippedHelpers(): void
    {
        $factory = new RowFactory(new ImportOptions(), new FixedClock(new DateTimeImmutable()));
        $issue = new \Grav\Plugin\RedirectManager\ImportExport\ImportIssue('x', 'msg');

        self::assertSame(['x'], array_map(static fn ($i) => $i->code, $factory->error(3, 'r', $issue)->errors));
        $skipped = $factory->skipped(4, 'r', $issue);
        self::assertNull($skipped->rule);
        self::assertSame([], $skipped->errors);
        self::assertSame(['x'], array_map(static fn ($i) => $i->code, $skipped->warnings));
    }

    public function testToPathAndIsBaseHost(): void
    {
        $factory = new RowFactory(new ImportOptions(baseHosts: ['Example.com', '']), new FixedClock(new DateTimeImmutable()));

        self::assertSame('/x', $factory->toPath('https://example.com/x'));
        self::assertSame('/', $factory->toPath('https://www.example.com'));
        self::assertSame('/?q=1', $factory->toPath('//example.com?q=1'));
        self::assertSame('https://other.com/x', $factory->toPath('https://other.com/x'));
        self::assertSame('/plain', $factory->toPath('/plain'));
        self::assertTrue($factory->isBaseHost('WWW.EXAMPLE.COM'));
        self::assertFalse($factory->isBaseHost('example.org'));
        self::assertFalse((new RowFactory(new ImportOptions(), new FixedClock(new DateTimeImmutable())))->isBaseHost('example.com'));
    }

    public function testParseQuery(): void
    {
        self::assertSame(['a' => '1', 'b c' => 'd e', 'flag' => null], RowFactory::parseQuery('?a=1&b%20c=d+e&flag&&=x'));
        self::assertSame([], RowFactory::parseQuery(''));
    }

    public function testStatusParser(): void
    {
        self::assertSame(301, StatusParser::code(301));
        self::assertSame(301, StatusParser::code('301'));
        self::assertSame(301, StatusParser::code(301.0));
        self::assertSame(410, StatusParser::code(' Gone '));
        self::assertSame(302, StatusParser::code('redirect'));
        self::assertNull(StatusParser::code(null));
        self::assertNull(StatusParser::code(''));
        self::assertNull(StatusParser::code('!'));
        self::assertNull(StatusParser::code(['a']));
        self::assertNull(StatusParser::code('lolwut'));
    }
}
