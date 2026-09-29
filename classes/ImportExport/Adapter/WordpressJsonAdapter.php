<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\ImportExport\Adapter;

use Grav\Plugin\RedirectManager\Domain\Condition;
use Grav\Plugin\RedirectManager\Domain\ConditionKind;
use Grav\Plugin\RedirectManager\Domain\ConditionOperator;
use Grav\Plugin\RedirectManager\Domain\MatchType;
use Grav\Plugin\RedirectManager\Domain\QueryMode;
use Grav\Plugin\RedirectManager\Domain\Rule;
use Grav\Plugin\RedirectManager\Domain\StatusCode;
use Grav\Plugin\RedirectManager\ImportExport\ExportAdapter;
use Grav\Plugin\RedirectManager\ImportExport\ExportNote;
use Grav\Plugin\RedirectManager\ImportExport\ExportOptions;
use Grav\Plugin\RedirectManager\ImportExport\ExportResult;
use Grav\Plugin\RedirectManager\ImportExport\ImportAdapter;
use Grav\Plugin\RedirectManager\ImportExport\ImportException;
use Grav\Plugin\RedirectManager\ImportExport\ImportIssue;
use Grav\Plugin\RedirectManager\ImportExport\ImportOptions;
use Grav\Plugin\RedirectManager\ImportExport\ImportRow;
use Grav\Plugin\RedirectManager\ImportExport\Support\HeaderCond;
use Grav\Plugin\RedirectManager\ImportExport\Support\Lossy;
use Grav\Plugin\RedirectManager\ImportExport\Support\Pattern;
use Grav\Plugin\RedirectManager\ImportExport\Support\RowFactory;
use Grav\Plugin\RedirectManager\Util\Clock;

/**
 * JSON export of the WordPress "Redirection" plugin (Tools, Import/Export).
 *
 * Supported: URL redirects (plain and regex) with its query, case and trailing-slash flags, 410 error
 * rules, pass-through, and the match types server, agent, referrer, header and cookie. Other match
 * types (login, role, ip, page, language, custom) and the random action are skipped with a warning.
 */
final class WordpressJsonAdapter implements ImportAdapter, ExportAdapter
{
    private const DEFAULT_GROUP = 'Redirections';

    public function __construct(private readonly Clock $clock)
    {
    }

    public function parse(string $content, ImportOptions $options): array
    {
        $data = json_decode($content, true, JsonAdapter::MAX_DEPTH);
        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            $code = json_last_error() === JSON_ERROR_DEPTH ? 'too_deep' : 'invalid_json';
            throw new ImportException(new ImportIssue($code, $code === 'too_deep' ? 'The JSON is nested too deeply.' : 'The file is not valid JSON: ' . json_last_error_msg()));
        }
        if (!is_array($data) || !isset($data['redirects']) || !is_array($data['redirects'])) {
            throw new ImportException(new ImportIssue('invalid_structure', 'The file has no "redirects" list; it is not a Redirection export.'));
        }
        $items = array_values($data['redirects']);
        if (count($items) > $options->maxRows) {
            throw new ImportException(new ImportIssue('too_many_rows', 'The file has more rows than allowed.', ['max' => $options->maxRows]));
        }

        $groups = [];
        foreach (is_array($data['groups'] ?? null) ? $data['groups'] : [] as $group) {
            if (is_array($group) && isset($group['id'])) {
                $name = is_scalar($group['name'] ?? null) ? trim((string) $group['name']) : '';
                $groups[(string) $group['id']] = $name === self::DEFAULT_GROUP ? '' : $name;
            }
        }

        // Redirection evaluates by position; keep that order (stable for equal positions).
        $indexed = [];
        foreach ($items as $i => $item) {
            $position = is_array($item) && is_numeric($item['position'] ?? null) ? (int) $item['position'] : PHP_INT_MAX;
            $indexed[] = [$position, $i, $item];
        }
        usort($indexed, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        $factory = new RowFactory($options, $this->clock);
        $rows = [];
        foreach ($indexed as [, $i, $item]) {
            $raw = json_encode($item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            $raw = $raw === false ? '' : $raw;
            if (!is_array($item)) {
                $rows[] = $factory->error($i + 1, $raw, new ImportIssue('invalid_row', 'The entry is not an object.'));
                continue;
            }
            /** @var array<string, mixed> $item */
            array_push($rows, ...$this->item($i + 1, $raw, $item, $groups, $factory));
        }

        return $rows;
    }

    /**
     * @param array<string, mixed>  $item
     * @param array<string, string> $groups
     * @return list<ImportRow>
     */
    private function item(int $no, string $raw, array $item, array $groups, RowFactory $factory): array
    {
        $regexFlag = self::truthy($item['regex'] ?? false);
        $source = self::str($item['url'] ?? '');
        if ($source === '' || ($regexFlag && $source === 'regex')) {
            $source = self::str($item['match_url'] ?? '');
        }

        $flags = [];
        $matchData = $item['match_data'] ?? null;
        if (is_array($matchData) && is_array($matchData['source'] ?? null)) {
            $flags = $matchData['source'];
        }
        $fields = [
            'source' => $source,
            'match_type' => $regexFlag ? 'regex' : 'exact',
            'note' => self::str($item['title'] ?? ''),
            'group' => $groups[self::str($item['group_id'] ?? '')] ?? '',
        ];
        if ($flags !== []) {
            $fields['case_sensitive'] = self::truthy($flags['flag_case'] ?? false);
            $fields['ignore_trailing_slash'] = self::truthy($flags['flag_trailing'] ?? false);
            $query = self::str($flags['flag_query'] ?? '');
            if (in_array($query, ['exact', 'ignore', 'pass'], true)) {
                $fields['query_mode'] = $query;
            }
        }
        if (array_key_exists('enabled', $item)) {
            $fields['enabled'] = self::truthy($item['enabled']);
        } elseif (isset($item['status'])) {
            $fields['enabled'] = self::str($item['status']) !== 'disabled';
        }

        // Action.
        $code = is_numeric($item['action_code'] ?? null) ? (int) $item['action_code'] : 301;
        $type = self::str($item['action_type'] ?? 'url');
        $data = $item['action_data'] ?? null;
        $data = is_array($data) ? $data : ['url' => is_string($data) ? $data : ''];
        $matchType = self::str($item['match_type'] ?? 'url');

        if ($type === 'error') {
            if ($code !== 410) {
                return [$factory->skipped($no, $raw, new ImportIssue('unsupported_status', 'Only 410 error rules are supported.', ['status' => $code]))];
            }
            $fields['status'] = 410;
        } elseif ($type === 'pass') {
            $fields['status'] = 200;
        } elseif ($type === 'url') {
            $fields['status'] = $code;
        } else {
            return [$factory->skipped($no, $raw, new ImportIssue('unsupported_action', 'This Redirection action is not supported and was skipped.', ['action' => $type]))];
        }

        if ($matchType === 'url' || $matchType === '') {
            $fields['target'] = self::str($data['url'] ?? '');

            return [$factory->make($no, $raw, $fields)];
        }

        $cond = self::condition($matchType, $data);
        if ($cond === null && $matchType !== 'server') {
            return [$factory->skipped($no, $raw, new ImportIssue('unsupported_match_type', 'This Redirection match type is not supported and was skipped.', ['match_type' => $matchType]))];
        }

        $rows = [];
        foreach (['url_from' => false, 'url_notfrom' => true] as $key => $negate) {
            $target = self::str($data[$key] ?? '');
            if ($target === '' && $fields['status'] !== 410) {
                continue;
            }
            $variant = $fields;
            $variant['target'] = $target;
            if ($matchType === 'server') {
                $server = strtolower(self::str($data['server'] ?? ''));
                if ($server === '' || $negate) {
                    if ($negate && $server !== '') {
                        $rows[] = $factory->skipped($no, $raw, new ImportIssue('not_from_skipped', 'The "otherwise" target of a server match is not supported.'));
                    }
                    continue;
                }
                $variant['conditions'] = ['hosts' => [$server]];
            } elseif ($cond !== null) {
                $variant['conditions'] = ['rules' => [(new Condition($cond->kind, $cond->name, $cond->operator, $cond->value, $negate))->toArray()]];
            }
            $rows[] = $factory->make($no, $raw, $variant);
            if ($fields['status'] === 410) {
                break;
            }
        }
        if ($rows === []) {
            $rows[] = $factory->skipped($no, $raw, new ImportIssue('no_target', 'The conditional redirect has no target.'));
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function condition(string $matchType, array $data): ?Condition
    {
        $regex = self::truthy($data['regex'] ?? false);
        switch ($matchType) {
            case 'agent':
                $value = self::str($data['agent'] ?? '');
                $kind = ConditionKind::Header;
                $name = 'User-Agent';
                $contains = true;
                break;
            case 'referrer':
                $value = self::str($data['referrer'] ?? '');
                $kind = ConditionKind::Header;
                $name = 'Referer';
                $contains = true;
                break;
            case 'header':
                $value = self::str($data['value'] ?? '');
                $kind = ConditionKind::Header;
                $name = self::str($data['name'] ?? '');
                $contains = false;
                break;
            case 'cookie':
                $value = self::str($data['value'] ?? '');
                $kind = ConditionKind::Cookie;
                $name = self::str($data['name'] ?? '');
                $contains = false;
                break;
            default:
                return null;
        }
        if ($name === '' || $value === '') {
            return null;
        }
        if ($regex) {
            return HeaderCond::fromPattern($kind, $name, $value, false);
        }

        return new Condition($kind, $name, $contains ? ConditionOperator::Contains : ConditionOperator::Equals, $value);
    }

    public function export(array $rules, ExportOptions $options): ExportResult
    {
        $groupIds = [];
        $groups = [];
        $redirects = [];
        $skipped = [];
        $lossy = [];
        $position = 0;
        foreach ($rules as $rule) {
            $built = self::redirect($rule);
            if (is_string($built)) {
                $skipped[] = new ExportNote($rule->id, $built, self::reason($built));
                continue;
            }
            $name = $rule->group === '' ? self::DEFAULT_GROUP : $rule->group;
            if (!isset($groupIds[$name])) {
                $groupIds[$name] = count($groupIds) + 1;
                $groups[] = ['id' => $groupIds[$name], 'name' => $name, 'module_id' => 1, 'enabled' => true, 'moduleName' => 'WordPress', 'redirects' => 0];
            }
            $built['id'] = count($redirects) + 1;
            $built['group_id'] = $groupIds[$name];
            $built['position'] = $position++;
            $built['title'] = $rule->note;
            $built['enabled'] = $rule->enabled;
            $built['status'] = $rule->enabled ? 'enabled' : 'disabled';
            $redirects[] = $built;
            array_push($lossy, ...Lossy::notes($rule, ['active_from', 'expires_at', 'continue', 'only_if_not_found', 'target_page', 'query_ignore']));
        }
        foreach ($groups as &$group) {
            $group['redirects'] = count(array_filter($redirects, static fn (array $r): bool => $r['group_id'] === $group['id']));
        }
        unset($group);

        $data = [
            'plugin' => ['version' => '5.4.0', 'date' => $this->clock->now()->format('D, d M Y H:i:s +0000')],
            'groups' => $groups,
            'redirects' => $redirects,
        ];

        return new ExportResult(
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n",
            skipped: $skipped,
            lossy: $lossy,
            exported: count($redirects),
        );
    }

    /**
     * @return array<string, mixed>|string the redirect record (without id, group, position, title, status) or a skip code
     */
    private static function redirect(Rule $rule): array|string
    {
        $c = $rule->conditions;
        if ($c->languages !== [] || $c->schemes !== []) {
            return 'conditions_not_supported';
        }
        if ($rule->queryMode === QueryMode::Params) {
            return 'query_params_not_supported';
        }
        if ($rule->status === StatusCode::UnavailableForLegalReasons) {
            return 'status_not_supported';
        }
        $count = ($c->hosts !== [] ? 1 : 0) + count($c->rules);
        if ($count > 1 || count($c->hosts) > 1) {
            return 'multiple_conditions_not_supported';
        }

        $target = $rule->target;
        $source = $rule->source;
        $regex = false;
        if ($rule->matchType === MatchType::Wildcard) {
            $source = Pattern::wildcardToRegex($source);
            $regex = true;
        } elseif ($rule->matchType === MatchType::Regex) {
            $regex = true;
            $converted = Pattern::namedToNumbered($target, $source);
            if ($converted === null) {
                return 'named_group_unresolved';
            }
            $target = $converted;
        }
        if ($rule->matchType !== MatchType::Regex && $rule->queryMode !== QueryMode::Exact && str_contains($source, '?')) {
            $source = substr($source, 0, (int) strpos($source, '?'));
        }

        $type = 'url';
        $code = $rule->status->value;
        $data = ['url' => $target];
        if ($rule->status === StatusCode::Gone) {
            $type = 'error';
            $data = null;
        } elseif ($rule->status === StatusCode::PassThrough) {
            $type = 'pass';
            $code = 0;
        }

        $matchType = 'url';
        if ($count === 1) {
            $matchType = self::conditionData($rule, $target, $data);
            if ($matchType === null) {
                return 'condition_not_supported';
            }
        }

        return [
            'url' => $source,
            'match_url' => $source,
            'match_data' => ['source' => [
                'flag_query' => match ($rule->queryMode) {
                    QueryMode::Exact => 'exact',
                    QueryMode::Pass => 'pass',
                    default => 'ignore',
                },
                'flag_case' => $rule->caseSensitive,
                'flag_trailing' => $rule->ignoreTrailingSlash,
                'flag_regex' => $regex,
            ]],
            'action_code' => $code,
            'action_type' => $type,
            'action_data' => $data,
            'match_type' => $matchType,
            'regex' => $regex,
            'hits' => 0,
            'last_access' => '0000-00-00 00:00:00',
        ];
    }

    /**
     * Fills action_data for a conditional rule and returns the Redirection match type.
     *
     * @param array<string, mixed>|null $data
     */
    private static function conditionData(Rule $rule, string $target, ?array &$data): ?string
    {
        $c = $rule->conditions;
        $key = 'url_from';
        if ($c->hosts !== []) {
            if (str_starts_with($c->hosts[0], '*')) {
                return null;
            }
            $data = ['server' => $c->hosts[0], 'url_from' => $target];

            return 'server';
        }
        $cond = $c->rules[0];
        if ($cond->negate) {
            $key = 'url_notfrom';
        }
        $data = [$key => $target];
        if ($cond->operator === ConditionOperator::Exists && $cond->negate) {
            return null;
        }
        $name = strtolower($cond->name);
        $isAgent = $cond->kind === ConditionKind::Header && $name === 'user-agent';
        $isReferrer = $cond->kind === ConditionKind::Header && $name === 'referer';
        $plain = ($cond->operator === ConditionOperator::Contains && ($isAgent || $isReferrer))
            || ($cond->operator === ConditionOperator::Equals && !$isAgent && !$isReferrer);
        if ($plain) {
            $value = $cond->value;
            $regex = false;
        } else {
            [$value] = HeaderCond::toPattern($cond);
            $regex = true;
        }
        if ($isAgent) {
            $data += ['agent' => $value, 'regex' => $regex];

            return 'agent';
        }
        if ($isReferrer) {
            $data += ['referrer' => $value, 'regex' => $regex];

            return 'referrer';
        }
        $data += ['name' => $cond->name, 'value' => $value, 'regex' => $regex];

        return $cond->kind === ConditionKind::Cookie ? 'cookie' : 'header';
    }

    private static function reason(string $code): string
    {
        return match ($code) {
            'conditions_not_supported' => 'Redirection has no language or scheme condition.',
            'query_params_not_supported' => 'Redirection cannot require single query parameters.',
            'status_not_supported' => 'Redirection cannot answer 451.',
            'multiple_conditions_not_supported' => 'Redirection supports one match condition per redirect.',
            'named_group_unresolved' => 'The target uses a named placeholder that the regex does not define.',
            default => 'The rule cannot be expressed in this format.',
        };
    }

    private static function truthy(mixed $value): bool
    {
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }

        return (bool) $value;
    }

    private static function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Shared with the CSV adapter: rows a redirect record becomes.
     *
     * @return array<string, mixed>|string
     */
    public static function record(Rule $rule): array|string
    {
        return self::redirect($rule);
    }
}
