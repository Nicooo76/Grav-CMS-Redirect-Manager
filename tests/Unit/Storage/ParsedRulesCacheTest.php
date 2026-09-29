<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Storage;

use DateTimeImmutable;
use Grav\Plugin\RedirectManager\Domain\Rule;
use Grav\Plugin\RedirectManager\Storage\AtomicFile;
use Grav\Plugin\RedirectManager\Storage\CorruptRulesFileException;
use Grav\Plugin\RedirectManager\Storage\ParsedRulesCache;
use Grav\Plugin\RedirectManager\Storage\RuleRepository;
use Grav\Plugin\RedirectManager\Tests\Unit\NotFound\Support\TempDirTrait;
use Grav\Plugin\RedirectManager\Util\FixedClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

#[CoversClass(ParsedRulesCache::class)]
#[CoversClass(RuleRepository::class)]
#[Group('storage')]
final class ParsedRulesCacheTest extends TestCase
{
    use TempDirTrait;

    private FixedClock $clock;
    private ParsedRulesCache $cache;
    private RuleRepository $repo;

    protected function setUp(): void
    {
        $this->makeTempDir();
        $this->clock = new FixedClock(new DateTimeImmutable('2026-09-29T10:00:00+00:00'));
        $this->cache = new ParsedRulesCache($this->tmp . '/cache', $this->tmp . '/data/rules.yaml');
        $this->repo = new RuleRepository($this->tmp . '/data', $this->clock, $this->cache);
    }

    protected function tearDown(): void
    {
        $this->removeTempDir();
    }

    /**
     * @return list<Rule>
     */
    private function richRules(): array
    {
        return [
            new Rule('a', '/a', '/b'),
            Rule::fromArray([
                'id' => 'r1', 'source' => '^/p/(?<id>\d+)$', 'target' => '/n/{id}', 'match_type' => 'regex', 'status' => 302,
                'enabled' => false, 'priority' => 7, 'case_sensitive' => true, 'ignore_trailing_slash' => false,
                'query_mode' => 'params', 'query_params' => ['a' => '1', 'b' => null], 'query_ignore' => ['utm_*'],
                'continue' => true, 'only_if_not_found' => true, 'active_from' => '2026-01-01T00:00:00+00:00', 'expires_at' => '2027-01-01T00:00:00+00:00',
                'note' => "multi\nline: \"quoted\" # not a comment", 'group' => 'Shop', 'tags' => ['x', 'y'], 'origin' => 'import',
                'conditions' => ['hosts' => ['example.com'], 'languages' => ['de'], 'rules' => [['kind' => 'header', 'name' => 'User-Agent', 'operator' => 'contains', 'value' => 'bot', 'negate' => true]]],
            ]),
            Rule::fromArray(['id' => 'num', 'source' => '/123', 'target' => 'http://example.com/x?y=1', 'note' => '2026-01-01', 'group' => '007']),
            Rule::fromArray(['id' => 'gone', 'source' => '/gone', 'target' => '', 'status' => 410]),
        ];
    }

    public function testWritingFillsTheCacheWithExactlyTheRowsTheYamlHolds(): void
    {
        $this->repo->saveAll($this->richRules());

        $yaml = (string) file_get_contents($this->repo->file());
        $rows = $this->cache->rows(RuleRepository::hashContent($yaml));
        self::assertNotNull($rows, 'saveAll writes the cache through');
        $parsed = Yaml::parse($yaml);
        self::assertIsArray($parsed);
        self::assertSame($parsed['rules'], $rows);
    }

    public function testACachedReadGivesTheSameRulesAsParsingTheYaml(): void
    {
        $this->repo->saveAll($this->richRules());

        $fromCache = $this->repo->all();
        $fromYaml = RuleRepository::parse(AtomicFile::read($this->repo->file()));

        self::assertEquals($fromYaml, $fromCache);
        self::assertSame(array_map(static fn (Rule $r): array => $r->toArray(), $fromYaml), array_map(static fn (Rule $r): array => $r->toArray(), $fromCache));
    }

    public function testTheCacheIsUsedInsteadOfTheYaml(): void
    {
        $this->repo->saveAll([new Rule('a', '/a', '/b')]);
        $content = (string) file_get_contents($this->repo->file());
        // Rows for the same hash that differ from the YAML prove which one is read.
        $this->cache->store(RuleRepository::hashContent($content), [['id' => 'from-cache', 'source' => '/c', 'target' => '/d']]);

        self::assertSame(['from-cache'], array_map(static fn (Rule $r): string => $r->id, $this->repo->all()));
    }

    public function testRowsOfAnotherHashAreIgnoredSoTheCacheCannotBeStale(): void
    {
        $this->repo->saveAll([new Rule('a', '/a', '/b')]);
        // A hand edit of rules.yaml (other content, other hash) must win over the cached rows.
        file_put_contents($this->repo->file(), "version: 1\nrules:\n  - id: edited\n    source: /e\n    target: /f\n");

        self::assertSame(['edited'], array_map(static fn (Rule $r): string => $r->id, $this->repo->all()));
        $snapshot = $this->repo->snapshot();
        self::assertSame(RuleRepository::hashContent((string) file_get_contents($this->repo->file())), $snapshot['revision']);
        self::assertNotNull($this->cache->rows($snapshot['revision']), 'the read stored the rows for the new content');
    }

    public function testACorruptOrTruncatedCacheFileMeansParseAgain(): void
    {
        $this->repo->saveAll([new Rule('a', '/a', '/b')]);
        foreach (['<?php return [', '<?php return "nope";', '<?php return [\'hash\' => 1];', '<?php this is not php;'] as $broken) {
            file_put_contents($this->cache->file(), $broken);

            self::assertSame(['a'], array_map(static fn (Rule $r): string => $r->id, $this->repo->all()), $broken);
        }
    }

    public function testRowsWrittenByAnotherFormatVersionAreIgnored(): void
    {
        $this->repo->saveAll([new Rule('a', '/a', '/b')]);
        $hash = RuleRepository::hashContent((string) file_get_contents($this->repo->file()));
        self::assertNotNull($this->cache->rows($hash));

        file_put_contents($this->cache->file(), '<?php return ' . var_export(['version' => 0, 'hash' => $hash, 'rows' => [['id' => 'old', 'source' => '/o', 'target' => '/p']]], true) . ';');

        self::assertNull($this->cache->rows($hash));
        self::assertSame(['a'], array_map(static fn (Rule $r): string => $r->id, $this->repo->all()));
    }

    public function testForgetDeletesTheCacheFileAndToleratesItsAbsence(): void
    {
        $this->repo->saveAll([new Rule('a', '/a', '/b')]);
        self::assertFileExists($this->cache->file());

        $this->cache->forget();
        self::assertFileDoesNotExist($this->cache->file());

        $this->cache->forget();
        self::assertSame(['a'], array_map(static fn (Rule $r): string => $r->id, $this->repo->all()), 'the next read parses and stores again');
        self::assertFileExists($this->cache->file());
    }

    public function testAnUnwritableCacheDirectoryDoesNotBreakReading(): void
    {
        file_put_contents($this->tmp . '/blocked', 'a file where the cache directory should be');
        $cache = new ParsedRulesCache($this->tmp . '/blocked/sub', $this->tmp . '/data/rules.yaml');
        $repo = new RuleRepository($this->tmp . '/data', $this->clock, $cache);

        $repo->saveAll([new Rule('a', '/a', '/b')]);

        self::assertSame(['a'], array_map(static fn (Rule $r): string => $r->id, $repo->all()));
        self::assertNull($cache->rows('anything'));
    }

    public function testACorruptRulesFileStillThrowsAndIsNeverCached(): void
    {
        mkdir($this->tmp . '/data', 0775, true);
        file_put_contents($this->repo->file(), "rules: [unclosed\n");

        try {
            $this->repo->all();
            self::fail('expected CorruptRulesFileException');
        } catch (CorruptRulesFileException) {
            self::assertFileDoesNotExist($this->cache->file());
        }
    }

    public function testTransactionsReadAndWriteThroughTheCache(): void
    {
        $this->repo->saveAll($this->richRules());
        $this->repo->upsert(new Rule('added', '/added', '/x'));

        $ids = array_map(static fn (Rule $r): string => $r->id, $this->repo->all());
        self::assertSame(['a', 'r1', 'num', 'gone', 'added'], $ids);
        $yaml = (string) file_get_contents($this->repo->file());
        self::assertNotNull($this->cache->rows(RuleRepository::hashContent($yaml)), 'the cache follows the file after a transaction');
    }

    public function testAnUnchangedTransactionLeavesTheFileAlone(): void
    {
        $this->repo->saveAll($this->richRules());
        $before = (string) file_get_contents($this->repo->file());
        $mtime = filemtime($this->repo->file());

        $this->repo->transaction(static fn (array $rules): array => $rules);

        self::assertSame($before, file_get_contents($this->repo->file()));
        self::assertSame($mtime, filemtime($this->repo->file()));
    }

    public function testTheCachedRowsAreNormalizedNotTheRawYaml(): void
    {
        mkdir($this->tmp . '/data', 0775, true);
        file_put_contents($this->repo->file(), "rules:\n  - id: x\n    source: /a\n    target: /b\n    enabled: 'yes'\n    priority: '5'\n    status: '302'\n    tags: 'one, two'\n");

        $rules = $this->repo->all();
        $rows = $this->cache->rows(RuleRepository::hashContent((string) file_get_contents($this->repo->file())));

        self::assertNotNull($rows);
        self::assertTrue($rows[0]['enabled']);
        self::assertSame(5, $rows[0]['priority']);
        self::assertSame(302, $rows[0]['status']);
        self::assertSame(['one', 'two'], $rows[0]['tags']);
        self::assertEquals($rules, $this->repo->all(), 'the cached read gives the same rules as the first read');
    }

    public function testSnapshotRowsGiveTheStoredFormWithoutRuleObjects(): void
    {
        $this->repo->saveAll($this->richRules());

        $snapshot = $this->repo->snapshotRows();

        self::assertSame($this->repo->revision(), $snapshot['revision']);
        self::assertSame(['a', 'r1', 'num', 'gone'], array_column($snapshot['rows'], 'id'));
        self::assertSame(array_map(static fn (Rule $r): array => $r->toArray(), $this->repo->all()), array_map(static fn (array $row): array => Rule::fromArray($row)->toArray(), $snapshot['rows']));
    }

    public function testSnapshotRowsOfNothingAndWithoutACache(): void
    {
        self::assertSame(['rows' => [], 'revision' => RuleRepository::hashContent('')], $this->repo->snapshotRows());

        $plain = new RuleRepository($this->tmp . '/data', $this->clock);
        $plain->saveAll([new Rule('a', '/a', '/b')]);

        self::assertSame(['a'], array_column($plain->snapshotRows()['rows'], 'id'));
    }

    public function testASnapshotOfACorruptFileThrows(): void
    {
        mkdir($this->tmp . '/data', 0775, true);
        file_put_contents($this->repo->file(), "rules: [unclosed\n");

        $this->expectException(CorruptRulesFileException::class);
        $this->repo->snapshotRows();
    }

    public function testParseCachedWithoutContentIsEmpty(): void
    {
        self::assertSame([], RuleRepository::parseCached(null, 'x', $this->cache));
        self::assertSame([], RuleRepository::parseCached("  \n", 'x', $this->cache));
        self::assertSame([], $this->repo->parseContent(null));
        self::assertSame([], (new RuleRepository($this->tmp . '/data', $this->clock))->parseContent(''));
    }

    public function testARepositoryWithoutCacheParsesTheYaml(): void
    {
        $plain = new RuleRepository($this->tmp . '/data', $this->clock);
        $plain->saveAll([new Rule('a', '/a', '/b')]);

        self::assertSame(['a'], array_map(static fn (Rule $r): string => $r->id, $plain->all()));
        self::assertFileDoesNotExist($this->cache->file());
    }
}
