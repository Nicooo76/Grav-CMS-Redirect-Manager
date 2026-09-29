<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Analysis;

use Grav\Plugin\RedirectManager\Domain\ConditionOperator;
use Grav\Plugin\RedirectManager\Domain\MatchPhase;
use Grav\Plugin\RedirectManager\Domain\MatchType;
use Grav\Plugin\RedirectManager\Domain\RequestContext;
use Grav\Plugin\RedirectManager\Domain\Rule;
use Grav\Plugin\RedirectManager\Domain\StatusCode;
use Grav\Plugin\RedirectManager\Domain\TargetType;
use Grav\Plugin\RedirectManager\Matching\MatcherOptions;
use Grav\Plugin\RedirectManager\Security\RegexSafety;
use Grav\Plugin\RedirectManager\Security\TargetGuard;
use Grav\Plugin\RedirectManager\Util\Clock;
use Grav\Plugin\RedirectManager\Util\InvalidPathException;
use Grav\Plugin\RedirectManager\Util\PathNormalizer;

/**
 * Save-time validation and live preview for one candidate rule.
 *
 * validate() checks the fields of the candidate (source, target, status, dates, placeholders, conditions) and then
 * relates it to the existing rules: conflicts and duplicates, shadowing, chains and loops that start at the
 * candidate, and chains from other rules that now pass through it. A rule in $existing with the candidate's id
 * is replaced. Relational checks work on a small rule set built for the candidate (PartialRuleSet), so ten
 * thousand existing rules cost about one pass over the list, not a full compile.
 *
 * Issue codes: source_empty, source_invalid, regex_invalid, regex_catastrophic, regex_too_long, target_required,
 * target_not_allowed_for_status, target_empty|target_protocol_relative|target_scheme|target_host_not_allowed|
 * target_invalid (from TargetGuard), target_placeholder_unknown, passthrough_external, condition_invalid,
 * dates_inverted, expired, loop, self_redirect, chain, chain_too_deep, conflict, duplicate, shadowed, analysis_skipped.
 */
final class RuleValidator
{
    private const PLACEHOLDER = '/\$(\d)|\$\{(\d+)\}|\{([A-Za-z_][A-Za-z0-9_]*)\}/';

    private readonly ConflictDetector $conflicts;
    private readonly ChainWalker $walker;
    private readonly ShadowDetector $shadows;

    public function __construct(
        private readonly MatcherOptions $options,
        private readonly TargetGuard $guard,
        private readonly Clock $clock,
    ) {
        $this->conflicts = new ConflictDetector($options, $clock);
        $this->walker = new ChainWalker($options, $clock);
        $this->shadows = new ShadowDetector();
    }

    /**
     * @param list<Rule> $existing all stored rules; the one with the candidate's id (if any) is ignored
     */
    public function validate(Rule $candidate, array $existing): ValidationResult
    {
        $issues = $this->fieldIssues($candidate);
        foreach ($issues as $issue) {
            if ($issue->isError() && $issue->field === 'source') {
                return new ValidationResult($issues); // without a usable source there is nothing to relate
            }
        }

        return new ValidationResult([...$issues, ...$this->relationIssues($candidate, $existing)]);
    }

    /**
     * Only the checks that look at the candidate itself (fields, regex, target safety, dates), without relating it
     * to other rules. For bulk imports where the relational pass per row would cost too much.
     */
    public function validateFields(Rule $candidate): ValidationResult
    {
        return new ValidationResult($this->fieldIssues($candidate));
    }

    /**
     * What the rules would do with $sampleUrl if the candidate were saved: the Matcher's result (rule_id,
     * status, location, rules, captures, trace) plus "matched_candidate". Null when no rule matches or the URL
     * is unusable. A missing host, scheme or language is taken from the candidate's conditions, so a rule
     * limited to "de" previews without the editor having to say so.
     *
     * @param list<Rule> $existing
     * @return array<string, mixed>|null
     */
    public function preview(Rule $candidate, array $existing, string $sampleUrl, ?string $language = null): ?array
    {
        $ctx = $this->contextFor($candidate, $sampleUrl, $language);
        if ($ctx === null) {
            return null;
        }
        $index = RuleIndex::build($existing, $candidate->id, $this->clock->now());
        $partial = new PartialRuleSet($this->options, $this->clock, $candidate, $index);
        $result = $partial->matcherFor($ctx->path)->match($ctx, MatchPhase::Any);
        if ($result === null) {
            return null;
        }
        $matched = false;
        foreach ($result->rules as $rule) {
            $matched = $matched || $rule->id === $candidate->id;
        }

        return [...$result->toArray(), 'matched_candidate' => $matched];
    }

    /**
     * An example URL path for the rule's source, or null.
     */
    public function sampleFor(Rule $rule): ?string
    {
        return SampleGenerator::url($rule);
    }

    /**
     * @return list<ValidationIssue>
     */
    private function fieldIssues(Rule $rule): array
    {
        $issues = [];
        $this->sourceIssues($rule, $issues);
        $this->targetIssues($rule, $issues);
        $this->conditionIssues($rule, $issues);

        if ($rule->activeFrom !== null && $rule->expiresAt !== null && $rule->expiresAt <= $rule->activeFrom) {
            $issues[] = ValidationIssue::error('dates_inverted', 'expires_at', 'The rule expires before or when it becomes active.', [
                'active_from' => $rule->activeFrom->format(Rule::DATE_FORMAT),
                'expires_at' => $rule->expiresAt->format(Rule::DATE_FORMAT),
            ]);
        } elseif ($rule->isExpired($this->clock->now())) {
            $issues[] = IssueFactory::expired($rule);
        }

        return $issues;
    }

    /**
     * @param list<ValidationIssue> $issues
     */
    private function sourceIssues(Rule $rule, array &$issues): void
    {
        if (trim($rule->source) === '') {
            $issues[] = ValidationIssue::error('source_empty', 'source', 'The source is empty.');

            return;
        }
        if ($rule->matchType === MatchType::Regex) {
            $error = RegexSafety::validate($rule->source, $rule->caseSensitive);
            if ($error !== null) {
                $issues[] = self::regexIssue($error, 'source');
            }

            return;
        }

        $path = trim(PathNormalizer::splitSource($rule->source)[0], " \t\n\r");
        if ($path === '') {
            $issues[] = ValidationIssue::error('source_empty', 'source', 'The source has no path.');

            return;
        }
        if (preg_match('~^[a-z][a-z0-9+.\-]*://~i', $path) === 1) {
            $issues[] = ValidationIssue::error('source_invalid', 'source', 'The source must be a path such as /old-page, not a full URL.');

            return;
        }
        try {
            PathNormalizer::normalize($path);
        } catch (InvalidPathException $e) {
            $issues[] = ValidationIssue::error('source_invalid', 'source', 'The source is not a valid path: ' . $e->getMessage());
        }
    }

    /**
     * @param list<ValidationIssue> $issues
     */
    private function targetIssues(Rule $rule, array &$issues): void
    {
        $target = $rule->target;
        if (!$rule->status->needsTarget()) {
            if (trim($target) !== '') {
                $issues[] = ValidationIssue::warning(
                    'target_not_allowed_for_status',
                    'target',
                    'A rule with status ' . $rule->status->value . ' has no target; the target is ignored.',
                    ['status' => $rule->status->value],
                );
            }

            return;
        }
        if (trim($target) === '') {
            $issues[] = ValidationIssue::error('target_required', 'target', 'A target is required for this status.', ['status' => $rule->status->value]);

            return;
        }
        if ($rule->status === StatusCode::PassThrough
            && ($rule->targetType === TargetType::Url || preg_match('~^[a-z][a-z0-9+.\-]*:~i', $target) === 1)) {
            $issues[] = ValidationIssue::error('passthrough_external', 'target', 'A pass-through rule serves a page of this site; it cannot point to an external URL.');

            return;
        }
        $error = $this->guard->checkTarget($target, $rule->targetType);
        if ($error !== null) {
            $issues[] = ValidationIssue::error($error, 'target', self::targetMessage($error), ['target' => $target]);
        }

        $this->placeholderIssues($rule, $issues);
    }

    /**
     * @param list<ValidationIssue> $issues
     */
    private function placeholderIssues(Rule $rule, array &$issues): void
    {
        if (strpbrk($rule->target, '${') === false || preg_match_all(self::PLACEHOLDER, $rule->target, $found, PREG_SET_ORDER) < 1) {
            return;
        }
        $groups = $this->groups($rule);
        if ($groups === null) {
            return; // the source is invalid, that is reported already
        }
        $reported = [];
        foreach ($found as $match) {
            $name = $match[3] ?? '';
            if ($name !== '') {
                $known = $name === 'lang' || in_array($name, $groups['names'], true);
            } else {
                $number = (int) (($match[2] ?? '') !== '' ? $match[2] : ($match[1] ?? '0'));
                $known = $number >= 1 && $number <= $groups['count'];
            }
            if (!$known && !isset($reported[$match[0]])) {
                $reported[$match[0]] = true;
                $issues[] = ValidationIssue::warning(
                    'target_placeholder_unknown',
                    'target',
                    'The placeholder ' . $match[0] . ' does not refer to a capture of the source (' . $groups['count'] . ' available).',
                    ['placeholder' => $match[0], 'groups' => $groups['count'], 'names' => $groups['names']],
                );
            }
        }
    }

    /**
     * Number and names of the captures the source provides, or null when it cannot be told.
     *
     * @return array{count: int, names: list<string>}|null
     */
    private function groups(Rule $rule): ?array
    {
        if ($rule->matchType === MatchType::Exact) {
            return ['count' => 0, 'names' => []];
        }
        if ($rule->matchType === MatchType::Wildcard) {
            $path = PathNormalizer::splitSource($rule->source)[0];

            return ['count' => substr_count((string) preg_replace('/\*+/', '*', $path), '*'), 'names' => []];
        }
        if (RegexSafety::syntaxError($rule->source, $rule->caseSensitive) !== null) {
            return null;
        }
        // Match the empty string against "(?:pattern)|": every group shows up, unmatched ones as null.
        $regex = RegexSafety::compile('(?:' . $rule->source . ')|', $rule->caseSensitive);
        set_error_handler(static fn (): bool => true, E_WARNING);
        try {
            $matches = [];
            $ok = preg_match($regex, '', $matches, PREG_UNMATCHED_AS_NULL);
        } finally {
            restore_error_handler();
        }
        if ($ok !== 1) {
            return null;
        }
        $count = 0;
        $names = [];
        foreach (array_keys($matches) as $key) {
            if (is_string($key)) {
                $names[] = $key;
            } elseif ($key > $count) {
                $count = $key;
            }
        }

        return ['count' => $count, 'names' => $names];
    }

    /**
     * @param list<ValidationIssue> $issues
     */
    private function conditionIssues(Rule $rule, array &$issues): void
    {
        foreach ($rule->conditions->rules as $i => $condition) {
            if ($condition->name === '') {
                $issues[] = ValidationIssue::error('condition_invalid', 'conditions', 'A header or cookie condition needs a name.', ['index' => $i]);
            }
            if ($condition->operator === ConditionOperator::Regex) {
                $error = $condition->value === '' ? RegexSafety::ERR_INVALID : RegexSafety::validate($condition->value, true);
                if ($error !== null) {
                    $issues[] = self::regexIssue($error, 'conditions', ['index' => $i]);
                }
            }
        }
    }

    /**
     * @param list<Rule> $existing
     * @return list<ValidationIssue>
     */
    private function relationIssues(Rule $candidate, array $existing): array
    {
        $now = $this->clock->now();
        if (!$candidate->enabled || $candidate->isExpired($now)) {
            return []; // an inactive rule takes part in nothing
        }

        $index = RuleIndex::build($existing, $candidate->id, $now);
        $partial = new PartialRuleSet($this->options, $this->clock, $candidate, $index);
        $issues = [];

        // conflicts and duplicates
        $mates = $candidate->matchType === MatchType::Exact
            ? $this->exactMates($candidate, $index)
            : array_values(array_filter($index->wide, static fn (Rule $r): bool => $r->matchType === $candidate->matchType));
        foreach ($this->conflicts->detect([$candidate, ...$mates]) as $group) {
            foreach ($group as $member) {
                if ($member->id === $candidate->id) {
                    array_push($issues, ...IssueFactory::relations($candidate, $group, $this->conflicts));
                }
            }
        }

        // shadowing (exact sources only)
        $winner = $this->shadows->shadowedBy($candidate, $partial->matcherFor(...));
        if ($winner !== null) {
            $issues[] = IssueFactory::shadowed($winner);
        }

        // chains and loops that start at the candidate
        $max = max(1, $this->options->maxChainDepth);
        array_push($issues, ...IssueFactory::fromOutcomes($this->walker->walk($candidate, $partial->matcherFor(...)), $max));

        // chains from other rules that now run through the candidate
        $sample = SampleGenerator::generate($candidate);
        if ($sample !== null) {
            foreach ($this->incomingStarts($sample, $candidate, $index) as $start) {
                foreach ($this->walker->walk($start, $partial->matcherFor(...)) as $outcome) {
                    if ($outcome->kind === ChainOutcome::CHAIN && in_array($candidate->id, $outcome->ruleIds, true)) {
                        $issues[] = IssueFactory::incoming($outcome);
                        break 2;
                    }
                }
            }
        }

        return $issues;
    }

    /**
     * @return list<Rule>
     */
    private function exactMates(Rule $candidate, RuleIndex $index): array
    {
        $path = PathKey::normalized(trim(PathNormalizer::splitSource($candidate->source)[0], " \t\n\r"));

        return $path === null ? [] : ($index->exactByFold[PathKey::fold($path)] ?? []);
    }

    /**
     * Existing rules that may redirect to the candidate's source: plain targets that fold to the same path, and,
     * for wildcard and regex candidates, rules that build their target from captures.
     *
     * @return list<Rule>
     */
    private function incomingStarts(Sample $sample, Rule $candidate, RuleIndex $index): array
    {
        $starts = $index->byTarget[PathKey::fold($sample->path)] ?? [];
        if ($candidate->matchType !== MatchType::Exact) {
            $starts = [...$starts, ...$index->captureTargets];
        }

        return array_values($starts);
    }

    private function contextFor(Rule $candidate, string $url, ?string $language): ?RequestContext
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        if ($url[0] !== '/' && !str_contains($url, '://')) {
            $url = '/' . $url;
        }
        $parts = parse_url($url);
        if ($parts === false) {
            return null;
        }
        try {
            $path = PathNormalizer::normalize($parts['path'] ?? '/');
        } catch (InvalidPathException) {
            return null;
        }
        $conditions = $candidate->conditions;
        $host = strtolower($parts['host'] ?? '');
        $scheme = strtolower($parts['scheme'] ?? '');

        return new RequestContext(
            $path,
            PathKey::parseQuery($parts['query'] ?? ''),
            $host !== '' ? $host : SampleGenerator::firstHost($candidate),
            $scheme !== '' ? $scheme : ($conditions->schemes[0] ?? 'https'),
            $language ?? ($conditions->languages[0] ?? null),
        );
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function regexIssue(string $code, string $field, array $params = []): ValidationIssue
    {
        $message = match ($code) {
            RegexSafety::ERR_TOO_LONG => 'The regular expression is longer than ' . RegexSafety::MAX_LENGTH . ' characters.',
            RegexSafety::ERR_CATASTROPHIC => 'The regular expression can take exponential time (catastrophic backtracking) and is not allowed.',
            default => 'The regular expression is invalid.',
        };

        return ValidationIssue::error($code, $field, $message, $params);
    }

    private static function targetMessage(string $code): string
    {
        return match ($code) {
            TargetGuard::ERR_EMPTY => 'The target is empty.',
            TargetGuard::ERR_PROTOCOL_RELATIVE => 'A target starting with // or /\\ would redirect to another site.',
            TargetGuard::ERR_SCHEME => 'The target uses a scheme that is not allowed (only http and https).',
            TargetGuard::ERR_HOST_NOT_ALLOWED => 'The target host is not in the list of allowed external hosts.',
            default => 'The target is not valid.',
        };
    }
}
