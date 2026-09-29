<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Domain;

/**
 * A header or cookie condition, e.g. "User-Agent contains Googlebot" or "Referer regex ^https://partner\.com".
 */
final readonly class Condition
{
    public function __construct(
        public ConditionKind $kind,
        public string $name,
        public ConditionOperator $operator,
        public string $value = '',
        public bool $negate = false,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            ConditionKind::tryFrom(self::str($data['kind'] ?? 'header')) ?? ConditionKind::Header,
            trim(self::str($data['name'] ?? '')),
            ConditionOperator::tryFrom(self::str($data['operator'] ?? 'exists')) ?? ConditionOperator::Exists,
            self::str($data['value'] ?? ''),
            (bool) ($data['negate'] ?? false),
        );
    }

    /**
     * @return array{kind: string, name: string, operator: string, value: string, negate: bool}
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'name' => $this->name,
            'operator' => $this->operator->value,
            'value' => $this->value,
            'negate' => $this->negate,
        ];
    }

    private static function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
