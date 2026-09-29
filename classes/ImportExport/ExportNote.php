<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\ImportExport;

/** Something an export could not express: a skipped rule or a dropped attribute of an exported rule. */
final readonly class ExportNote
{
    public function __construct(
        public string $ruleId,
        public string $code,
        public string $reason,
    ) {
    }

    /**
     * @return array{rule_id: string, code: string, reason: string}
     */
    public function toArray(): array
    {
        return ['rule_id' => $this->ruleId, 'code' => $this->code, 'reason' => $this->reason];
    }
}
