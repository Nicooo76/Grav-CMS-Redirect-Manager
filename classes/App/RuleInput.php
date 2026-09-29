<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\App;

use DateTimeImmutable;
use Exception;
use Grav\Plugin\RedirectManager\Analysis\ValidationIssue;
use Grav\Plugin\RedirectManager\Domain\ConditionKind;
use Grav\Plugin\RedirectManager\Domain\ConditionOperator;
use Grav\Plugin\RedirectManager\Domain\MatchType;
use Grav\Plugin\RedirectManager\Domain\QueryMode;
use Grav\Plugin\RedirectManager\Domain\Rule;
use Grav\Plugin\RedirectManager\Domain\RuleSource;
use Grav\Plugin\RedirectManager\Domain\StatusCode;
use Grav\Plugin\RedirectManager\Domain\TargetType;

/**
 * Checks the rule fields of a request body before they reach Rule::fromArray(), which would silently turn a wrong
 * value into a default (status 999 into 301). Only the fields present are checked, so the same code serves create,
 * partial update and validate.
 *
 * Read-only fields (id, timestamps, stats, badges, issues) and unknown keys are ignored. Every finding is an error
 * issue with code "invalid_value" (or "invalid_type") and the field name.
 */
final class RuleInput
{
    private const STRINGS = ['source', 'target', 'note', 'group'];
    private const BOOLS = ['enabled', 'case_sensitive', 'ignore_trailing_slash', 'continue', 'only_if_not_found'];
    private const ENUMS = ['match_type', 'target_type', 'query_mode', 'origin'];
    private const DATES = ['active_from', 'expires_at'];
    private const LISTS = ['query_ignore', 'tags'];

    /** Fields a client may send. */
    public const FIELDS = [
        'source', 'target', 'match_type', 'status', 'enabled', 'priority', 'target_type', 'case_sensitive',
        'ignore_trailing_slash', 'query_mode', 'query_params', 'query_ignore', 'continue', 'only_if_not_found',
        'active_from', 'expires_at', 'note', 'group', 'tags', 'origin', 'conditions',
    ];

    /**
     * @param array<mixed> $body
     * @param bool         $withMeta also accept id, created_at and updated_at (restoring deleted rules)
     *
     * @return array{fields: array<string, mixed>, issues: list<ValidationIssue>}
     */
    public static function parse(array $body, bool $withMeta = false): array
    {
        $fields = [];
        $issues = [];
        $allowed = $withMeta ? [...self::FIELDS, 'id', 'created_at', 'updated_at'] : self::FIELDS;

        foreach ($allowed as $name) {
            if (!array_key_exists($name, $body)) {
                continue;
            }
            $value = $body[$name];
            $error = null;
            $clean = self::clean($name, $value, $error);
            if ($error !== null) {
                $issues[] = ValidationIssue::error($error[0], $name, $error[1]);
                continue;
            }
            $fields[$name] = $clean;
        }

        return ['fields' => $fields, 'issues' => $issues];
    }

    /**
     * @param array{0: string, 1: string}|null $error set to [code, message] when the value is unusable
     */
    private static function clean(string $name, mixed $value, ?array &$error): mixed
    {
        if (in_array($name, self::STRINGS, true) || $name === 'id') {
            if ($value === null && $name !== 'id') {
                return '';
            }
            if (!is_string($value) && !(is_int($value) && $name === 'group')) {
                $error = ['invalid_type', sprintf('"%s" must be a string.', $name)];

                return null;
            }

            return (string) $value;
        }
        if (in_array($name, self::BOOLS, true)) {
            $bool = is_bool($value) ? $value : ($value === null ? null : filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE));
            if ($bool === null || $value === null || is_array($value)) {
                $error = ['invalid_type', sprintf('"%s" must be true or false.', $name)];

                return null;
            }

            return $bool;
        }
        if ($name === 'priority') {
            if (!is_int($value) && !(is_string($value) && preg_match('/^-?\d{1,9}$/', trim($value)) === 1)) {
                $error = ['invalid_type', '"priority" must be an integer.'];

                return null;
            }

            return (int) $value;
        }
        if ($name === 'status') {
            $code = is_int($value) ? $value : (is_string($value) && ctype_digit(trim($value)) ? (int) trim($value) : null);
            if ($code === null || StatusCode::tryFrom($code) === null) {
                $error = ['invalid_value', '"status" must be one of ' . implode(', ', array_map(static fn (StatusCode $s): int => $s->value, StatusCode::cases())) . '.'];

                return null;
            }

            return $code;
        }
        if (in_array($name, self::ENUMS, true)) {
            $enum = match ($name) {
                'match_type' => array_map(static fn (MatchType $c): string => $c->value, MatchType::cases()),
                'target_type' => array_map(static fn (TargetType $c): string => $c->value, TargetType::cases()),
                'query_mode' => array_map(static fn (QueryMode $c): string => $c->value, QueryMode::cases()),
                default => array_map(static fn (RuleSource $c): string => $c->value, RuleSource::cases()),
            };
            if (!is_string($value) || !in_array($value, $enum, true)) {
                $error = ['invalid_value', sprintf('"%s" must be one of %s.', $name, implode(', ', $enum))];

                return null;
            }

            return $value;
        }
        if (in_array($name, self::DATES, true) || $name === 'created_at' || $name === 'updated_at') {
            if ($value === null || $value === '') {
                return null;
            }
            if (!is_string($value)) {
                $error = ['invalid_type', sprintf('"%s" must be a date string or null.', $name)];

                return null;
            }
            try {
                return (new DateTimeImmutable(trim($value)))->format(Rule::DATE_FORMAT);
            } catch (Exception) {
                $error = ['invalid_value', sprintf('"%s" is not a valid date.', $name)];

                return null;
            }
        }
        if (in_array($name, self::LISTS, true)) {
            if (is_string($value) || is_array($value)) {
                return $value;
            }
            $error = ['invalid_type', sprintf('"%s" must be a list of strings.', $name)];

            return null;
        }
        if ($name === 'query_params') {
            if ($value === null || is_string($value) || is_array($value)) {
                return $value ?? [];
            }
            $error = ['invalid_type', '"query_params" must be a map.'];

            return null;
        }
        if ($name === 'conditions') {
            return self::conditions($value, $error);
        }

        return $value;
    }

    /**
     * @param array{0: string, 1: string}|null $error
     *
     * @return array<string, mixed>|null
     */
    private static function conditions(mixed $value, ?array &$error): ?array
    {
        if ($value === null) {
            return [];
        }
        if (!is_array($value)) {
            $error = ['invalid_type', '"conditions" must be an object.'];

            return null;
        }
        foreach (['hosts', 'languages', 'schemes'] as $key) {
            if (isset($value[$key]) && !is_array($value[$key]) && !is_string($value[$key])) {
                $error = ['invalid_type', sprintf('"conditions.%s" must be a list of strings.', $key)];

                return null;
            }
        }
        $rules = $value['rules'] ?? [];
        if (!is_array($rules)) {
            $error = ['invalid_type', '"conditions.rules" must be a list.'];

            return null;
        }
        $kinds = array_map(static fn (ConditionKind $c): string => $c->value, ConditionKind::cases());
        $operators = array_map(static fn (ConditionOperator $c): string => $c->value, ConditionOperator::cases());
        foreach ($rules as $i => $rule) {
            if (!is_array($rule)) {
                $error = ['invalid_type', sprintf('"conditions.rules.%s" must be an object.', (string) $i)];

                return null;
            }
            if (isset($rule['kind']) && !in_array($rule['kind'], $kinds, true)) {
                $error = ['invalid_value', sprintf('"conditions.rules.%s.kind" must be one of %s.', (string) $i, implode(', ', $kinds))];

                return null;
            }
            if (isset($rule['operator']) && !in_array($rule['operator'], $operators, true)) {
                $error = ['invalid_value', sprintf('"conditions.rules.%s.operator" must be one of %s.', (string) $i, implode(', ', $operators))];

                return null;
            }
        }

        /** @var array<string, mixed> $value */
        return $value;
    }
}
