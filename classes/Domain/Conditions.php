<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Domain;

/**
 * Request conditions a rule requires. Empty lists mean "any".
 *
 * Hosts may use a leading wildcard (*.example.com). Languages are Grav language codes (en, de).
 * Schemes are http or https. All listed condition groups must hold (AND); values inside a group are OR.
 */
final readonly class Conditions
{
    /**
     * @param list<string>    $hosts
     * @param list<string>    $languages
     * @param list<string>    $schemes
     * @param list<Condition> $rules
     */
    public function __construct(
        public array $hosts = [],
        public array $languages = [],
        public array $schemes = [],
        public array $rules = [],
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->hosts === [] && $this->languages === [] && $this->schemes === [] && $this->rules === [];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        if ($data === []) {
            return new self();
        }
        $rules = [];
        foreach (is_array($data['rules'] ?? null) ? $data['rules'] : [] as $rule) {
            if (is_array($rule)) {
                /** @var array<string, mixed> $rule */
                $rules[] = Condition::fromArray($rule);
            }
        }

        return new self(
            self::lowerList($data['hosts'] ?? []),
            self::lowerList($data['languages'] ?? []),
            self::lowerList($data['schemes'] ?? []),
            $rules,
        );
    }

    /**
     * @return array{hosts: list<string>, languages: list<string>, schemes: list<string>, rules: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'hosts' => $this->hosts,
            'languages' => $this->languages,
            'schemes' => $this->schemes,
            'rules' => array_map(static fn (Condition $c): array => $c->toArray(), $this->rules),
        ];
    }

    /**
     * @return list<string>
     */
    private static function lowerList(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[\s,]+/', $value) ?: [];
        }
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (is_scalar($item)) {
                $item = strtolower(trim((string) $item));
                if ($item !== '' && !in_array($item, $out, true)) {
                    $out[] = $item;
                }
            }
        }

        return $out;
    }
}
