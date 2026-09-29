<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Cli;

use Grav\Plugin\RedirectManager\Analysis\ValidationIssue;
use Grav\Plugin\RedirectManager\App\Exception\InvalidInputException;
use Grav\Plugin\RedirectManager\App\Exception\PayloadTooLargeException;
use Grav\Plugin\RedirectManager\App\Exception\RateLimitedException;
use Grav\Plugin\RedirectManager\App\Exception\ResourceNotFoundException;
use Grav\Plugin\RedirectManager\App\Exception\RevisionConflictException;
use Grav\Plugin\RedirectManager\App\Exception\UnavailableException;
use Grav\Plugin\RedirectManager\Cli\CliCommands;
use Grav\Plugin\RedirectManager\Cli\ExitCode;
use Grav\Plugin\RedirectManager\NotFound\NotFoundEntry;
use Grav\Plugin\RedirectManager\NotFound\UserAgentClass;
use Grav\Plugin\RedirectManager\Tests\Unit\App\Support\AppTestCase;
use Grav\Plugin\RedirectManager\Tests\Unit\Suggest\PageTreeFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(CliCommands::class)]
#[Group('cli')]
final class CliCommandsTest extends AppTestCase
{
    private BufferedOutput $output;

    protected function setUp(): void
    {
        parent::setUp();
        $this->output = new BufferedOutput(BufferedOutput::VERBOSITY_NORMAL, false);
    }

    private function cli(?int $verbosity = null): CliCommands
    {
        $this->output = new BufferedOutput($verbosity ?? BufferedOutput::VERBOSITY_NORMAL, false);

        return new CliCommands($this->app, new SymfonyStyle(new ArrayInput([]), $this->output));
    }

    /** Everything written so far, whitespace collapsed (error blocks wrap long lines). */
    private function out(): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $this->output->fetch()));
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonOut(): array
    {
        $decoded = json_decode($this->output->fetch(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @return list<string> ids of the stored rules
     */
    private function storedIds(): array
    {
        return array_map(static fn ($r): string => $r->id, $this->app->rules()->all());
    }

    // ---------------------------------------------------------------- guard

    public function testGuardMapsExceptionsToExitCodes(): void
    {
        $cases = [
            [new InvalidInputException('Bad value.', field: 'x'), ExitCode::INVALID],
            [new PayloadTooLargeException(20, 10), ExitCode::INVALID],
            [new RevisionConflictException('Changed.'), ExitCode::INVALID],
            [new ResourceNotFoundException('rule', 'r9'), ExitCode::NOT_FOUND],
            [new RateLimitedException(30), ExitCode::ERROR],
            [new UnavailableException('No client.'), ExitCode::ERROR],
            [new RuntimeException('Boom'), ExitCode::ERROR],
        ];
        foreach ($cases as [$exception, $code]) {
            $cli = $this->cli();
            self::assertSame($code, $cli->guard(static function () use ($exception): int {
                throw $exception;
            }), $exception::class);
            self::assertStringContainsString($exception->getMessage(), $this->out());
        }
    }

    public function testGuardPrintsEveryIssueAndHidesTheTraceUnlessVerbose(): void
    {
        $cli = $this->cli();
        self::assertSame(ExitCode::INVALID, $cli->guard(static function (): int {
            throw new InvalidInputException('Two problems.', [
                ValidationIssue::error('required', 'source', 'Source is empty.'),
                ValidationIssue::error('invalid_value', 'status', 'Status is wrong.'),
            ]);
        }));
        $text = $this->out();
        self::assertStringContainsString('source: Source is empty. (required)', $text);
        self::assertStringContainsString('status: Status is wrong. (invalid_value)', $text);

        $cli = $this->cli();
        $cli->guard(static function (): int {
            throw new RuntimeException('Quiet');
        });
        self::assertStringNotContainsString('#0', $this->out());

        $cli = $this->cli(BufferedOutput::VERBOSITY_VERBOSE);
        $cli->guard(static function (): int {
            throw new RuntimeException('Loud');
        });
        self::assertStringContainsString('#0', $this->out());
    }

    // ---------------------------------------------------------------- rules

    public function testRulesPrintsATableAndFiltersLikeTheApi(): void
    {
        $this->seedRules([
            ['id' => 'r1', 'source' => '/shop', 'target' => '/store', 'group' => 'relaunch'],
            ['id' => 'r2', 'source' => '/blog/*', 'target' => '/news/$1', 'match_type' => 'wildcard', 'status' => 302],
            ['id' => 'r3', 'source' => '/off', 'target' => '/x', 'enabled' => false],
        ]);

        self::assertSame(ExitCode::OK, $this->cli()->rules([]));
        $text = $this->out();
        foreach (['id', 'source', 'target', 'status', 'type', 'hits', 'badges', 'group', 'r1', '/shop', '/news/$1', 'wildcard', 'relaunch', 'disabled'] as $needle) {
            self::assertStringContainsString($needle, $text);
        }
        self::assertStringContainsString('3 rule(s) in total', $text);

        $this->cli()->rules(['q' => 'shop']);
        $text = $this->out();
        self::assertStringContainsString('/shop', $text);
        self::assertStringNotContainsString('/blog/*', $text);

        $this->cli()->rules(['match_type' => 'wildcard', 'status' => '302']);
        self::assertStringContainsString('r2', $this->out());

        $this->cli()->rules(['state' => 'disabled']);
        $text = $this->out();
        self::assertStringContainsString('r3', $text);
        self::assertStringNotContainsString('r1', $text);

        $this->cli()->rules(['group' => 'relaunch']);
        self::assertStringContainsString('r1', $this->out());
    }

    public function testRulesJsonHasDataAndMetaOnly(): void
    {
        $this->seedRules([
            ['id' => 'r1', 'source' => '/a', 'target' => '/x'],
            ['id' => 'r2', 'source' => '/b', 'target' => '/x'],
            ['id' => 'r3', 'source' => '/c', 'target' => '/x'],
        ]);

        self::assertSame(ExitCode::OK, $this->cli()->rules(['per_page' => '2', 'page' => '2', 'sort' => 'source', 'dir' => 'asc'], true));
        $json = $this->jsonOut();

        self::assertSame(['data', 'meta'], array_keys($json));
        self::assertSame(['total' => 3, 'page' => 2, 'per_page' => 2], $json['meta']);
        self::assertIsArray($json['data']);
        self::assertCount(1, $json['data']);
        self::assertIsArray($json['data'][0]);
        self::assertSame('r3', $json['data'][0]['id']);
        self::assertArrayHasKey('badges', $json['data'][0]);
    }

    public function testRulesRejectsAnUnknownFilterValue(): void
    {
        self::assertSame(ExitCode::INVALID, $this->cli()->rules(['state' => 'sleeping']));
        self::assertStringContainsString('state: "state" must be one of', $this->out());
    }

    public function testRulesSaysWhenNothingMatches(): void
    {
        self::assertSame(ExitCode::OK, $this->cli()->rules([]));
        self::assertStringContainsString('No rules match.', $this->out());
    }

    // ---------------------------------------------------------------- add

    public function testAddCreatesARuleAndPrintsItsId(): void
    {
        self::assertSame(ExitCode::OK, $this->cli()->add('/old', '/new', '301', 'exact', 'relaunch', 'Moved', '5', false, false));

        $rules = $this->app->rules()->all();
        self::assertCount(1, $rules);
        self::assertSame('/old', $rules[0]->source);
        self::assertSame(301, $rules[0]->status->value);
        self::assertSame('relaunch', $rules[0]->group);
        self::assertSame('Moved', $rules[0]->note);
        self::assertSame(5, $rules[0]->priority);
        self::assertStringContainsString('Created rule ' . $rules[0]->id, $this->out());
    }

    public function testAddPrintsWarningsOfAValidRule(): void
    {
        $this->seedRules([['id' => 'r1', 'source' => '/b', 'target' => '/c']]);

        self::assertSame(ExitCode::OK, $this->cli()->add('/a', '/b', null, 'exact', null, null, null, false, false));
        self::assertMatchesRegularExpression('/Warning: .*\(chain\)/', $this->out());
    }

    public function testAddDryRunSavesNothing(): void
    {
        self::assertSame(ExitCode::OK, $this->cli()->add('/old', '/new', null, 'exact', null, null, null, true, false));
        self::assertStringContainsString('Dry run', $this->out());
        self::assertSame([], $this->storedIds());
    }

    public function testAddDryRunOfAnInvalidRuleExitsWithInvalid(): void
    {
        self::assertSame(ExitCode::INVALID, $this->cli()->add('/a', '/a', null, 'exact', null, null, null, true, false));
        $text = $this->out();
        self::assertStringContainsString('target:', $text);
        self::assertStringContainsString('(self_redirect)', $text);
        self::assertSame([], $this->storedIds());

        $code = $this->cli()->add('/a', '/a', null, 'exact', null, null, null, true, true);
        $json = $this->jsonOut();
        self::assertSame(ExitCode::INVALID, $code);
        self::assertFalse($json['valid']);
        self::assertTrue($json['dry_run']);
        self::assertIsArray($json['issues']);
        self::assertSame('self_redirect', $json['issues'][0]['code'] ?? null);
    }

    public function testAddRejectsLoopsAndBadValues(): void
    {
        self::assertSame(ExitCode::INVALID, $this->cli()->add('/a', '/a', null, 'exact', null, null, null, false, false));
        self::assertStringContainsString('(self_redirect)', $this->out());

        self::assertSame(ExitCode::INVALID, $this->cli()->add('/a', '/b', '999', 'exact', null, null, null, false, false));
        self::assertStringContainsString('status: "status" must be one of', $this->out());

        self::assertSame(ExitCode::INVALID, $this->cli()->add('/a', '/b', null, 'glob', null, null, null, false, false));
        self::assertStringContainsString('match_type', $this->out());

        self::assertSame(ExitCode::INVALID, $this->cli()->add('/a', '/b', null, 'exact', null, null, 'high', false, false));
        self::assertStringContainsString('priority', $this->out());
        self::assertSame([], $this->storedIds());
    }

    public function testAddJsonPrintsTheRuleAndIssues(): void
    {
        self::assertSame(ExitCode::OK, $this->cli()->add('/old', '/new', '308', 'exact', null, null, null, false, true));
        $json = $this->jsonOut();
        self::assertIsArray($json['data']);
        self::assertSame('/old', $json['data']['source']);
        self::assertSame(308, $json['data']['status']);
        self::assertSame([], $json['issues']);
    }

    // ---------------------------------------------------------------- remove

    public function testRemoveDeletesAndReportsUnknownIdsWithExitCode4(): void
    {
        $this->seedRules([
            ['id' => 'r1', 'source' => '/a', 'target' => '/x'],
            ['id' => 'r2', 'source' => '/b', 'target' => '/x'],
        ]);

        self::assertSame(ExitCode::NOT_FOUND, $this->cli()->remove(['r1', 'ghost']));
        $text = $this->out();
        self::assertStringContainsString('Deleted 1 rule(s).', $text);
        self::assertStringContainsString('Rule "ghost" was not found.', $text);
        self::assertSame(['r2'], $this->storedIds());

        self::assertSame(ExitCode::OK, $this->cli()->remove(['r2']));
        self::assertSame([], $this->storedIds());
    }

    public function testRemoveWithoutIdsIsInvalid(): void
    {
        self::assertSame(ExitCode::INVALID, $this->cli()->remove([]));
    }

    // ---------------------------------------------------------------- enable / disable

    public function testDisableAndEnableSwitchTheRules(): void
    {
        $this->seedRules([
            ['id' => 'r1', 'source' => '/a', 'target' => '/x'],
            ['id' => 'r2', 'source' => '/b', 'target' => '/x'],
        ]);

        self::assertSame(ExitCode::OK, $this->cli()->setEnabled(['r1', 'r2'], false));
        self::assertStringContainsString('Disabled 2 rule(s).', $this->out());
        self::assertFalse($this->app->rules()->find('r1')->enabled);

        self::assertSame(ExitCode::OK, $this->cli()->setEnabled(['r1'], true));
        self::assertStringContainsString('Enabled 1 rule(s).', $this->out());
        self::assertTrue($this->app->rules()->find('r1')->enabled);
        self::assertFalse($this->app->rules()->find('r2')->enabled);
    }

    public function testEnableReportsUnknownIdsAndInvalidRules(): void
    {
        $this->seedRules([
            ['id' => 'ok', 'source' => '/a', 'target' => '/x', 'enabled' => false],
            ['id' => 'loop', 'source' => '/l', 'target' => '/l', 'enabled' => false],
        ]);

        self::assertSame(ExitCode::NOT_FOUND, $this->cli()->setEnabled(['ok', 'ghost'], true));
        self::assertStringContainsString('Rule "ghost" was not found.', $this->out());
        self::assertTrue($this->app->rules()->find('ok')->enabled);

        self::assertSame(ExitCode::INVALID, $this->cli()->setEnabled(['loop', 'ghost'], true));
        $text = $this->out();
        self::assertStringContainsString('Rule "loop" was skipped', $text);
        self::assertStringContainsString('(self_redirect)', $text);
        self::assertFalse($this->app->rules()->find('loop')->enabled);
    }

    // ---------------------------------------------------------------- test

    public function testTestPrintsTheChainAndTheFinalStatus(): void
    {
        $this->seedRules([
            ['id' => 'r1', 'source' => '/a', 'target' => '/b', 'status' => 301],
            ['id' => 'r2', 'source' => '/b', 'target' => '/c', 'status' => 302],
        ]);

        self::assertSame(ExitCode::OK, $this->cli()->test('/a', null, null, null, null, null));
        $text = $this->out();
        self::assertStringContainsString('GET /a (phase any)', $text);
        self::assertStringContainsString('1 /a 301 r1 /b', $text);
        self::assertStringContainsString('2 /b 302 r2 /c', $text);
        foreach (['/a', '/b', '/c', '301', '302', 'r1', 'r2', 'Final: 404 for /c'] as $needle) {
            self::assertStringContainsString($needle, $text);
        }
    }

    public function testTestExpectationsPassAndFail(): void
    {
        $this->seedRules([['id' => 'r1', 'source' => '/old', 'target' => '/new', 'status' => 301]]);

        self::assertSame(ExitCode::OK, $this->cli()->test('/old', '301', '/new', null, null, null));
        self::assertSame(ExitCode::OK, $this->cli()->test('/old', null, null, null, null, null));

        self::assertSame(ExitCode::MISMATCH, $this->cli()->test('/old', '302', null, null, null, null));
        self::assertStringContainsString('Expected status 302, found 301.', $this->out());

        self::assertSame(ExitCode::MISMATCH, $this->cli()->test('/old', null, '/other', null, null, null));
        self::assertStringContainsString('Expected location "/other", found "/new".', $this->out());

        self::assertSame(ExitCode::MISMATCH, $this->cli()->test('/nothing', '301', null, null, null, null));
        self::assertStringContainsString('Expected status 301, found 404.', $this->out());
    }

    public function testTestRejectsABadExpectedStatusAndBadPhase(): void
    {
        self::assertSame(ExitCode::INVALID, $this->cli()->test('/old', 'abc', null, null, null, null));
        self::assertStringContainsString('expect-status', $this->out());

        self::assertSame(ExitCode::INVALID, $this->cli()->test('/old', null, null, null, null, 'sometime'));
        self::assertStringContainsString('phase', $this->out());
    }

    public function testTestJsonCarriesTheChainAndTheExpectationResult(): void
    {
        $this->seedRules([['id' => 'r1', 'source' => '/old', 'target' => '/new', 'status' => 301]]);

        $code = $this->cli()->test('/old', '302', null, 'GET', null, 'early', true);
        $json = $this->jsonOut();

        self::assertSame(ExitCode::MISMATCH, $code);
        self::assertIsArray($json['chain']);
        self::assertSame('/new', $json['chain'][0]['location']);
        self::assertSame(['ok' => false, 'mismatches' => ['Expected status 302, found 301.']], $json['expect']);
        self::assertSame('early', $json['input']['phase']);
    }

    public function testTestJsonWithoutExpectationsHasNoExpectKey(): void
    {
        $this->cli()->test('/old', null, null, null, null, null, true);
        self::assertArrayNotHasKey('expect', $this->jsonOut());
    }

    // ---------------------------------------------------------------- import

    private function writeFile(string $name, string $content): string
    {
        $path = $this->tmp . '/' . $name;
        file_put_contents($path, $content);

        return $path;
    }

    public function testImportDryRunPrintsCountsAndSavesNothing(): void
    {
        $file = $this->writeFile('ok.csv', "source,target,status\n/a,/x,301\n/b,/y,302\n");

        self::assertSame(ExitCode::OK, $this->cli()->import($file, null, true, true, false));
        $text = $this->out();
        self::assertStringContainsString('Dry run of ok.csv (format csv)', $text);
        self::assertStringContainsString('Rows 2', $text);
        self::assertStringContainsString('Valid 2', $text);
        self::assertSame([], $this->storedIds());
    }

    public function testImportDryRunWithInvalidRowsExitsWithInvalidAndListsThem(): void
    {
        $file = $this->writeFile('bad.csv', "source,target,status\n/a,/x,301\n/loop,/loop,301\n/c,/z,999\n");

        self::assertSame(ExitCode::INVALID, $this->cli()->import($file, 'csv', true, true, false));
        $text = $this->out();
        self::assertStringContainsString('Invalid 2', $text);
        self::assertStringContainsString('Rows with problems', $text);
        self::assertStringContainsString('/loop,/loop,301', $text);
    }

    public function testImportDryRunJsonIsThePreview(): void
    {
        $file = $this->writeFile('ok.csv', "source,target,status\n/a,/x,301\n");

        self::assertSame(ExitCode::OK, $this->cli()->import($file, null, true, true, false, true));
        $json = $this->jsonOut();
        self::assertSame(1, $json['counts']['valid'] ?? null);
        self::assertSame('csv', $json['format']);
    }

    public function testImportCommitCreatesRulesAndSkipsDuplicates(): void
    {
        $this->seedRules([['id' => 'r1', 'source' => '/a', 'target' => '/x']]);
        $file = $this->writeFile('in.csv', "source,target,status\n/a,/x,301\n/b,/y,302\n");

        self::assertSame(ExitCode::OK, $this->cli()->import($file, null, false, true, false));
        self::assertStringContainsString('1 rule(s) created, 1 row(s) skipped', $this->out());
        self::assertCount(2, $this->storedIds());
        self::assertContains('r1', $this->storedIds());
    }

    public function testImportCommitStopsOnInvalidRowsUnlessSkipped(): void
    {
        $file = $this->writeFile('mixed.csv', "source,target,status\n/a,/x,301\n/loop,/loop,301\n");

        self::assertSame(ExitCode::INVALID, $this->cli()->import($file, null, false, true, false));
        self::assertStringContainsString('The import has invalid rows. rows.3:', $this->out());
        self::assertSame([], $this->storedIds());

        self::assertSame(ExitCode::OK, $this->cli()->import($file, null, false, true, true));
        self::assertStringContainsString('1 rule(s) created', $this->out());
        self::assertCount(1, $this->storedIds());
    }

    public function testImportWithAMissingFileOrUnknownFormatIsInvalid(): void
    {
        self::assertSame(ExitCode::INVALID, $this->cli()->import($this->tmp . '/nope.csv', null, false, true, false));
        self::assertStringContainsString('does not exist or is not readable', $this->out());

        $file = $this->writeFile('x.csv', "source,target\n/a,/b\n");
        self::assertSame(ExitCode::INVALID, $this->cli()->import($file, 'docx', false, true, false));
        self::assertStringContainsString('unknown_format', $this->out());
    }

    // ---------------------------------------------------------------- export

    public function testExportWritesToAFile(): void
    {
        $this->seedRules([
            ['id' => 'r1', 'source' => '/a', 'target' => '/x'],
            ['id' => 'r2', 'source' => '/b', 'target' => '/y', 'enabled' => false],
        ]);
        $file = $this->tmp . '/out.csv';

        self::assertSame(ExitCode::OK, $this->cli()->export('csv', $file, false, null));
        self::assertStringContainsString('Exported 2 rule(s) to ' . $file, $this->out());
        $content = (string) file_get_contents($file);
        self::assertStringContainsString('/a,/x', $content);
        self::assertStringContainsString('/b,/y', $content);

        self::assertSame(ExitCode::OK, $this->cli()->export('csv', $file, true, null));
        $content = (string) file_get_contents($file);
        self::assertStringContainsString('/a,/x', $content);
        self::assertStringNotContainsString('/b,/y', $content);
    }

    public function testExportWritesTheRawContentToStdout(): void
    {
        $this->seedRules([['id' => 'r1', 'source' => '/a', 'target' => '/x', 'note' => '<info>tag</info>', 'group' => 'g']]);

        self::assertSame(ExitCode::OK, $this->cli()->export('csv', null, false, 'g'));
        $text = $this->output->fetch();
        self::assertStringStartsWith('source,target,status', $text);
        self::assertStringContainsString('<info>tag</info>', $text);
    }

    public function testExportJsonWithOutputPrintsFileFacts(): void
    {
        $this->seedRules([['id' => 'r1', 'source' => '/a', 'target' => '/x']]);
        $file = $this->tmp . '/out.json';

        self::assertSame(ExitCode::OK, $this->cli()->export('json', $file, false, null, true));
        $json = $this->jsonOut();

        self::assertSame($file, $json['output']);
        self::assertSame(1, $json['exported']);
        self::assertSame('application/json', $json['mime']);
        self::assertArrayNotHasKey('content', $json);
        self::assertFileExists($file);
    }

    public function testExportRejectsAnUnknownFormatAndAnUnwritableFile(): void
    {
        self::assertSame(ExitCode::INVALID, $this->cli()->export('docx', null, false, null));
        self::assertStringContainsString('Unknown export format.', $this->out());

        self::assertSame(ExitCode::INVALID, $this->cli()->export('crawler_csv', null, false, null));

        self::assertSame(ExitCode::ERROR, $this->cli()->export('csv', $this->tmp . '/missing-dir/out.csv', false, null));
        self::assertStringContainsString('could not be written', $this->out());
    }

    // ---------------------------------------------------------------- suggest

    private function logNotFound(string $path): void
    {
        $this->app->services()->logStore()->append(new NotFoundEntry(
            $this->clock->now()->modify('-1 hour'),
            $path,
            '',
            '',
            'UA/1.0',
            UserAgentClass::Browser,
            null,
            'en',
            'example.org',
            'GET',
        ));
    }

    public function testSuggestDryRunStoresNothing(): void
    {
        $this->app = $this->makeApp([], PageTreeFixture::pages());
        $this->logNotFound('/blog/my-post.html');

        self::assertSame(ExitCode::OK, $this->cli()->suggest('30', null, false, true));
        $text = $this->out();
        self::assertStringContainsString('Dry run, nothing was saved.', $text);
        self::assertStringContainsString('1 path(s) looked at: 1 suggestion(s) would be stored', $text);
        self::assertStringContainsString('/blog/my-post.html', $text);
        self::assertStringContainsString('Would accept 1 suggestion(s)', $text);
        self::assertSame([], $this->app->services()->suggestionStore()->all());
        self::assertSame([], $this->storedIds());
    }

    public function testSuggestStoresAndAcceptsAboveTheScore(): void
    {
        $this->app = $this->makeApp([], PageTreeFixture::pages());
        $this->logNotFound('/blog/my-post.html');

        self::assertSame(ExitCode::OK, $this->cli()->suggest('30', '0.5', false, false));
        self::assertStringContainsString('Accepted 1 suggestion(s) with a score of at least 0.5 as rules', $this->out());
        self::assertCount(1, $this->storedIds());
    }

    public function testSuggestNoAcceptOnlyGenerates(): void
    {
        $this->app = $this->makeApp([], PageTreeFixture::pages());
        $this->logNotFound('/blog/my-post.html');

        self::assertSame(ExitCode::OK, $this->cli()->suggest('30', null, true, false, true));
        $json = $this->jsonOut();

        self::assertNull($json['accept']);
        self::assertFalse($json['dry_run']);
        self::assertIsArray($json['generate']);
        self::assertSame(1, $json['generate']['suggested']);
        self::assertCount(1, $this->app->services()->suggestionStore()->all());
        self::assertSame([], $this->storedIds());
    }

    public function testSuggestValidatesItsOptions(): void
    {
        self::assertSame(ExitCode::INVALID, $this->cli()->suggest('0', null, false, true));
        self::assertSame(ExitCode::INVALID, $this->cli()->suggest('abc', null, false, true));
        self::assertSame(ExitCode::INVALID, $this->cli()->suggest('30', 'high', false, true));
        self::assertSame(ExitCode::INVALID, $this->cli()->suggest('30', '1.5', false, true));
        self::assertSame(ExitCode::INVALID, $this->cli()->suggest('999', null, false, true));
    }

    // ---------------------------------------------------------------- check-targets

    private function withHttp(int $code): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse('', ['http_code' => $code]));
        $this->app = $this->makeApp([], null, null, $client);
    }

    public function testCheckTargetsWithADeadTargetExitsWith3(): void
    {
        $this->withHttp(404);
        $this->seedRules([['id' => 'r1', 'source' => '/a', 'target' => '/gone']]);

        self::assertSame(ExitCode::MISMATCH, $this->cli()->checkTargets());
        $text = $this->out();
        self::assertStringContainsString('r1', $text);
        self::assertStringContainsString('/a', $text);
        self::assertStringContainsString('/gone', $text);
        self::assertStringContainsString('404', $text);
        self::assertStringContainsString('Checked 1 target(s), 1 dead.', $text);
    }

    public function testCheckTargetsWithLiveTargetsExitsWith0(): void
    {
        $this->withHttp(200);
        $this->seedRules([['id' => 'r1', 'source' => '/a', 'target' => '/here']]);

        self::assertSame(ExitCode::OK, $this->cli()->checkTargets());
        self::assertStringContainsString('Checked 1 target(s), none is dead.', $this->out());
    }

    public function testCheckTargetsJsonListsOnlyDeadTargetsAndIgnoresTheRateLimit(): void
    {
        $this->withHttp(404);
        $this->seedRules([['id' => 'r1', 'source' => '/a', 'target' => '/gone']]);

        self::assertSame(ExitCode::MISMATCH, $this->cli()->checkTargets(true));
        $json = $this->jsonOut();
        self::assertSame(1, $json['checked']);
        self::assertSame(1, $json['dead']);
        self::assertIsArray($json['results']);
        self::assertSame('r1', $json['results'][0]['rule_id']);

        // A second run right after the first must not be rate limited.
        self::assertSame(ExitCode::MISMATCH, $this->cli()->checkTargets(true));
    }

    // ---------------------------------------------------------------- prune

    /**
     * Rules created 200 days ago and never hit count as unused for 180 days.
     */
    private function seedOldRules(): void
    {
        $this->seedRules([
            ['id' => 'old1', 'source' => '/a', 'target' => '/x', 'created_at' => '2026-01-01T00:00:00+00:00'],
            ['id' => 'old2', 'source' => '/b', 'target' => '/x', 'created_at' => '2026-01-01T00:00:00+00:00'],
            ['id' => 'fresh', 'source' => '/c', 'target' => '/x', 'created_at' => '2026-09-20T00:00:00+00:00'],
        ]);
    }

    public function testPruneDryRunListsAndChangesNothing(): void
    {
        $this->seedOldRules();

        self::assertSame(ExitCode::OK, $this->cli()->prune('180', true, false));
        $text = $this->out();
        self::assertStringContainsString('old1', $text);
        self::assertStringContainsString('old2', $text);
        self::assertStringNotContainsString('fresh', $text);
        self::assertStringContainsString('Dry run: 2 rule(s) unused for 180 days would be deleted.', $text);
        self::assertCount(3, $this->storedIds());
    }

    public function testPruneDisablesWithDisableOnly(): void
    {
        $this->seedOldRules();

        self::assertSame(ExitCode::OK, $this->cli()->prune('180', false, true));
        self::assertStringContainsString('Disabled 2 rule(s) unused for 180 days.', $this->out());
        self::assertFalse($this->app->rules()->find('old1')->enabled);
        self::assertTrue($this->app->rules()->find('fresh')->enabled);
    }

    public function testPruneDeletes(): void
    {
        $this->seedOldRules();

        self::assertSame(ExitCode::OK, $this->cli()->prune('180', false, false));
        self::assertStringContainsString('Deleted 2 rule(s) unused for 180 days.', $this->out());
        self::assertSame(['fresh'], $this->storedIds());
    }

    public function testPruneWithNothingToDoAndBadDays(): void
    {
        self::assertSame(ExitCode::OK, $this->cli()->prune('180', false, false));
        self::assertStringContainsString('No rule has been unused for 180 days.', $this->out());

        self::assertSame(ExitCode::INVALID, $this->cli()->prune('0', false, false));
        self::assertSame(ExitCode::INVALID, $this->cli()->prune('many', false, false));
    }

    public function testPruneJson(): void
    {
        $this->seedOldRules();

        self::assertSame(ExitCode::OK, $this->cli()->prune('180', true, false, true));
        $json = $this->jsonOut();
        self::assertTrue($json['dry_run']);
        self::assertSame(2, $json['count']);
        self::assertNull($json['action']);
        self::assertIsArray($json['rules']);
        self::assertCount(2, $json['rules']);

        self::assertSame(ExitCode::OK, $this->cli()->prune('180', false, true, true));
        $json = $this->jsonOut();
        self::assertSame('disable', $json['action']);
        self::assertSame(2, $json['count']);
    }

    // ---------------------------------------------------------------- stats / cache

    public function testStatsPrintsATableAndJson(): void
    {
        $this->seedRules([
            ['id' => 'r1', 'source' => '/a', 'target' => '/x'],
            ['id' => 'r2', 'source' => '/b', 'target' => '/x', 'enabled' => false],
        ]);

        self::assertSame(ExitCode::OK, $this->cli()->stats());
        $text = $this->out();
        self::assertStringContainsString('metric', $text);
        self::assertMatchesRegularExpression('/Rules 2/', $text);
        self::assertMatchesRegularExpression('/Active rules 1/', $text);

        self::assertSame(ExitCode::OK, $this->cli()->stats(true));
        $json = $this->jsonOut();
        self::assertSame(2, $json['rules_total']);
        self::assertSame(1, $json['rules_active']);
        self::assertArrayHasKey('not_found_by_day', $json);
    }

    public function testRebuildCachePrintsTheNumberOfCompiledRules(): void
    {
        $this->seedRules([
            ['id' => 'r1', 'source' => '/a', 'target' => '/x'],
            ['id' => 'r2', 'source' => '/b', 'target' => '/x'],
        ]);

        self::assertSame(ExitCode::OK, $this->cli()->rebuildCache());
        self::assertStringContainsString('Rebuilt the rule cache: 2 compiled rule(s).', $this->out());
    }

    public function testExitCodeValues(): void
    {
        self::assertSame([0, 1, 2, 3, 4], [ExitCode::OK, ExitCode::ERROR, ExitCode::INVALID, ExitCode::MISMATCH, ExitCode::NOT_FOUND]);
    }
}
