<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\App;

use Closure;
use DateTimeImmutable;
use Grav\Plugin\RedirectManager\Analysis\AnalysisReport;
use Grav\Plugin\RedirectManager\Analysis\ChainAnalyzer;
use Grav\Plugin\RedirectManager\Analysis\ChainShortener;
use Grav\Plugin\RedirectManager\Analysis\RuleValidator;
use Grav\Plugin\RedirectManager\Analysis\Severity;
use Grav\Plugin\RedirectManager\Analysis\ValidationIssue;
use Grav\Plugin\RedirectManager\Analysis\ValidationResult;
use Grav\Plugin\RedirectManager\App\Exception\InvalidInputException;
use Grav\Plugin\RedirectManager\App\Exception\ResourceNotFoundException;
use Grav\Plugin\RedirectManager\App\Exception\RuleValidationException;
use Grav\Plugin\RedirectManager\Domain\Rule;
use Grav\Plugin\RedirectManager\Domain\RuleSource;
use Grav\Plugin\RedirectManager\Domain\StatusCode;
use Grav\Plugin\RedirectManager\Domain\TargetType;
use Grav\Plugin\RedirectManager\Grav\RuleEvents;
use Grav\Plugin\RedirectManager\Grav\ServiceFactory;
use Grav\Plugin\RedirectManager\Stats\RuleStats;
use Grav\Plugin\RedirectManager\Storage\AtomicFile;
use Grav\Plugin\RedirectManager\Storage\AtomicFileException;
use Grav\Plugin\RedirectManager\Storage\DuplicateRuleIdException;
use Grav\Plugin\RedirectManager\Util\Ids;
use Grav\Plugin\RedirectManager\Util\Timestamps;
use stdClass;
use Throwable;

/**
 * Everything that reads or changes rules: list, get, create, partial update, delete, restore, bulk actions,
 * reorder, live validation, chain shortening and analysis. The REST controllers and the CLI call this and nothing
 * below it.
 *
 * Writes go through RuleRepository::transaction() (read, validate, write under one lock). RuleValidator errors
 * abort with RuleValidationException, warnings and infos are handed back with the saved rule. After every write
 * the compiled rule cache is invalidated and onRedirectRuleSaved fires.
 *
 * Reordering: the listed rules take their own priority values back in the new order (highest value to the first
 * id), so they keep their place relative to rules that are not listed. Where that would give two rules the same
 * value, each following rule is set one below its predecessor. Nothing outside the list changes.
 *
 * The chain analysis of all rules is cached per rules revision in cache://redirect-manager/analysis-<revision>.php.
 * It is dropped when the rules file changes, when a rule expires or starts (time dependent findings), or when the
 * matcher configuration changes.
 *
 * @phpstan-type Row array<string, mixed>
 */
final class RuleService
{
    public const BULK_ACTIONS = ['enable', 'disable', 'delete', 'set_status', 'set_group', 'add_tag', 'remove_tag'];
    public const MAX_BULK = 1000;
    /** Imports up to this many rules are related to each other and to the stored rules; larger ones get field checks only. */
    public const RELATION_CHECK_LIMIT = 300;
    public const DEFAULT_UNUSED_DAYS = 180;

    private ?RuleValidator $validator = null;
    private ?AnalysisReport $report = null;
    private ?string $reportRevision = null;
    private ?int $reportValidUntil = null;

    public function __construct(
        private readonly ServiceFactory $services,
        private readonly SiteContext $site,
        private readonly StatsAccess $stats,
        private readonly RuleEvents $events,
        /** @var (Closure(): string)|null generator of rule ids for imports (default: Ids::rule); tests inject a fixed sequence */
        private readonly ?Closure $ids = null,
    ) {
    }

    // ---------------------------------------------------------------- reading

    /**
     * @return list<Rule>
     */
    public function all(): array
    {
        return $this->snapshot()['rules'];
    }

    /**
     * Rules and the hash of the file they were read from, in one consistent read.
     *
     * @return array{rules: list<Rule>, revision: string}
     */
    public function snapshot(): array
    {
        return $this->services->repository()->snapshot();
    }

    /**
     * How many rules there are, how many of them are active now, and which ones are enabled. Reads the stored rows
     * instead of Rule objects (see RuleRepository::snapshotRows()), which is what the dashboard needs and 3x cheaper.
     *
     * @return array{total: int, active: int, enabled: array<string, true>}
     */
    public function summary(): array
    {
        $now = $this->services->clock()->now();
        $enabled = [];
        $active = 0;
        $rows = $this->services->repository()->snapshotRows()['rows'];
        foreach ($rows as $row) {
            if (($row['enabled'] ?? true) !== true) {
                continue;
            }
            $id = is_string($row['id'] ?? null) ? $row['id'] : '';
            $enabled[$id] = true;
            $from = is_string($row['active_from'] ?? null) ? Timestamps::parse($row['active_from']) : null;
            $until = is_string($row['expires_at'] ?? null) ? Timestamps::parse($row['expires_at']) : null;
            if (($until === null || $until > $now) && ($from === null || $from <= $now)) {
                ++$active;
            }
        }

        return ['total' => count($rows), 'active' => $active, 'enabled' => $enabled];
    }

    /**
     * @throws ResourceNotFoundException
     */
    public function find(string $id): Rule
    {
        foreach ($this->all() as $rule) {
            if ($rule->id === $id) {
                return $rule;
            }
        }

        throw new ResourceNotFoundException('rule', $id);
    }

    /** Entity tag of a rule: changes with every stored change (updated_at is part of it). */
    public function etag(Rule $rule): string
    {
        return md5((string) json_encode($rule->toArray()));
    }

    /**
     * @return array{rows: list<Row>, total: int, page: int, per_page: int, meta: array<string, mixed>}
     */
    public function list(RuleQuery $q): array
    {
        $snap = $this->snapshot();
        $ctx = $this->context($snap['rules'], $snap['revision']);
        if ($q->unusedDays !== null) {
            $ctx['unused_by_days'] = array_fill_keys($this->unusedIdsFrom($snap['rules'], $q->unusedDays), true);
        }

        $matches = static function (Rule $rule) use ($q, $ctx): bool {
            if ($q->matchType !== null && $rule->matchType->value !== $q->matchType) {
                return false;
            }
            if ($q->status !== null && $rule->status->value !== $q->status) {
                return false;
            }
            if ($q->group !== null && $rule->group !== $q->group) {
                return false;
            }
            if ($q->origin !== null && $rule->origin->value !== $q->origin) {
                return false;
            }
            if ($q->tag !== null && !in_array($q->tag, $rule->tags, true)) {
                return false;
            }
            if ($q->state !== null && $ctx['states'][$rule->id] !== $q->state) {
                return false;
            }
            if ($q->unusedDays !== null && !isset($ctx['unused_by_days'][$rule->id])) {
                return false;
            }
            if ($q->search !== '') {
                $haystack = implode("\n", [$rule->source, $rule->target, $rule->note, $rule->group, ...$rule->tags]);
                if (mb_stripos($haystack, $q->search) === false) {
                    return false;
                }
            }

            return true;
        };

        $base = array_values(array_filter($snap['rules'], $matches));
        $counts = array_fill_keys(RuleQuery::BADGES, 0);
        foreach ($base as $rule) {
            foreach ($ctx['badges'][$rule->id] as $badge) {
                ++$counts[$badge];
            }
        }
        $filtered = $q->badge === null
            ? $base
            : array_values(array_filter($base, static fn (Rule $r): bool => in_array($q->badge, $ctx['badges'][$r->id], true)));

        $this->sortRules($filtered, $q, $ctx['stats']);

        $total = count($filtered);
        $all = $q->perPage === PHP_INT_MAX;
        $slice = array_slice($filtered, $all ? 0 : ($q->page - 1) * $q->perPage, $all ? null : $q->perPage);
        $rows = array_map(fn (Rule $r): array => $this->present($r, $ctx), $slice);

        $groups = [];
        foreach ($snap['rules'] as $rule) {
            if ($rule->group !== '') {
                $groups[$rule->group] = true;
            }
        }
        $groupNames = array_map('strval', array_keys($groups));
        sort($groupNames, SORT_NATURAL | SORT_FLAG_CASE);

        return [
            'rows' => $rows,
            'total' => $total,
            'page' => $q->page,
            'per_page' => $q->perPage === PHP_INT_MAX ? $total : $q->perPage,
            'meta' => ['groups' => $groupNames, 'counts' => $counts],
        ];
    }

    /**
     * One rule with stats, badges and issues.
     *
     * @return Row
     */
    public function get(string $id): array
    {
        $snap = $this->snapshot();
        foreach ($snap['rules'] as $rule) {
            if ($rule->id === $id) {
                return $this->present($rule, $this->context($snap['rules'], $snap['revision']));
            }
        }

        throw new ResourceNotFoundException('rule', $id);
    }

    /**
     * Rule fields only (no stats, badges or issues); the form for undo lists and export.
     *
     * @return Row
     */
    public function plain(Rule $rule): array
    {
        $row = $rule->toArray();
        if ($row['query_params'] === []) {
            $row['query_params'] = new stdClass();
        }

        return $row;
    }

    /**
     * A freshly written rule as the API returns it: its own validation findings instead of the full analysis.
     *
     * @param list<ValidationIssue> $issues
     *
     * @return Row
     */
    public function presentSaved(Rule $rule, array $issues): array
    {
        $now = $this->services->clock()->now();
        $stats = $this->stats->store()->forRule($rule->id);
        $row = $this->plain($rule);
        $row['stats'] = $this->statsRow($stats);
        $row['badges'] = $this->badges($rule, $issues, $now, false, false);
        $row['issues'] = array_map(static fn (ValidationIssue $i): array => $i->toArray(), $issues);

        return $row;
    }

    /**
     * @return array{chains: list<array<string, mixed>>, loops: list<array<string, mixed>>, conflicts: list<list<string>>, expired: list<string>, unused: list<string>}
     */
    public function analysis(): array
    {
        $snap = $this->snapshot();
        $report = $this->report($snap['rules'], $snap['revision']);
        $data = $report->toArray();

        return [
            'chains' => $data['chains'],
            'loops' => $data['loops'],
            'conflicts' => $data['conflicts'],
            'expired' => $data['expired'],
            'unused' => $this->unusedIdsFrom($snap['rules'], $this->unusedDays()),
        ];
    }

    /**
     * @return array{groups: list<array{name: string, count: int}>, tags: list<array{name: string, count: int}>}
     */
    public function groups(): array
    {
        $groups = [];
        $tags = [];
        foreach ($this->all() as $rule) {
            if ($rule->group !== '') {
                $groups[$rule->group] = ($groups[$rule->group] ?? 0) + 1;
            }
            foreach ($rule->tags as $tag) {
                $tags[$tag] = ($tags[$tag] ?? 0) + 1;
            }
        }

        return ['groups' => self::counted($groups), 'tags' => self::counted($tags)];
    }

    /**
     * Active rules that got no hit for $days days (never-hit rules only when older than that).
     *
     * @return list<Rule>
     */
    public function unused(int $days): array
    {
        $rules = $this->all();
        $ids = array_flip($this->unusedIdsFrom($rules, $days));

        return array_values(array_filter($rules, static fn (Rule $r): bool => isset($ids[$r->id])));
    }

    public function defaultStatus(): StatusCode
    {
        $configured = $this->services->int('redirects.default_status', 0);
        $code = $configured > 0 ? $configured : $this->site->redirectDefaultCode;

        return StatusCode::tryFrom($code) ?? StatusCode::MovedPermanently;
    }

    public function validator(): RuleValidator
    {
        return $this->validator ??= new RuleValidator(
            $this->services->matcherOptions(),
            $this->services->targetGuard(),
            $this->services->clock(),
        );
    }

    // ---------------------------------------------------------------- live validation

    /**
     * Validates a rule without saving it. Input that cannot be read as a rule is reported as error issues too, so
     * the editor shows everything in one list. $body may carry "id" (an existing rule is patched by the other
     * fields), "sample" (a URL to preview) and "language".
     *
     * @param array<mixed> $body
     *
     * @return array{rule: Row, issues: list<ValidationIssue>, preview: array<string, mixed>|null}
     */
    public function validate(array $body, ?string $id = null): array
    {
        $id ??= isset($body['id']) && is_string($body['id']) && $body['id'] !== '' ? $body['id'] : null;
        $parsed = RuleInput::parse($body);
        $existing = $this->all();
        $base = null;
        if ($id !== null) {
            foreach ($existing as $rule) {
                if ($rule->id === $id) {
                    $base = $rule;
                }
            }
        }
        $fields = $parsed['fields'];
        if ($base === null) {
            $fields += ['status' => $this->defaultStatus()->value, 'origin' => RuleSource::Manual->value];
            if ($id !== null) {
                $fields['id'] = $id;
            }
            $candidate = Rule::fromArray($fields);
        } else {
            $candidate = $base->with($fields);
        }

        $issues = $parsed['issues'];
        if (!self::hasError($issues)) {
            $issues = [...$issues, ...$this->validator()->validate($candidate, $existing)->issues];
        }

        $preview = null;
        $sample = isset($body['sample']) && is_string($body['sample']) ? trim($body['sample']) : '';
        if ($sample !== '' && !self::hasError($parsed['issues'])) {
            $language = isset($body['language']) && is_string($body['language']) && $body['language'] !== '' ? $body['language'] : null;
            $preview = ['sample' => $sample, 'result' => $this->previewResult($this->validator()->preview($candidate, $existing, $sample, $language))];
        }

        return ['rule' => $this->plain($candidate), 'issues' => $issues, 'preview' => $preview];
    }

    // ---------------------------------------------------------------- writing

    /**
     * @param array<mixed> $body rule fields
     *
     * @return array{rule: Rule, issues: list<ValidationIssue>}
     *
     * @throws RuleValidationException
     */
    public function create(array $body): array
    {
        $rule = $this->buildNew($body);
        $result = null;
        $stored = $this->services->repository()->transaction(function (array $current) use (&$rule, &$result): array {
            // The id was generated, not chosen by the caller: on the (practically impossible) clash take another one.
            $rule = $this->withFreshId($rule, array_fill_keys(array_map(static fn (Rule $r): string => $r->id, $current), true));
            $result = $this->validator()->validate($rule, $current);
            if ($result->hasErrors()) {
                throw new RuleValidationException($result->issues);
            }
            $current[] = $rule;

            return $current;
        });
        $this->afterWrite();
        $saved = self::byId($stored, $rule->id) ?? $rule;
        $this->fire($saved, null, RuleEvents::ACTION_CREATE);

        return ['rule' => $saved, 'issues' => $result instanceof ValidationResult ? $result->issues : []];
    }

    /**
     * Partial update: only the fields present in $body change.
     *
     * @param array<mixed> $body
     *
     * @return array{rule: Rule, issues: list<ValidationIssue>}
     *
     * @throws ResourceNotFoundException
     * @throws RuleValidationException
     */
    public function update(string $id, array $body): array
    {
        $parsed = RuleInput::parse($body);
        if ($parsed['issues'] !== []) {
            throw new RuleValidationException($parsed['issues']);
        }
        $fields = $parsed['fields'];
        $previous = null;
        $result = null;
        $stored = $this->services->repository()->transaction(function (array $current) use ($id, $fields, &$previous, &$result): array {
            foreach ($current as $i => $rule) {
                if ($rule->id !== $id) {
                    continue;
                }
                $previous = $rule;
                if (isset($fields['target']) && !isset($fields['target_type'])) {
                    $absolute = self::isAbsoluteUrl((string) $fields['target']);
                    if ($absolute && $rule->targetType !== TargetType::Url) {
                        $fields['target_type'] = TargetType::Url->value;
                    } elseif (!$absolute && $rule->targetType === TargetType::Url && (string) $fields['target'] !== '') {
                        $fields['target_type'] = TargetType::Route->value;
                    }
                }
                $next = $fields === [] ? $rule : $rule->with($fields);
                $result = $this->validator()->validate($next, $current);
                if ($result->hasErrors()) {
                    throw new RuleValidationException($result->issues);
                }
                $current[$i] = $next;

                return $current;
            }

            throw new ResourceNotFoundException('rule', $id);
        });
        $this->afterWrite();
        $saved = self::byId($stored, $id);
        if ($saved === null || !$previous instanceof Rule) {
            throw new ResourceNotFoundException('rule', $id);
        }
        if ($fields !== [] && !self::sameContent($previous, $saved)) {
            $this->fire($saved, $previous, RuleEvents::ACTION_UPDATE);
        }

        return ['rule' => $saved, 'issues' => $result instanceof ValidationResult ? $result->issues : []];
    }

    /**
     * @throws ResourceNotFoundException
     */
    public function delete(string $id): Rule
    {
        $deleted = $this->services->repository()->delete([$id]);
        if ($deleted === []) {
            throw new ResourceNotFoundException('rule', $id);
        }
        $this->afterWrite();
        $this->fire($deleted[0], $deleted[0], RuleEvents::ACTION_DELETE);

        return $deleted[0];
    }

    /**
     * Puts deleted rules back with their ids and timestamps (undo). A rule whose id exists again replaces it.
     * The rules are not validated again: they were valid when they were deleted.
     *
     * @param list<mixed> $rows serialized rules
     *
     * @return list<Rule>
     *
     * @throws InvalidInputException
     */
    public function restore(array $rows): array
    {
        if ($rows === []) {
            throw new InvalidInputException('"rules" must be a non-empty list.', field: 'rules', errorCode: 'required');
        }
        if (count($rows) > self::MAX_BULK) {
            throw new InvalidInputException(sprintf('At most %d rules can be restored at once.', self::MAX_BULK), field: 'rules', errorCode: 'too_many');
        }
        $rules = [];
        $issues = [];
        foreach ($rows as $i => $row) {
            if (!is_array($row) || !isset($row['id']) || !is_string($row['id']) || $row['id'] === '') {
                $issues[] = ValidationIssue::error('invalid_type', sprintf('rules.%s', (string) $i), 'Every rule needs an "id".');
                continue;
            }
            $parsed = RuleInput::parse($row, true);
            foreach ($parsed['issues'] as $issue) {
                $issues[] = ValidationIssue::error($issue->code, sprintf('rules.%s.%s', (string) $i, $issue->field), $issue->message);
            }
            if ($parsed['issues'] === []) {
                $rules[] = Rule::fromArray($parsed['fields']);
            }
        }
        if ($issues !== []) {
            throw new InvalidInputException('Some rules cannot be restored.', $issues);
        }
        try {
            $this->services->repository()->restore($rules);
        } catch (DuplicateRuleIdException $e) {
            throw new InvalidInputException($e->getMessage(), field: 'rules', errorCode: 'duplicate_id');
        }
        $this->afterWrite();
        foreach ($rules as $rule) {
            $this->fire($rule, null, RuleEvents::ACTION_CREATE);
        }

        return $rules;
    }

    /**
     * @param list<string> $ids
     *
     * @return array{affected: int, rules: list<Rule>, skipped: list<array{id: string, reason: string, issues: list<ValidationIssue>}>}
     *
     * @throws InvalidInputException
     */
    public function bulk(string $action, array $ids, mixed $value = null): array
    {
        if (!in_array($action, self::BULK_ACTIONS, true)) {
            throw new InvalidInputException('"action" must be one of ' . implode(', ', self::BULK_ACTIONS) . '.', field: 'action');
        }
        $ids = array_values(array_unique(array_filter($ids, static fn (mixed $id): bool => is_string($id) && $id !== '')));
        if ($ids === []) {
            throw new InvalidInputException('"ids" must be a non-empty list of rule ids.', field: 'ids', errorCode: 'required');
        }
        if (count($ids) > self::MAX_BULK) {
            throw new InvalidInputException(sprintf('At most %d rules per bulk action.', self::MAX_BULK), field: 'ids', errorCode: 'too_many');
        }

        if ($action === 'delete') {
            $deleted = $this->services->repository()->delete($ids);
            if ($deleted !== []) {
                $this->afterWrite();
                foreach ($deleted as $rule) {
                    $this->fire($rule, $rule, RuleEvents::ACTION_DELETE);
                }
            }

            return ['affected' => count($deleted), 'rules' => $deleted, 'skipped' => self::missing($ids, $deleted)];
        }

        $change = $this->bulkChange($action, $value);
        $wanted = array_flip($ids);
        $changed = [];
        $skipped = [];
        $before = [];
        $this->services->repository()->transaction(function (array $current) use ($wanted, $change, $action, &$changed, &$skipped, &$before): array {
            $changed = [];
            $skipped = [];
            $before = [];
            $found = [];
            foreach ($current as $i => $rule) {
                if (!isset($wanted[$rule->id])) {
                    continue;
                }
                $found[$rule->id] = true;
                $next = $change($rule);
                if ($next === null || self::sameContent($rule, $next)) {
                    continue;
                }
                if (in_array($action, ['enable', 'set_status'], true)) {
                    $result = $this->validator()->validate($next, $current);
                    if ($result->hasErrors()) {
                        $skipped[] = ['id' => $rule->id, 'reason' => 'invalid', 'issues' => $result->errors()];
                        continue;
                    }
                }
                $before[$rule->id] = $rule;
                $current[$i] = $next;
                $changed[$rule->id] = $next;
            }
            foreach (array_keys($wanted) as $id) {
                if (!isset($found[(string) $id])) {
                    $skipped[] = ['id' => (string) $id, 'reason' => 'not_found', 'issues' => []];
                }
            }

            return $current;
        });
        $out = array_values($changed);
        if ($out !== []) {
            $this->afterWrite();
            foreach ($out as $rule) {
                $this->fire($rule, $before[$rule->id] ?? null, RuleEvents::ACTION_UPDATE);
            }
        }

        return ['affected' => count($out), 'rules' => $out, 'skipped' => $skipped];
    }

    /**
     * Reorders the listed rules; see the class comment for the priority semantics.
     *
     * @param list<string> $ids desired order, first = evaluated first
     *
     * @return list<Rule> the changed rules
     *
     * @throws InvalidInputException
     * @throws ResourceNotFoundException
     */
    public function reorder(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (mixed $id): bool => is_string($id) && $id !== '')));
        if (count($ids) < 2) {
            throw new InvalidInputException('"ids" needs at least two rule ids.', field: 'ids', errorCode: 'required');
        }
        if (count($ids) > self::MAX_BULK) {
            throw new InvalidInputException(sprintf('At most %d rules can be reordered at once.', self::MAX_BULK), field: 'ids', errorCode: 'too_many');
        }
        $changed = [];
        $before = [];
        $this->services->repository()->transaction(function (array $current) use ($ids, &$changed, &$before): array {
            $changed = [];
            $before = [];
            $index = [];
            foreach ($current as $i => $rule) {
                $index[$rule->id] = $i;
            }
            foreach ($ids as $id) {
                if (!isset($index[$id])) {
                    throw new ResourceNotFoundException('rule', $id);
                }
            }
            $pool = array_map(static fn (string $id): int => $current[$index[$id]]->priority, $ids);
            rsort($pool);
            $previous = null;
            foreach ($ids as $position => $id) {
                $priority = $pool[$position];
                if ($previous !== null && $priority >= $previous) {
                    $priority = $previous - 1;
                }
                $previous = $priority;
                $rule = $current[$index[$id]];
                if ($rule->priority !== $priority) {
                    $before[$id] = $rule;
                    $changed[$id] = $rule->with(['priority' => $priority]);
                    $current[$index[$id]] = $changed[$id];
                }
            }

            return array_values($current);
        });
        $out = array_values($changed);
        if ($out !== []) {
            $this->afterWrite();
            foreach ($out as $rule) {
                $this->fire($rule, $before[$rule->id] ?? null, RuleEvents::ACTION_UPDATE);
            }
        }

        return $out;
    }

    /**
     * Points the rule at the end of its redirect chain.
     *
     * @return array{rule: Rule, issues: list<ValidationIssue>}
     *
     * @throws ResourceNotFoundException
     * @throws InvalidInputException when the rule is not the start of a chain, or its language variants end in different places
     */
    public function shortenChain(string $id): array
    {
        $snap = $this->snapshot();
        $rule = self::byId($snap['rules'], $id);
        if ($rule === null) {
            throw new ResourceNotFoundException('rule', $id);
        }
        $shortened = (new ChainShortener())->shorten($rule, $this->report($snap['rules'], $snap['revision']));
        if ($shortened === null) {
            throw new InvalidInputException('The rule is not the start of a chain that can be shortened.', field: 'id', errorCode: 'no_chain');
        }

        return $this->update($id, [
            'target' => $shortened->target,
            'target_type' => $shortened->targetType->value,
            'status' => $shortened->status->value,
        ]);
    }

    /**
     * Adds many rules at once (import, suggestions, site config). Every rule is validated against the stored rules
     * and the rules accepted before it; rejected ones are reported and skipped. Above RELATION_CHECK_LIMIT
     * rules only the field checks run. Ids and timestamps are assigned here.
     *
     * @param list<Rule> $rules
     * @param string     $action RuleEvents::ACTION_*
     *
     * @return array{created: list<Rule>, rejected: list<array{index: int, issues: list<ValidationIssue>}>}
     */
    public function createMany(array $rules, string $action = RuleEvents::ACTION_IMPORT): array
    {
        $prepared = array_map(fn (Rule $r): Rule => $r->with(['id' => $this->newId()]), $rules);
        $rejected = [];
        $created = [];
        $this->services->repository()->transaction(function (array $current) use ($prepared, &$rejected, &$created): array {
            $rejected = [];
            $created = [];
            // The ids were generated here, so a clash with a stored rule (another process wrote in between, or a
            // hand-edited id) is fixed by taking another id instead of failing the whole import.
            $taken = array_fill_keys(array_map(static fn (Rule $r): string => $r->id, $current), true);
            $batch = [];
            foreach ($prepared as $rule) {
                $rule = $this->withFreshId($rule, $taken);
                $taken[$rule->id] = true;
                $batch[] = $rule;
            }
            $verdicts = $this->checkBatch($batch, $current);
            foreach ($batch as $i => $rule) {
                $verdict = $verdicts[$i];
                if ($verdict->hasErrors()) {
                    $rejected[] = ['index' => $i, 'issues' => $verdict->issues];
                    continue;
                }
                $current[] = $rule;
                $created[] = $rule;
            }

            return $current;
        });
        if ($created !== []) {
            $this->afterWrite();
            foreach ($created as $rule) {
                $this->fire($rule, null, $action);
            }
        }

        return ['created' => $created, 'rejected' => $rejected];
    }

    /**
     * Validates candidates one after the other, each against $existing and the candidates accepted before it.
     * Nothing is stored. Above RELATION_CHECK_LIMIT candidates only the field checks run.
     *
     * @param list<Rule> $candidates
     * @param list<Rule> $existing
     *
     * @return list<ValidationResult> one per candidate, same order
     */
    public function checkBatch(array $candidates, array $existing): array
    {
        $relate = count($candidates) <= self::RELATION_CHECK_LIMIT;
        $known = $existing;
        $out = [];
        foreach ($candidates as $rule) {
            $result = $relate ? $this->validator()->validate($rule, $known) : $this->validator()->validateFields($rule);
            if (!$result->hasErrors()) {
                $known[] = $rule;
            }
            $out[] = $result;
        }

        return $out;
    }

    /**
     * A new rule from a request body: defaults for status and origin, fresh id.
     *
     * @param array<mixed> $body
     *
     * @throws RuleValidationException when a field has the wrong type or value
     */
    public function buildNew(array $body): Rule
    {
        $parsed = RuleInput::parse($body);
        if ($parsed['issues'] !== []) {
            throw new RuleValidationException($parsed['issues']);
        }

        $fields = $parsed['fields'];
        if (!isset($fields['target_type']) && isset($fields['target']) && self::isAbsoluteUrl((string) $fields['target'])) {
            $fields['target_type'] = TargetType::Url->value;
        }

        return Rule::fromArray($fields + ['status' => $this->defaultStatus()->value, 'origin' => RuleSource::Manual->value]);
    }

    // ---------------------------------------------------------------- internals

    private static function isAbsoluteUrl(string $target): bool
    {
        return preg_match('#^[a-z][a-z0-9+.\-]*://#i', trim($target)) === 1;
    }

    /**
     * The rule with a new generated id when its id is in $taken.
     *
     * @param array<string, true> $taken
     */
    private function withFreshId(Rule $rule, array $taken): Rule
    {
        while (isset($taken[$rule->id])) {
            $rule = $rule->with(['id' => $this->newId()]);
        }

        return $rule;
    }

    private function newId(): string
    {
        return $this->ids === null ? Ids::rule() : ($this->ids)();
    }

    private function afterWrite(): void
    {
        try {
            $this->services->compiledCache()->invalidate();
        } catch (Throwable) {
            // The cache notices the changed file on its own (mtime and size).
        }
        $this->report = null;
        $this->reportRevision = null;
        $this->reportValidUntil = null;
    }

    private function fire(Rule $rule, ?Rule $previous, string $action): void
    {
        try {
            $this->events->saved($rule, $previous, $action);
        } catch (Throwable) {
            // A failing listener must not undo or hide a saved change.
        }
    }

    /**
     * @param list<Rule> $rules
     */
    private static function byId(array $rules, string $id): ?Rule
    {
        foreach ($rules as $rule) {
            if ($rule->id === $id) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * @param list<string> $ids
     * @param list<Rule>   $found
     *
     * @return list<array{id: string, reason: string, issues: list<ValidationIssue>}>
     */
    private static function missing(array $ids, array $found): array
    {
        $have = array_flip(array_map(static fn (Rule $r): string => $r->id, $found));
        $out = [];
        foreach ($ids as $id) {
            if (!isset($have[$id])) {
                $out[] = ['id' => $id, 'reason' => 'not_found', 'issues' => []];
            }
        }

        return $out;
    }

    private static function sameContent(Rule $a, Rule $b): bool
    {
        $x = $a->toArray();
        $y = $b->toArray();
        unset($x['updated_at'], $y['updated_at']);

        return $x === $y;
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
     * @param array<string, int> $counts
     *
     * @return list<array{name: string, count: int}>
     */
    private static function counted(array $counts): array
    {
        $out = [];
        foreach ($counts as $name => $count) {
            $out[] = ['name' => (string) $name, 'count' => $count];
        }
        usort($out, static fn (array $a, array $b): int => ($b['count'] <=> $a['count']) ?: strcasecmp($a['name'], $b['name']));

        return $out;
    }

    /**
     * @return callable(Rule): ?Rule
     */
    private function bulkChange(string $action, mixed $value): callable
    {
        switch ($action) {
            case 'enable':
                return static fn (Rule $r): Rule => $r->with(['enabled' => true]);
            case 'disable':
                return static fn (Rule $r): Rule => $r->with(['enabled' => false]);
            case 'set_status':
                $code = is_int($value) ? $value : (is_string($value) && ctype_digit($value) ? (int) $value : 0);
                $status = StatusCode::tryFrom($code);
                if ($status === null) {
                    throw new InvalidInputException('"value" must be a status code (200, 301, 302, 307, 308, 410 or 451).', field: 'value');
                }

                return static fn (Rule $r): Rule => $r->with($status->needsTarget() ? ['status' => $status->value] : ['status' => $status->value, 'target' => '']);
            case 'set_group':
                if ($value !== null && !is_string($value)) {
                    throw new InvalidInputException('"value" must be a group name.', field: 'value');
                }
                $group = trim((string) $value);

                return static fn (Rule $r): Rule => $r->with(['group' => $group]);
            default:
                if (!is_string($value) || trim($value) === '') {
                    throw new InvalidInputException('"value" must be a tag.', field: 'value');
                }
                $tag = trim($value);
                if ($action === 'add_tag') {
                    return static fn (Rule $r): Rule => in_array($tag, $r->tags, true) ? $r : $r->with(['tags' => [...$r->tags, $tag]]);
                }

                return static fn (Rule $r): Rule => $r->with(['tags' => array_values(array_filter($r->tags, static fn (string $t): bool => $t !== $tag))]);
        }
    }

    /**
     * Sorts in place. The natural order is the matcher's: priority high to low, exact before wildcard before regex,
     * older first, then id. Every other sort falls back to it for equal values. Done on plain columns with
     * array_multisort(), which is about ten times faster than usort() with a callback at 10,000 rules.
     *
     * @param list<Rule>              $rules
     * @param array<string, RuleStats> $stats
     */
    private function sortRules(array &$rules, RuleQuery $q, array $stats): void
    {
        if (count($rules) < 2) {
            return;
        }
        $ts = static fn (?DateTimeImmutable $d): int => $d?->getTimestamp() ?? 0;
        $primary = $priority = $matchType = $created = $ids = [];
        foreach ($rules as $rule) {
            $primary[] = match ($q->sort) {
                'priority' => 0,
                'source' => strtolower($rule->source),
                'target' => strtolower($rule->target),
                'status' => $rule->status->value,
                'hits' => ($stats[$rule->id]->total ?? 0),
                'last_hit' => $ts($stats[$rule->id]->lastHit ?? null),
                'created_at' => $ts($rule->createdAt),
                default => $ts($rule->updatedAt),
            };
            $priority[] = $rule->priority;
            $matchType[] = $rule->matchType->order();
            $created[] = $ts($rule->createdAt);
            $ids[] = $rule->id;
        }
        $text = $q->sort === 'source' || $q->sort === 'target';
        $primaryOrder = $q->sort === 'priority' ? SORT_ASC : ($q->direction === 'asc' ? SORT_ASC : SORT_DESC);
        // "priority asc" is the exact reverse of the natural order.
        $reverse = $q->sort === 'priority' && $q->direction === 'asc';
        array_multisort(
            $primary,
            $primaryOrder,
            $text ? SORT_STRING : SORT_NUMERIC,
            $priority,
            $reverse ? SORT_ASC : SORT_DESC,
            SORT_NUMERIC,
            $matchType,
            $reverse ? SORT_DESC : SORT_ASC,
            SORT_NUMERIC,
            $created,
            $reverse ? SORT_DESC : SORT_ASC,
            SORT_NUMERIC,
            $ids,
            $reverse ? SORT_DESC : SORT_ASC,
            SORT_STRING,
            $rules,
        );
    }

    // ---------------------------------------------------------------- enrichment

    /**
     * @param list<Rule> $rules
     *
     * @return array{stats: array<string, RuleStats>, states: array<string, string>, badges: array<string, list<string>>, issues: array<string, list<ValidationIssue>>, unused_by_days: array<string, true>}
     */
    private function context(array $rules, string $revision): array
    {
        $now = $this->services->clock()->now();
        $report = $this->report($rules, $revision);
        $stats = $this->stats->store()->all();
        $dead = $this->deadTargets($rules);
        $unused = array_flip($this->unusedIdsFrom($rules, $this->unusedDays()));

        $states = [];
        $badges = [];
        $issues = [];
        foreach ($rules as $rule) {
            $states[$rule->id] = self::state($rule, $now);
            $issues[$rule->id] = $report->issuesFor($rule->id);
            $badges[$rule->id] = $this->badges($rule, $issues[$rule->id], $now, isset($dead[$rule->id]), isset($unused[$rule->id]));
        }

        return ['stats' => $stats, 'states' => $states, 'badges' => $badges, 'issues' => $issues, 'unused_by_days' => []];
    }

    /**
     * @param array{stats: array<string, RuleStats>, states: array<string, string>, badges: array<string, list<string>>, issues: array<string, list<ValidationIssue>>, unused_by_days: array<string, true>} $ctx
     *
     * @return Row
     */
    private function present(Rule $rule, array $ctx): array
    {
        $row = $this->plain($rule);
        $row['stats'] = $this->statsRow($ctx['stats'][$rule->id] ?? new RuleStats());
        $row['badges'] = $ctx['badges'][$rule->id];
        $row['issues'] = array_map(static fn (ValidationIssue $i): array => $i->toArray(), $ctx['issues'][$rule->id]);

        return $row;
    }

    /**
     * @return array{total: int, last_hit: string|null, daily: array<string, int>|stdClass}
     */
    private function statsRow(RuleStats $stats): array
    {
        return [
            'total' => $stats->total,
            'last_hit' => $stats->lastHit?->format(Rule::DATE_FORMAT),
            'daily' => $stats->daily === [] ? new stdClass() : $stats->daily,
        ];
    }

    private static function state(Rule $rule, DateTimeImmutable $now): string
    {
        if (!$rule->enabled) {
            return 'disabled';
        }
        if ($rule->isExpired($now)) {
            return 'expired';
        }

        return $rule->isScheduled($now) ? 'scheduled' : 'active';
    }

    /**
     * @param list<ValidationIssue> $issues
     *
     * @return list<string>
     */
    private function badges(Rule $rule, array $issues, DateTimeImmutable $now, bool $dead, bool $unused): array
    {
        $badges = [self::state($rule, $now)];
        $extra = [];
        foreach ($issues as $issue) {
            $badge = match ($issue->code) {
                'chain', 'chain_too_deep' => 'chain',
                'loop', 'self_redirect' => 'loop',
                'conflict', 'duplicate' => 'conflict',
                default => null,
            };
            if ($badge !== null) {
                $extra[$badge] = true;
            }
        }
        foreach (['chain', 'loop', 'conflict'] as $badge) {
            if (isset($extra[$badge])) {
                $badges[] = $badge;
            }
        }
        if ($dead) {
            $badges[] = 'dead_target';
        }
        if ($unused) {
            $badges[] = 'unused';
        }

        return $badges;
    }

    /**
     * @param list<Rule> $rules
     *
     * @return array<string, true>
     */
    private function deadTargets(array $rules): array
    {
        $dead = [];
        $byId = [];
        foreach ($rules as $rule) {
            $byId[$rule->id] = $rule;
        }
        foreach ($this->services->checkResultStore()->all() as $id => $result) {
            $rule = $byId[$id] ?? null;
            if ($rule === null || !$result->isDead() || !$rule->enabled) {
                continue;
            }
            // A result older than the last edit says nothing about the current target.
            if ($rule->updatedAt !== null && $rule->updatedAt > $result->checkedAt) {
                continue;
            }
            $dead[$id] = true;
        }

        return $dead;
    }

    private function unusedDays(): int
    {
        return max(1, $this->services->int('stats.unused_days', self::DEFAULT_UNUSED_DAYS));
    }

    /**
     * @param list<Rule> $rules
     *
     * @return list<string>
     */
    private function unusedIdsFrom(array $rules, int $days): array
    {
        $now = $this->services->clock()->now();
        $created = [];
        foreach ($rules as $rule) {
            if ($rule->isActive($now)) {
                $created[$rule->id] = $rule->createdAt;
            }
        }

        return $this->stats->store()->unusedSince($created, $now->modify(sprintf('-%d days', max(1, $days))));
    }

    /**
     * @param array<string, mixed>|null $preview
     *
     * @return array<string, mixed>|null
     */
    private function previewResult(?array $preview): ?array
    {
        if ($preview === null) {
            return null;
        }
        $matched = ($preview['matched_candidate'] ?? false) === true;
        $captures = is_array($preview['captures'] ?? null) ? $preview['captures'] : [];

        return [
            'matched' => $matched,
            'status' => $preview['status'] ?? null,
            'location' => $preview['location'] ?? '',
            'captures' => $captures === [] ? new stdClass() : $captures,
            'rule_id' => $preview['rule_id'] ?? null,
            'rules' => $preview['rules'] ?? [],
            'reason' => $matched ? 'matched' : 'other_rule',
            'trace' => $preview['trace'] ?? [],
        ];
    }

    // ---------------------------------------------------------------- analysis cache

    /**
     * The chain analysis over $rules, cached per revision.
     *
     * @param list<Rule> $rules
     */
    private function report(array $rules, string $revision): AnalysisReport
    {
        $now = $this->services->clock()->now()->getTimestamp();
        // A long-lived instance must not keep serving an analysis a rule has outlived (expiry, start).
        if ($this->report !== null && $this->reportRevision === $revision && ($this->reportValidUntil === null || $this->reportValidUntil > $now)) {
            return $this->report;
        }
        $fingerprint = $this->analysisFingerprint();
        $file = $this->services->cacheDir() . '/analysis-' . $revision . '.php';

        $cached = null;
        if (is_file($file)) {
            try {
                $cached = @include $file;
            } catch (Throwable) {
                $cached = null;
            }
        }
        if (
            is_array($cached)
            && ($cached['fingerprint'] ?? null) === $fingerprint
            && ($cached['valid_until'] === null || (is_int($cached['valid_until']) && $cached['valid_until'] > $now))
            && is_array($cached['report'] ?? null)
        ) {
            /** @var array<string, mixed> $data */
            $data = $cached['report'];

            return $this->remember(self::hydrate($data), $revision, is_int($cached['valid_until']) ? $cached['valid_until'] : null);
        }

        $report = (new ChainAnalyzer($this->services->matcherOptions(), $this->services->clock()))->analyze($rules);
        $validUntil = $this->validUntil($rules);
        $this->store($file, $report, $fingerprint, $validUntil);

        return $this->remember($report, $revision, $validUntil);
    }

    private function remember(AnalysisReport $report, string $revision, ?int $validUntil): AnalysisReport
    {
        $this->reportRevision = $revision;
        $this->reportValidUntil = $validUntil;

        return $this->report = $report;
    }

    /**
     * Earliest moment a rule starts or expires: the analysis (expired issue, states) changes then.
     *
     * @param list<Rule> $rules
     */
    private function validUntil(array $rules): ?int
    {
        $now = $this->services->clock()->now()->getTimestamp();
        $next = null;
        foreach ($rules as $rule) {
            foreach ([$rule->activeFrom, $rule->expiresAt] as $moment) {
                if ($moment !== null && $moment->getTimestamp() > $now && ($next === null || $moment->getTimestamp() < $next)) {
                    $next = $moment->getTimestamp();
                }
            }
        }

        return $next;
    }

    private function analysisFingerprint(): string
    {
        return md5((string) json_encode([
            $this->services->int('redirects.max_chain_depth', 10),
            $this->services->config('redirects.query_ignore'),
            $this->services->stringList('security.allowed_hosts'),
            $this->services->bool('security.allow_any_external', false),
            $this->services->int('security.regex_backtrack_limit', 100000),
        ]));
    }

    private function store(string $file, AnalysisReport $report, string $fingerprint, ?int $validUntil): void
    {
        $data = [
            'fingerprint' => $fingerprint,
            'valid_until' => $validUntil,
            'report' => $report->toArray(),
        ];
        try {
            AtomicFile::write($file, '<?php return ' . var_export($data, true) . ";\n");
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($file, true);
            }
            foreach (glob($this->services->cacheDir() . '/analysis-*.php') ?: [] as $old) {
                if ($old !== $file) {
                    @unlink($old);
                }
            }
        } catch (AtomicFileException) {
            // The analysis is still usable, it just is not cached.
        }
    }

    /**
     * @param array<string, mixed> $data AnalysisReport::toArray() output
     */
    private static function hydrate(array $data): AnalysisReport
    {
        $issues = [];
        $stored = is_array($data['issues'] ?? null) ? $data['issues'] : [];
        foreach ($stored as $id => $list) {
            foreach (is_array($list) ? $list : [] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $issues[(string) $id][] = new ValidationIssue(
                    Severity::tryFrom(is_string($row['severity'] ?? null) ? $row['severity'] : '') ?? Severity::Warning,
                    is_string($row['code'] ?? null) ? $row['code'] : '',
                    is_string($row['field'] ?? null) ? $row['field'] : '',
                    is_string($row['message'] ?? null) ? $row['message'] : '',
                    is_array($row['params'] ?? null) ? $row['params'] : [],
                );
            }
        }

        /** @var list<array<string, mixed>> $chains */
        $chains = is_array($data['chains'] ?? null) ? array_values($data['chains']) : [];
        /** @var list<array{rule_ids: list<string>, paths: list<string>}> $loops */
        $loops = is_array($data['loops'] ?? null) ? array_values($data['loops']) : [];
        /** @var list<list<string>> $conflicts */
        $conflicts = is_array($data['conflicts'] ?? null) ? array_values($data['conflicts']) : [];
        /** @var list<string> $expired */
        $expired = is_array($data['expired'] ?? null) ? array_values($data['expired']) : [];

        return new AnalysisReport($issues, [], $chains, $loops, $conflicts, $expired);
    }
}
