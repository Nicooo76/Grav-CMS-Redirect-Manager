<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Integration;

use Grav\Plugin\RedirectManager\Cli\ExitCode;
use Grav\Plugin\RedirectManager\Tests\Integration\Support\CliRunner;
use PHPUnit\Framework\Attributes\Group;

/**
 * The console commands (`bin/plugin redirect-manager ...`) in a real Grav site: boot, exit codes, stdout/stderr.
 */
#[Group('cli')]
final class CliTest extends IntegrationTestCase
{
    /**
     * @param list<string> $args
     *
     * @return array{code: int, stdout: string, stderr: string}
     */
    private function cli(array $args): array
    {
        return (new CliRunner($this->site()))->run($args);
    }

    /**
     * @param list<string> $args
     *
     * @return array<string, mixed>
     */
    private function cliJson(array $args, int $expectedCode = ExitCode::OK): array
    {
        $result = $this->cli($args);
        self::assertSame($expectedCode, $result['code'], $result['stdout'] . $result['stderr']);
        $decoded = json_decode($result['stdout'], true);
        self::assertIsArray($decoded, 'stdout is not JSON: ' . $result['stdout'] . $result['stderr']);

        return $decoded;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function storedRules(): array
    {
        $rules = [];
        foreach ($this->site()->repository()->all() as $rule) {
            $rules[] = $rule->toArray();
        }

        return $rules;
    }

    public function testRulesJsonListsTheStoredRules(): void
    {
        $this->rules([
            ['id' => 'r1', 'source' => '/a', 'target' => '/x', 'status' => 301],
            ['id' => 'r2', 'source' => '/b', 'target' => '/y', 'status' => 302, 'group' => 'g'],
        ]);

        $json = $this->cliJson(['rules', '--json', '--sort=source', '--dir=asc']);

        self::assertSame(['data', 'meta'], array_keys($json));
        self::assertSame(['total' => 2, 'page' => 1, 'per_page' => 50], $json['meta']);
        self::assertIsArray($json['data']);
        self::assertSame(['r1', 'r2'], array_column($json['data'], 'id'));
    }

    public function testRulesTableAndTheAliasRedirectsList(): void
    {
        $this->rules([['id' => 'r1', 'source' => '/a', 'target' => '/x']]);

        $short = $this->cli(['rules', '--q=/a']);
        $alias = $this->cli(['redirects:list', '--q=/a']);

        self::assertSame(ExitCode::OK, $short['code'], $short['stderr']);
        self::assertStringContainsString('r1', $short['stdout']);
        self::assertStringContainsString('/x', $short['stdout']);
        self::assertSame(ExitCode::OK, $alias['code'], $alias['stderr']);
        self::assertSame($short['stdout'], $alias['stdout']);
    }

    public function testRulesRejectsABadFilter(): void
    {
        $result = $this->cli(['rules', '--state=sleeping']);

        self::assertSame(ExitCode::INVALID, $result['code']);
        self::assertStringContainsString('state', $result['stderr']);
        self::assertSame('', trim($result['stdout']));
    }

    public function testAddCreatesARuleThatTheFrontendServesRightAway(): void
    {
        $result = $this->cli(['add', '/cli-old', '/cli-new', '--status=301', '--group=cli']);

        self::assertSame(ExitCode::OK, $result['code'], $result['stderr']);
        self::assertStringContainsString('Created rule', $result['stdout']);
        $rules = $this->storedRules();
        self::assertCount(1, $rules);
        self::assertSame('/cli-old', $rules[0]['source']);
        self::assertSame('cli', $rules[0]['group']);
        $this->assertRedirect($this->get('/cli-old'), 301, '/cli-new');
    }

    public function testAddDryRunSavesNothing(): void
    {
        $result = $this->cli(['add', '/cli-old', '/cli-new', '--dry-run', '--json']);

        self::assertSame(ExitCode::OK, $result['code'], $result['stderr']);
        $json = json_decode($result['stdout'], true);
        self::assertIsArray($json);
        self::assertTrue($json['valid']);
        self::assertSame([], $this->storedRules());
    }

    public function testAddALoopExitsWith2AndExplainsOnStderr(): void
    {
        $result = $this->cli(['add', '/loop', '/loop']);

        self::assertSame(ExitCode::INVALID, $result['code']);
        self::assertStringContainsString('self_redirect', $result['stderr']);
        self::assertSame('', trim($result['stdout']));
        self::assertSame([], $this->storedRules());
    }

    public function testRemoveDeletesAndUnknownIdsExitWith4(): void
    {
        $this->rules([
            ['id' => 'r1', 'source' => '/a', 'target' => '/x'],
            ['id' => 'r2', 'source' => '/b', 'target' => '/y'],
        ]);

        $result = $this->cli(['remove', 'r1', 'ghost']);
        self::assertSame(ExitCode::NOT_FOUND, $result['code']);
        self::assertStringContainsString('Deleted 1 rule(s).', $result['stdout']);
        self::assertStringContainsString('ghost', $result['stderr']);
        self::assertSame(['r2'], array_column($this->storedRules(), 'id'));

        self::assertSame(ExitCode::OK, $this->cli(['remove', 'r2'])['code']);
        self::assertSame([], $this->storedRules());
    }

    public function testDisableAndEnableChangeWhatTheFrontendServes(): void
    {
        $this->rules([['id' => 'r1', 'source' => '/toggle', 'target' => '/x', 'status' => 301]]);
        $this->assertRedirect($this->get('/toggle'), 301, '/x');

        $off = $this->cli(['disable', 'r1']);
        self::assertSame(ExitCode::OK, $off['code'], $off['stderr']);
        self::assertStringContainsString('Disabled 1 rule(s).', $off['stdout']);
        $this->assertNotRedirected($this->get('/toggle'));

        $on = $this->cli(['enable', 'r1']);
        self::assertSame(ExitCode::OK, $on['code'], $on['stderr']);
        self::assertStringContainsString('Enabled 1 rule(s).', $on['stdout']);
        $this->assertRedirect($this->get('/toggle'), 301, '/x');

        self::assertSame(ExitCode::NOT_FOUND, $this->cli(['enable', 'ghost'])['code']);
    }

    public function testTestChecksExpectations(): void
    {
        $this->rules([['id' => 'r1', 'source' => '/old', 'target' => '/new', 'status' => 301]]);

        $ok = $this->cli(['test', '/old', '--expect-status=301', '--expect-location=/new']);
        self::assertSame(ExitCode::OK, $ok['code'], $ok['stderr']);
        self::assertStringContainsString('r1', $ok['stdout']);

        $mismatch = $this->cli(['test', '/old', '--expect-status=302']);
        self::assertSame(ExitCode::MISMATCH, $mismatch['code']);
        self::assertStringContainsString('Expected status 302, found 301.', $mismatch['stderr']);

        $json = $this->cliJson(['test', '/old', '--json']);
        self::assertIsArray($json['chain']);
        self::assertSame(301, $json['chain'][0]['status']);
    }

    public function testImportDryRunAndCommit(): void
    {
        $this->site()->writeFile('import.csv', "source,target,status\n/i1,/x,301\n/i2,/y,302\n");
        $file = $this->site()->dir . '/import.csv';

        $dry = $this->cli(['import', $file, '--dry-run']);
        self::assertSame(ExitCode::OK, $dry['code'], $dry['stderr']);
        self::assertStringContainsString('Dry run of import.csv', $dry['stdout']);
        self::assertSame([], $this->storedRules());

        $commit = $this->cli(['import', $file]);
        self::assertSame(ExitCode::OK, $commit['code'], $commit['stderr']);
        self::assertStringContainsString('2 rule(s) created', $commit['stdout']);
        self::assertSame(['/i1', '/i2'], array_column($this->storedRules(), 'source'));
        $this->assertRedirect($this->get('/i1'), 301, '/x');

        // Same file again: the duplicates are skipped by default.
        $again = $this->cli(['import', $file]);
        self::assertSame(ExitCode::OK, $again['code'], $again['stderr']);
        self::assertStringContainsString('0 rule(s) created, 2 row(s) skipped', $again['stdout']);
        self::assertCount(2, $this->storedRules());
    }

    public function testImportWithInvalidRowsExitsWith2(): void
    {
        $this->site()->writeFile('bad.csv', "source,target,status\n/ok,/x,301\n/loop,/loop,301\n");
        $file = $this->site()->dir . '/bad.csv';

        self::assertSame(ExitCode::INVALID, $this->cli(['import', $file, '--dry-run'])['code']);
        $commit = $this->cli(['import', $file]);
        self::assertSame(ExitCode::INVALID, $commit['code']);
        self::assertSame([], $this->storedRules());

        $skip = $this->cli(['import', $file, '--skip-invalid']);
        self::assertSame(ExitCode::OK, $skip['code'], $skip['stderr']);
        self::assertSame(['/ok'], array_column($this->storedRules(), 'source'));

        $missing = $this->cli(['import', $this->site()->dir . '/nope.csv']);
        self::assertSame(ExitCode::INVALID, $missing['code']);
        self::assertStringContainsString('does not exist', $missing['stderr']);
    }

    public function testExportWritesAFileAndToStdout(): void
    {
        $this->rules([['id' => 'r1', 'source' => '/e1', 'target' => '/x', 'status' => 301]]);
        $file = $this->site()->dir . '/export.csv';

        $result = $this->cli(['export', '--output=' . $file]);
        self::assertSame(ExitCode::OK, $result['code'], $result['stderr']);
        self::assertStringContainsString('Exported 1 rule(s)', $result['stdout']);
        self::assertStringContainsString('/e1,/x,301', (string) file_get_contents($file));

        $stdout = $this->cli(['export', '--format=csv']);
        self::assertSame(ExitCode::OK, $stdout['code'], $stdout['stderr']);
        self::assertStringStartsWith('source,target,status', $stdout['stdout']);

        $facts = $this->cliJson(['export', '--format=json', '--output=' . $this->site()->dir . '/export.json', '--json']);
        self::assertSame(1, $facts['exported']);
        self::assertSame('application/json', $facts['mime']);

        self::assertSame(ExitCode::INVALID, $this->cli(['export', '--format=docx'])['code']);
    }

    public function testSuggestDryRun(): void
    {
        $json = $this->cliJson(['suggest', '--dry-run', '--json']);

        self::assertTrue($json['dry_run']);
        self::assertIsArray($json['generate']);
        self::assertSame(0, $json['generate']['paths']);
        self::assertSame([], $this->storedRules());

        self::assertSame(ExitCode::INVALID, $this->cli(['suggest', '--days=0'])['code']);
    }

    public function testCheckTargetsFindsADeadInternalTarget(): void
    {
        $this->rules([
            ['id' => 'live', 'source' => '/c-live', 'target' => '/typography', 'status' => 301],
            ['id' => 'dead', 'source' => '/c-dead', 'target' => '/no-such-page-anywhere', 'status' => 301],
        ]);
        $base = '--base-url=http://127.0.0.1:' . $this->site()->port;

        $result = $this->cli(['check-targets', $base]);
        self::assertSame(ExitCode::MISMATCH, $result['code'], $result['stdout'] . $result['stderr']);
        self::assertStringContainsString('dead', $result['stdout']);
        self::assertStringContainsString('/no-such-page-anywhere', $result['stdout']);
        self::assertStringNotContainsString('live', $result['stdout']);

        $json = $this->cliJson(['check-targets', $base, '--json'], ExitCode::MISMATCH);
        self::assertSame(1, $json['dead']);
        self::assertIsArray($json['results']);
        self::assertSame('dead', $json['results'][0]['rule_id']);

        $this->rules([['id' => 'live', 'source' => '/c-live', 'target' => '/typography', 'status' => 301]]);
        $clean = $this->cli(['check-targets', $base]);
        self::assertSame(ExitCode::OK, $clean['code'], $clean['stdout'] . $clean['stderr']);
    }

    public function testPruneDryRunListsUnusedRules(): void
    {
        $this->rules([
            ['id' => 'old', 'source' => '/p-old', 'target' => '/x', 'created_at' => '2020-01-01T00:00:00+00:00'],
            ['id' => 'new', 'source' => '/p-new', 'target' => '/x'],
        ]);

        $dry = $this->cli(['prune', '--dry-run']);
        self::assertSame(ExitCode::OK, $dry['code'], $dry['stderr']);
        self::assertStringContainsString('/p-old', $dry['stdout']);
        self::assertStringNotContainsString('/p-new', $dry['stdout']);
        self::assertCount(2, $this->storedRules());

        $delete = $this->cli(['prune']);
        self::assertSame(ExitCode::OK, $delete['code'], $delete['stderr']);
        self::assertStringContainsString('Deleted 1 rule(s)', $delete['stdout']);
        self::assertSame(['new'], array_column($this->storedRules(), 'id'));
    }

    public function testPruneCanDisableInsteadOfDelete(): void
    {
        $this->rules([
            ['id' => 'old', 'source' => '/p-old', 'target' => '/x', 'created_at' => '2020-01-01T00:00:00+00:00'],
            ['id' => 'new', 'source' => '/p-new', 'target' => '/x'],
        ]);

        $disable = $this->cli(['prune', '--disable-only']);

        self::assertSame(ExitCode::OK, $disable['code'], $disable['stderr']);
        self::assertStringContainsString('Disabled 1 rule(s)', $disable['stdout']);
        $enabled = array_column($this->storedRules(), 'enabled', 'id');
        self::assertFalse($enabled['old']);
        self::assertTrue($enabled['new']);
    }

    public function testStatsJson(): void
    {
        $this->rules([['id' => 'r1', 'source' => '/a', 'target' => '/x']]);

        $json = $this->cliJson(['stats', '--json']);

        self::assertSame(1, $json['rules_total']);
        self::assertSame(1, $json['rules_active']);
    }

    public function testRebuildCache(): void
    {
        $this->rules([
            ['id' => 'r1', 'source' => '/a', 'target' => '/x'],
            ['id' => 'r2', 'source' => '/b', 'target' => '/x'],
        ]);

        $result = $this->cli(['rebuild-cache']);

        self::assertSame(ExitCode::OK, $result['code'], $result['stderr']);
        self::assertStringContainsString('2 compiled rule(s)', $result['stdout']);
    }
}
