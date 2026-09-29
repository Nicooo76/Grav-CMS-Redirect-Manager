<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Cli;

use Grav\Plugin\RedirectManager\Analysis\ValidationIssue;
use Grav\Plugin\RedirectManager\App\Exception\InvalidInputException;
use Grav\Plugin\RedirectManager\App\Exception\PayloadTooLargeException;
use Grav\Plugin\RedirectManager\App\Exception\RateLimitedException;
use Grav\Plugin\RedirectManager\App\Exception\ResourceNotFoundException;
use Grav\Plugin\RedirectManager\App\Exception\RevisionConflictException;
use Grav\Plugin\RedirectManager\App\Exception\RuleValidationException;
use Grav\Plugin\RedirectManager\App\Exception\UnavailableException;
use Grav\Plugin\RedirectManager\App\RedirectService;
use Grav\Plugin\RedirectManager\App\RuleQuery;
use Grav\Plugin\RedirectManager\Domain\Rule;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * The logic of the `redirect-manager` console commands, without Grav: one method per command, taking the plain
 * option values and returning an ExitCode. The command classes in cli/ only read their input and call these.
 *
 * Output rules: with $json the only thing written to stdout is one pretty printed JSON document; messages about
 * problems always go to stderr (guard()). Values that come straight from the command line are strings and are
 * validated here, so a bad value ends as ExitCode::INVALID with a message, not as a PHP error.
 */
final class CliCommands
{
    private const JSON_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;
    private const PREVIEW_ROWS = 20;

    public function __construct(
        private readonly RedirectService $app,
        private readonly SymfonyStyle $io,
    ) {
    }

    // ---------------------------------------------------------------- guard

    /**
     * Runs $command and turns the application exceptions into a message on stderr and an exit code.
     *
     * @param callable(): int $command
     */
    public function guard(callable $command): int
    {
        try {
            return $command();
        } catch (RuleValidationException $e) {
            $this->fail($e->getMessage(), $e->issues);

            return ExitCode::INVALID;
        } catch (InvalidInputException $e) {
            $this->fail($e->getMessage(), $e->issues);

            return ExitCode::INVALID;
        } catch (PayloadTooLargeException | RevisionConflictException $e) {
            $this->fail($e->getMessage());

            return ExitCode::INVALID;
        } catch (ResourceNotFoundException $e) {
            $this->fail($e->getMessage());

            return ExitCode::NOT_FOUND;
        } catch (RateLimitedException | UnavailableException $e) {
            $this->fail($e->getMessage());

            return ExitCode::ERROR;
        } catch (Throwable $e) {
            $this->fail($e->getMessage() !== '' ? $e->getMessage() : $e::class);
            if ($this->io->isVerbose()) {
                $this->err()->writeln($e::class . ' in ' . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString());
            }

            return ExitCode::ERROR;
        }
    }

    // ---------------------------------------------------------------- rules

    /**
     * @param array<string, string|null> $filters API style keys: q, match_type, status, state, badge, group, origin, unused_days, sort, dir, per_page, page
     */
    public function rules(array $filters, bool $json = false): int
    {
        return $this->guard(function () use ($filters, $json): int {
            $query = RuleQuery::fromArray(array_filter($filters, static fn (?string $v): bool => $v !== null), 50);
            $result = $this->app->rules()->list($query);

            if ($json) {
                $this->json(['data' => $result['rows'], 'meta' => ['total' => $result['total'], 'page' => $result['page'], 'per_page' => $result['per_page']]]);

                return ExitCode::OK;
            }
            if ($result['rows'] === []) {
                $this->io->writeln($result['total'] === 0 ? 'No rules match.' : 'No rules on this page.');

                return ExitCode::OK;
            }

            $rows = [];
            foreach ($result['rows'] as $row) {
                $rows[] = [
                    self::str($row['id'] ?? ''),
                    self::str($row['source'] ?? ''),
                    self::str($row['target'] ?? ''),
                    self::str($row['status'] ?? ''),
                    self::str($row['match_type'] ?? ''),
                    self::str(is_array($row['stats'] ?? null) ? ($row['stats']['total'] ?? 0) : 0),
                    implode(', ', self::strings($row['badges'] ?? [])),
                    self::str($row['group'] ?? ''),
                ];
            }
            $this->io->table(['id', 'source', 'target', 'status', 'type', 'hits', 'badges', 'group'], $rows);
            $this->io->writeln(sprintf('%d rule(s) in total, page %d, %d per page.', $result['total'], $result['page'], $result['per_page']));

            return ExitCode::OK;
        });
    }

    public function add(string $source, string $target, ?string $status, string $type, ?string $group, ?string $note, ?string $priority, bool $dryRun, bool $json): int
    {
        return $this->guard(function () use ($source, $target, $status, $type, $group, $note, $priority, $dryRun, $json): int {
            $body = ['source' => $source, 'target' => $target, 'match_type' => $type];
            foreach (['status' => $status, 'group' => $group, 'note' => $note, 'priority' => $priority] as $field => $value) {
                if ($value !== null) {
                    $body[$field] = $value;
                }
            }

            if ($dryRun) {
                $checked = $this->app->rules()->validate($body);
                $issues = $checked['issues'];
                $valid = !self::hasError($issues);
                if ($json) {
                    $this->json(['dry_run' => true, 'valid' => $valid, 'data' => $checked['rule'], 'issues' => self::issueRows($issues)]);

                    return $valid ? ExitCode::OK : ExitCode::INVALID;
                }
                if (!$valid) {
                    $this->fail('The rule is invalid.', $issues);

                    return ExitCode::INVALID;
                }
                $this->io->writeln(sprintf('Dry run: the rule %s -> %s is valid, nothing was saved.', $source, $target));
                $this->printWarnings($issues);

                return ExitCode::OK;
            }

            $created = $this->app->rules()->create($body);
            $rule = $created['rule'];
            if ($json) {
                $this->json(['data' => $this->app->rules()->plain($rule), 'issues' => self::issueRows($created['issues'])]);

                return ExitCode::OK;
            }
            $this->io->writeln(sprintf('Created rule %s: %s -> %s (%d).', $rule->id, $rule->source, $rule->target, $rule->status->value));
            $this->printWarnings($created['issues']);

            return ExitCode::OK;
        });
    }

    /**
     * @param list<string> $ids
     */
    public function remove(array $ids): int
    {
        return $this->guard(function () use ($ids): int {
            $result = $this->app->rules()->bulk('delete', $ids);
            $this->io->writeln(sprintf('Deleted %d rule(s).', $result['affected']));

            return $this->reportSkipped($result['skipped']);
        });
    }

    /**
     * @param list<string> $ids
     */
    public function setEnabled(array $ids, bool $enabled): int
    {
        return $this->guard(function () use ($ids, $enabled): int {
            $result = $this->app->rules()->bulk($enabled ? 'enable' : 'disable', $ids);
            $this->io->writeln(sprintf('%s %d rule(s).', $enabled ? 'Enabled' : 'Disabled', $result['affected']));

            return $this->reportSkipped($result['skipped']);
        });
    }

    // ---------------------------------------------------------------- test

    public function test(string $url, ?string $expectStatus, ?string $expectLocation, ?string $method, ?string $language, ?string $phase, bool $json = false): int
    {
        return $this->guard(function () use ($url, $expectStatus, $expectLocation, $method, $language, $phase, $json): int {
            $status = self::intOption('expect-status', $expectStatus, 100);
            $body = ['url' => $url];
            foreach (['method' => $method, 'language' => $language, 'phase' => $phase] as $field => $value) {
                if ($value !== null && $value !== '') {
                    $body[$field] = $value;
                }
            }
            $result = $this->app->tester()->test($body);

            /** @var list<array{url: string, status: int, rule_id: string|null, location: string|null}> $chain */
            $chain = $result['chain'];
            $first = $chain[0] ?? null;
            $resultRow = is_array($result['result']) ? $result['result'] : null;
            $foundStatus = $first['status'] ?? null;
            $foundLocation = $first['location'] ?? (is_string($resultRow['location'] ?? null) ? $resultRow['location'] : null);

            $mismatches = [];
            if ($status !== null && $status !== $foundStatus) {
                $mismatches[] = sprintf('Expected status %d, found %s.', $status, $foundStatus === null ? 'none' : (string) $foundStatus);
            }
            if ($expectLocation !== null && $expectLocation !== $foundLocation) {
                $mismatches[] = sprintf('Expected location "%s", found %s.', $expectLocation, $foundLocation === null ? 'none' : '"' . $foundLocation . '"');
            }

            if ($json) {
                $out = $result;
                if ($status !== null || $expectLocation !== null) {
                    $out['expect'] = ['ok' => $mismatches === [], 'mismatches' => $mismatches];
                }
                $this->json($out);

                return $mismatches === [] ? ExitCode::OK : ExitCode::MISMATCH;
            }

            $input = is_array($result['input']) ? $result['input'] : [];
            $this->io->writeln(sprintf('%s %s (phase %s)', self::str($input['method'] ?? 'GET'), $url, self::str($input['phase'] ?? 'any')));
            $rows = [];
            foreach ($chain as $hop => $step) {
                $rows[] = [$hop + 1, $step['url'], $step['status'], $step['rule_id'] ?? '-', $step['location'] ?? '-'];
            }
            $this->io->table(['hop', 'url', 'status', 'rule', 'location'], $rows);
            $final = is_array($result['final']) ? $result['final'] : [];
            $this->io->writeln(sprintf('Final: %s for %s%s', self::str($final['status'] ?? ''), self::str($final['url'] ?? ''), ($final['external'] ?? false) === true ? ' (external, not requested)' : ''));

            foreach ($mismatches as $line) {
                $this->err()->writeln($line);
            }

            return $mismatches === [] ? ExitCode::OK : ExitCode::MISMATCH;
        });
    }

    // ---------------------------------------------------------------- import / export

    public function import(string $path, ?string $format, bool $dryRun, bool $skipDuplicates, bool $skipInvalid, bool $json = false): int
    {
        return $this->guard(function () use ($path, $format, $dryRun, $skipDuplicates, $skipInvalid, $json): int {
            if (!is_file($path) || !is_readable($path)) {
                $this->fail(sprintf('The file "%s" does not exist or is not readable.', $path));

                return ExitCode::INVALID;
            }
            $content = file_get_contents($path);
            if ($content === false) {
                $this->fail(sprintf('The file "%s" could not be read.', $path));

                return ExitCode::INVALID;
            }
            $body = ['content' => $content, 'filename' => basename($path)];
            if ($format !== null && $format !== '') {
                $body['format'] = $format;
            }

            if ($dryRun) {
                $preview = $this->app->importExport()->preview($body);
                /** @var array{total: int, valid: int, errors: int, duplicates: int, warnings: int, skipped: int, not_found: int} $counts */
                $counts = $preview['counts'];
                $fileErrors = is_array($preview['errors']) ? $preview['errors'] : [];
                $failed = $fileErrors !== [] || $counts['errors'] > 0;
                if ($json) {
                    $this->json($preview);

                    return $failed ? ExitCode::INVALID : ExitCode::OK;
                }

                $this->io->writeln(sprintf('Dry run of %s (format %s), nothing was saved.', basename($path), self::str($preview['format'] ?? 'unknown')));
                foreach ($fileErrors as $error) {
                    $this->err()->writeln(is_array($error) ? self::str($error['message'] ?? '') : '');
                }
                $this->io->definitionList(
                    ['Rows' => (string) $counts['total']],
                    ['Valid' => (string) $counts['valid']],
                    ['Invalid' => (string) $counts['errors']],
                    ['Duplicates' => (string) $counts['duplicates']],
                    ['With warnings' => (string) $counts['warnings']],
                    ['Skipped' => (string) $counts['skipped']],
                );
                $problems = [];
                foreach (is_array($preview['rows']) ? $preview['rows'] : [] as $row) {
                    if (!is_array($row) || !is_array($row['errors'] ?? null) || $row['errors'] === []) {
                        continue;
                    }
                    $messages = [];
                    foreach ($row['errors'] as $error) {
                        $messages[] = is_array($error) ? self::str($error['message'] ?? '') . ' (' . self::str($error['code'] ?? '') . ')' : '';
                    }
                    $problems[] = [self::str($row['line'] ?? ''), implode('; ', $messages), self::str($row['raw'] ?? '')];
                }
                if ($problems !== []) {
                    $this->io->writeln(sprintf('Rows with problems (first %d):', self::PREVIEW_ROWS));
                    $this->io->table(['line', 'problem', 'content'], array_slice($problems, 0, self::PREVIEW_ROWS));
                }

                return $failed ? ExitCode::INVALID : ExitCode::OK;
            }

            $body['skip_duplicates'] = $skipDuplicates;
            $body['skip_invalid'] = $skipInvalid;
            $result = $this->app->importExport()->commit($body);
            if ($json) {
                $this->json($result);

                return ExitCode::OK;
            }
            $this->io->writeln(sprintf('Imported %s: %s rule(s) created, %s row(s) skipped.', basename($path), self::str($result['created'] ?? 0), self::str($result['skipped'] ?? 0)));

            return ExitCode::OK;
        });
    }

    public function export(string $format, ?string $output, bool $onlyEnabled, ?string $group, bool $json = false, ?string $host = null): int
    {
        return $this->guard(function () use ($format, $output, $onlyEnabled, $group, $json, $host): int {
            $query = ['format' => $format, 'only_enabled' => $onlyEnabled];
            if ($group !== null) {
                $query['group'] = $group;
            }
            if ($host !== null && $host !== '') {
                $query['host'] = $host;
            }
            $result = $this->app->importExport()->export($query);

            if ($output === null || $output === '') {
                if ($json) {
                    $this->json($result);
                } else {
                    $this->io->write($result['content'], false, OutputInterface::OUTPUT_RAW);
                    foreach ([...$result['skipped'], ...$result['lossy']] as $note) {
                        $this->err()->writeln(sprintf('Rule %s: %s', $note['rule_id'], $note['reason']));
                    }
                }

                return ExitCode::OK;
            }

            if (@file_put_contents($output, $result['content']) === false) {
                $this->fail(sprintf('The file "%s" could not be written.', $output));

                return ExitCode::ERROR;
            }
            if ($json) {
                $this->json([
                    'filename' => $result['filename'],
                    'mime' => $result['mime'],
                    'output' => $output,
                    'exported' => $result['exported'],
                    'skipped' => $result['skipped'],
                    'lossy' => $result['lossy'],
                ]);

                return ExitCode::OK;
            }
            $this->io->writeln(sprintf('Exported %d rule(s) to %s.', $result['exported'], $output));
            foreach ([...$result['skipped'], ...$result['lossy']] as $note) {
                $this->io->writeln(sprintf('Rule %s: %s', $note['rule_id'], $note['reason']));
            }

            return ExitCode::OK;
        });
    }

    // ---------------------------------------------------------------- suggest

    public function suggest(string $days, ?string $acceptMin, bool $noAccept, bool $dryRun, bool $json = false): int
    {
        return $this->guard(function () use ($days, $acceptMin, $noAccept, $dryRun, $json): int {
            $daysValue = self::intOption('days', $days, 1);
            $min = null;
            if ($acceptMin !== null && $acceptMin !== '') {
                if (!is_numeric($acceptMin)) {
                    throw new InvalidInputException('"accept-min" must be a number between 0 and 1.', field: 'accept-min');
                }
                $min = (float) $acceptMin;
            }

            $suggestions = $this->app->suggestions();
            $generated = $suggestions->generate($daysValue, $dryRun);
            $accepted = $noAccept ? null : $suggestions->bulkAccept($min, null, $dryRun);
            if ($dryRun && $accepted !== null) {
                // Nothing was stored, so the suggestions this run would create are not in the store yet: count them too.
                $limit = is_numeric($accepted['min_score'] ?? null) ? (float) $accepted['min_score'] : 0.9;
                $stored = [];
                foreach (is_array($accepted['rows'] ?? null) ? $accepted['rows'] : [] as $row) {
                    if (is_array($row) && isset($row['path']) && is_string($row['path'])) {
                        $stored[$row['path']] = true;
                    }
                }
                $extra = array_values(array_filter(
                    $generated['preview'] ?? [],
                    static fn (array $r): bool => is_numeric($r['score'] ?? null) && (float) $r['score'] >= $limit && !isset($stored[self::str($r['path'] ?? '')]),
                ));
                $accepted['rows'] = [...(is_array($accepted['rows'] ?? null) ? $accepted['rows'] : []), ...$extra];
                $accepted['count'] = self::intValue($accepted['count'] ?? 0) + count($extra);
            }

            if ($json) {
                $this->json(['dry_run' => $dryRun, 'generate' => $generated, 'accept' => $accepted]);

                return ExitCode::OK;
            }

            $this->io->writeln(sprintf(
                '%s%d path(s) looked at: %d suggestion(s) %s, %d improved, %d without a match, %d already covered by a rule.',
                $dryRun ? 'Dry run, nothing was saved. ' : '',
                $generated['paths'],
                $generated['suggested'],
                $dryRun ? 'would be stored' : 'stored',
                $generated['improved'],
                $generated['no_suggestion'],
                $generated['skipped_with_rule'],
            ));
            if ($dryRun && ($generated['preview'] ?? []) !== []) {
                $this->io->table(['path', 'target', 'score'], array_map(
                    static fn (array $r): array => [self::str($r['path'] ?? ''), self::str($r['target'] ?? ''), self::str($r['score'] ?? '')],
                    array_slice($generated['preview'] ?? [], 0, self::PREVIEW_ROWS),
                ));
            }

            if ($accepted === null) {
                return ExitCode::OK;
            }
            $count = self::intValue($accepted['count'] ?? 0);
            $this->io->writeln(sprintf(
                '%s %d suggestion(s) with a score of at least %s%s.',
                $dryRun ? 'Would accept' : 'Accepted',
                $count,
                self::str($accepted['min_score'] ?? ''),
                $dryRun ? '' : ' as rules',
            ));
            $rows = is_array($accepted['rows'] ?? null) ? $accepted['rows'] : [];
            if ($rows !== []) {
                $this->io->table(['path', 'target', 'score'], array_map(
                    static fn (mixed $r): array => is_array($r) ? [self::str($r['path'] ?? ''), self::str($r['target'] ?? ''), self::str($r['score'] ?? '')] : [],
                    array_slice($rows, 0, self::PREVIEW_ROWS),
                ));
            }
            $skipped = is_array($accepted['skipped'] ?? null) ? $accepted['skipped'] : [];
            if ($skipped !== []) {
                $this->io->writeln(sprintf('%d suggestion(s) were not accepted because the rule is invalid.', count($skipped)));
            }

            return ExitCode::OK;
        });
    }

    // ---------------------------------------------------------------- check-targets

    public function checkTargets(bool $json = false): int
    {
        return $this->guard(function () use ($json): int {
            $run = $this->app->checks()->run(null, false);
            $stored = $this->app->services()->checkResultStore()->all();

            $dead = [];
            foreach ($run['results'] as $row) {
                $result = $stored[self::str($row['rule_id'] ?? '')] ?? null;
                if ($result !== null && $result->isDead()) {
                    $dead[] = $row;
                }
            }

            if ($json) {
                $this->json(['checked' => $run['checked'], 'dead' => count($dead), 'results' => $dead]);

                return $dead === [] ? ExitCode::OK : ExitCode::MISMATCH;
            }
            if ($dead === []) {
                $this->io->writeln(sprintf('Checked %d target(s), none is dead.', $run['checked']));

                return ExitCode::OK;
            }
            $this->io->table(['rule', 'source', 'target', 'status / error'], array_map(
                static fn (array $r): array => [
                    self::str($r['rule_id'] ?? ''),
                    self::str($r['source'] ?? ''),
                    self::str($r['target'] ?? ''),
                    is_int($r['status'] ?? null) && $r['status'] >= 400 ? (string) $r['status'] : self::str($r['error'] ?? $r['status'] ?? ''),
                ],
                $dead,
            ));
            $this->err()->writeln(sprintf('Checked %d target(s), %d dead.', $run['checked'], count($dead)));

            return ExitCode::MISMATCH;
        });
    }

    // ---------------------------------------------------------------- prune

    public function prune(string $unusedDays, bool $dryRun, bool $disableOnly, bool $json = false): int
    {
        return $this->guard(function () use ($unusedDays, $dryRun, $disableOnly, $json): int {
            $days = self::intOption('unused-days', $unusedDays, 1) ?? 180;
            $rules = $this->app->rules();
            $unused = $rules->unused($days);
            $action = $disableOnly ? 'disable' : 'delete';

            $affected = 0;
            if (!$dryRun && $unused !== []) {
                $result = $rules->bulk($action, array_map(static fn (Rule $r): string => $r->id, $unused));
                $affected = $result['affected'];
            }

            if ($json) {
                $this->json([
                    'dry_run' => $dryRun,
                    'action' => $dryRun ? null : $action,
                    'days' => $days,
                    'count' => $dryRun ? count($unused) : $affected,
                    'rules' => array_map($rules->plain(...), $unused),
                ]);

                return ExitCode::OK;
            }
            if ($unused === []) {
                $this->io->writeln(sprintf('No rule has been unused for %d days.', $days));

                return ExitCode::OK;
            }
            if ($dryRun) {
                $this->io->table(['id', 'source', 'target', 'status', 'enabled'], array_map(
                    static fn (Rule $r): array => [$r->id, $r->source, $r->target, $r->status->value, $r->enabled ? 'yes' : 'no'],
                    $unused,
                ));
                $this->io->writeln(sprintf('Dry run: %d rule(s) unused for %d days would be %s.', count($unused), $days, $disableOnly ? 'disabled' : 'deleted'));

                return ExitCode::OK;
            }
            $this->io->writeln(sprintf('%s %d rule(s) unused for %d days.', $disableOnly ? 'Disabled' : 'Deleted', $affected, $days));

            return ExitCode::OK;
        });
    }

    // ---------------------------------------------------------------- stats / cache

    public function stats(bool $json = false): int
    {
        return $this->guard(function () use ($json): int {
            $numbers = $this->app->stats()->dashboard();
            if ($json) {
                $this->json($numbers);

                return ExitCode::OK;
            }
            $this->io->table(['metric', 'value'], [
                ['404 requests today', $numbers['not_found_today']],
                ['404 requests, 7 days', $numbers['not_found_7d']],
                ['Redirect hits today', $numbers['hits_today']],
                ['Redirect hits, 7 days', $numbers['hits_7d']],
                ['Rules', $numbers['rules_total']],
                ['Active rules', $numbers['rules_active']],
                ['Open suggestions', $numbers['open_suggestions']],
                ['Dead targets', $numbers['dead_targets']],
                ['Pending deleted pages', $numbers['pending_deletes']],
            ]);

            return ExitCode::OK;
        });
    }

    public function rebuildCache(): int
    {
        return $this->guard(function (): int {
            $count = $this->app->rebuildCache();
            $this->io->writeln(sprintf('Rebuilt the rule cache: %d compiled rule(s).', $count));

            return ExitCode::OK;
        });
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Prints the outcome of a bulk action's skipped ids. Invalid rules win over unknown ids for the exit code.
     *
     * @param list<array{id: string, reason: string, issues: list<ValidationIssue>}> $skipped
     */
    private function reportSkipped(array $skipped): int
    {
        $code = ExitCode::OK;
        foreach ($skipped as $item) {
            if ($item['reason'] === 'not_found') {
                $this->err()->writeln(sprintf('Rule "%s" was not found.', $item['id']));
                $code = $code === ExitCode::OK ? ExitCode::NOT_FOUND : $code;
                continue;
            }
            $this->err()->writeln(sprintf('Rule "%s" was skipped, it would be invalid:', $item['id']));
            foreach ($item['issues'] as $issue) {
                $this->err()->writeln('  ' . self::issueLine($issue));
            }
            $code = ExitCode::INVALID;
        }

        return $code;
    }

    /**
     * @param list<ValidationIssue> $issues
     */
    private function printWarnings(array $issues): void
    {
        foreach ($issues as $issue) {
            if ($issue->severity->value === 'warning') {
                $this->io->writeln('Warning: ' . self::issueLine($issue));
            }
        }
    }

    /**
     * @param list<ValidationIssue> $issues
     */
    private function fail(string $message, array $issues = []): void
    {
        $lines = [];
        $single = count($issues) === 1 && $issues[0]->message === $message;
        if (!$single) {
            $lines[] = $message;
        }
        foreach ($issues as $issue) {
            $lines[] = self::issueLine($issue);
        }
        $this->err()->error($lines === [] ? $message : $lines);
    }

    private function err(): SymfonyStyle
    {
        return $this->io->getErrorStyle();
    }

    private function json(mixed $data): void
    {
        $this->io->writeln(json_encode($data, self::JSON_FLAGS), OutputInterface::OUTPUT_RAW);
    }

    private static function issueLine(ValidationIssue $issue): string
    {
        return ($issue->field !== '' ? $issue->field . ': ' : '') . $issue->message . ' (' . $issue->code . ')';
    }

    /**
     * @param list<ValidationIssue> $issues
     *
     * @return list<array{code: string, severity: string, field: string, message: string, params: array<string, mixed>}>
     */
    private static function issueRows(array $issues): array
    {
        return array_map(static fn (ValidationIssue $i): array => $i->toArray(), $issues);
    }

    /**
     * @param list<ValidationIssue> $issues
     */
    private static function hasError(array $issues): bool
    {
        foreach ($issues as $issue) {
            if ($issue->isError()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @throws InvalidInputException
     */
    private static function intOption(string $name, ?string $value, int $min): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (preg_match('/^\d{1,9}$/', trim($value)) !== 1 || (int) trim($value) < $min) {
            throw new InvalidInputException(sprintf('"%s" must be an integer of at least %d.', $name, $min), field: $name);
        }

        return (int) trim($value);
    }

    private static function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private static function intValue(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $value): array
    {
        return is_array($value) ? array_values(array_map(self::str(...), $value)) : [];
    }
}
