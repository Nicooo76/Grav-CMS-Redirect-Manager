<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Storage;

use DateTimeImmutable;
use Grav\Plugin\RedirectManager\Domain\Conditions;
use Grav\Plugin\RedirectManager\Domain\MatchType;
use Grav\Plugin\RedirectManager\Domain\Rule;
use Grav\Plugin\RedirectManager\Domain\StatusCode;
use Grav\Plugin\RedirectManager\Storage\ConcurrentModificationException;
use Grav\Plugin\RedirectManager\Storage\CorruptRulesFileException;
use Grav\Plugin\RedirectManager\Storage\DuplicateRuleIdException;
use Grav\Plugin\RedirectManager\Storage\RuleRepository;
use Grav\Plugin\RedirectManager\Tests\Unit\NotFound\Support\TempDirTrait;
use Grav\Plugin\RedirectManager\Util\FixedClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(RuleRepository::class)]
#[Group('storage')]
final class RuleRepositoryTest extends TestCase
{
    use TempDirTrait;

    private FixedClock $clock;
    private RuleRepository $repo;

    protected function setUp(): void
    {
        $this->makeTempDir();
        $this->clock = new FixedClock(new DateTimeImmutable('2026-09-29T10:00:00+00:00'));
        $this->repo = new RuleRepository($this->tmp . '/data', $this->clock);
    }

    protected function tearDown(): void
    {
        $this->removeTempDir();
    }

    public function testMissingFileIsEmptyList(): void
    {
        self::assertSame([], $this->repo->all());
        self::assertNull($this->repo->find('nope'));
        self::assertSame($this->repo->revision(), $this->repo->revision());
    }

    public function testUpsertStampsTimestampsFromTheClock(): void
    {
        $stored = $this->repo->upsert(new Rule('a', '/a', '/b'));
        self::assertSame('2026-09-29T10:00:00+00:00', $stored->createdAt?->format(Rule::DATE_FORMAT));
        self::assertSame('2026-09-29T10:00:00+00:00', $stored->updatedAt?->format(Rule::DATE_FORMAT));

        $this->clock->set(new DateTimeImmutable('2026-09-30T08:00:00+00:00'));
        $changed = $this->repo->upsert($stored->with(['target' => '/c']));
        self::assertSame('2026-09-29T10:00:00+00:00', $changed->createdAt?->format(Rule::DATE_FORMAT), 'created_at is kept');
        self::assertSame('2026-09-30T08:00:00+00:00', $changed->updatedAt?->format(Rule::DATE_FORMAT));

        $this->clock->set(new DateTimeImmutable('2026-10-01T08:00:00+00:00'));
        $same = $this->repo->upsert($changed);
        self::assertSame('2026-09-30T08:00:00+00:00', $same->updatedAt?->format(Rule::DATE_FORMAT), 'no change, no new updated_at');
    }

    public function testRoundTripKeepsAllFields(): void
    {
        $rule = Rule::fromArray([
            'id' => 'x1',
            'source' => '^/p/(?<id>\d+)$',
            'target' => '/shop/{id}',
            'match_type' => 'regex',
            'status' => 308,
            'priority' => 7,
            'case_sensitive' => true,
            'query_mode' => 'params',
            'query_params' => ['id' => null, 'ref' => 'x'],
            'note' => "line one\nline two",
            'tags' => ['a', 'b'],
            'conditions' => ['hosts' => ['example.org'], 'rules' => [['kind' => 'header', 'name' => 'user-agent', 'operator' => 'contains', 'value' => 'bot']]],
        ]);
        $this->repo->upsert($rule);

        $read = (new RuleRepository($this->tmp . '/data', $this->clock))->find('x1');
        self::assertNotNull($read);
        self::assertSame(MatchType::Regex, $read->matchType);
        self::assertSame(StatusCode::PermanentRedirect, $read->status);
        self::assertSame("line one\nline two", $read->note);
        self::assertSame(['id' => null, 'ref' => 'x'], $read->queryParams);
        self::assertSame(['example.org'], $read->conditions->hosts);
        self::assertSame('bot', $read->conditions->rules[0]->value);
        self::assertSame('^/p/(?<id>\d+)$', $read->source);
    }

    public function testFileIsReadableAndStable(): void
    {
        $this->repo->upsertMany([new Rule('a', '/a', '/b'), new Rule('b', '/c', '/d', status: StatusCode::Found)]);
        $first = (string) file_get_contents($this->repo->file());
        self::assertStringContainsString('version: 1', $first);
        self::assertStringContainsString("  -\n    id: a\n    source: /a", $first);
        self::assertStringNotContainsString('query_ignore', $first, 'default values are omitted');

        $this->repo->transaction(static fn (array $rules): array => $rules);
        self::assertSame($first, (string) file_get_contents($this->repo->file()), 'an unchanged save rewrites nothing');
    }

    public function testDuplicateIdsAreRejected(): void
    {
        $this->expectException(DuplicateRuleIdException::class);
        $this->repo->saveAll([new Rule('a', '/a', '/b'), new Rule('a', '/c', '/d')]);
    }

    public function testEmptyIdIsRejected(): void
    {
        $this->expectException(DuplicateRuleIdException::class);
        $this->repo->upsert(new Rule('', '/a', '/b'));
    }

    public function testUpsertReplacesInPlaceAndKeepsOrder(): void
    {
        $this->repo->upsertMany([new Rule('a', '/a', '/1'), new Rule('b', '/b', '/2'), new Rule('c', '/c', '/3')]);
        $this->repo->upsert(new Rule('b', '/b', '/changed'));

        $rules = $this->repo->all();
        self::assertSame(['a', 'b', 'c'], array_map(static fn (Rule $r): string => $r->id, $rules));
        self::assertSame('/changed', $rules[1]->target);
    }

    public function testDeleteReturnsDeletedAndRestoreBringsThemBack(): void
    {
        $this->repo->upsertMany([new Rule('a', '/a', '/1'), new Rule('b', '/b', '/2')]);
        $before = $this->repo->find('a');
        self::assertNotNull($before);

        $this->clock->set(new DateTimeImmutable('2026-12-01T00:00:00+00:00'));
        $deleted = $this->repo->delete(['a', 'unknown']);
        self::assertSame(['a'], array_map(static fn (Rule $r): string => $r->id, $deleted));
        self::assertNull($this->repo->find('a'));

        $this->repo->restore($deleted);
        $restored = $this->repo->find('a');
        self::assertNotNull($restored);
        self::assertEquals($before->createdAt, $restored->createdAt);
        self::assertEquals($before->updatedAt, $restored->updatedAt, 'restore is verbatim');
    }

    public function testOptimisticLocking(): void
    {
        $this->repo->upsert(new Rule('a', '/a', '/1'));
        $revision = $this->repo->revision();

        $this->repo->saveAll([new Rule('a', '/a', '/2')], $revision);
        self::assertNotSame($revision, $this->repo->revision());

        $this->expectException(ConcurrentModificationException::class);
        $this->repo->saveAll([new Rule('a', '/a', '/3')], $revision);
    }

    public function testTransactionCallbackSeesCurrentRulesAndCanRemove(): void
    {
        $this->repo->upsertMany([new Rule('a', '/a', '/1'), new Rule('b', '/b', '/2')]);
        $written = $this->repo->transaction(static fn (array $rules): array => array_values(array_filter($rules, static fn (Rule $r): bool => $r->id !== 'a')));
        self::assertCount(1, $written);
        self::assertCount(1, $this->repo->all());
    }

    public function testCorruptFileThrowsAndIsNotOverwrittenByTransaction(): void
    {
        mkdir($this->tmp . '/data', 0775, true);
        file_put_contents($this->repo->file(), "rules: [unclosed\n  - : :");

        try {
            $this->repo->all();
            self::fail('expected CorruptRulesFileException');
        } catch (CorruptRulesFileException $e) {
            self::assertStringContainsString('rules.yaml', $e->getMessage());
        }

        try {
            $this->repo->upsert(new Rule('a', '/a', '/b'));
            self::fail('expected CorruptRulesFileException');
        } catch (CorruptRulesFileException) {
        }
        self::assertSame("rules: [unclosed\n  - : :", file_get_contents($this->repo->file()), 'the corrupt file stays untouched');
    }

    public function testSaveAllBacksUpACorruptFileBeforeReplacingIt(): void
    {
        mkdir($this->tmp . '/data', 0775, true);
        file_put_contents($this->repo->file(), 'just a string');

        $this->repo->saveAll([new Rule('a', '/a', '/b')]);

        $backups = glob($this->repo->file() . '.corrupt-*') ?: [];
        self::assertCount(1, $backups);
        self::assertSame('just a string', file_get_contents($backups[0]));
        self::assertCount(1, $this->repo->all());
    }

    public function testStructurallyWrongDocumentsAreCorrupt(): void
    {
        foreach (["- a\n- b\n", "version: 1\nrules: 5\n", "rules:\n  - just text\n", "rules:\n  - {id: a, source: /a}\n  - {id: a, source: /b}\n"] as $content) {
            try {
                RuleRepository::parse($content);
                self::fail('expected CorruptRulesFileException for: ' . $content);
            } catch (CorruptRulesFileException) {
            }
        }
        self::assertSame([], RuleRepository::parse(''));
        self::assertSame([], RuleRepository::parse("version: 1\nrules:\n"));
    }

    public function testConditionsSurviveEmptyAndFilled(): void
    {
        $this->repo->upsert(new Rule('a', '/a', '/b', conditions: new Conditions()));
        self::assertStringNotContainsString('conditions', (string) file_get_contents($this->repo->file()));
    }

    public function testTwoProcessesDoNotLoseUpdates(): void
    {
        $dir = $this->tmp . '/data';
        mkdir($dir, 0775, true);
        $goFile = $this->tmp . '/go';
        $script = __DIR__ . '/Support/repo-worker.php';
        $procs = [];
        foreach ([0, 1, 2] as $worker) {
            $proc = proc_open(
                [PHP_BINARY, $script, $dir, (string) $worker, '25', $goFile],
                [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', $this->tmp . '/err-' . $worker, 'w']],
                $pipes,
            );
            self::assertIsResource($proc);
            $procs[$worker] = $proc;
        }
        touch($goFile);
        foreach ($procs as $worker => $proc) {
            proc_close($proc);
            self::assertSame('', (string) file_get_contents($this->tmp . '/err-' . $worker), 'worker ' . $worker . ' failed');
        }

        $ids = array_map(static fn (Rule $r): string => $r->id, $this->repo->all());
        self::assertCount(75, $ids);
        self::assertCount(75, array_unique($ids));
    }
}
