<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\ImportExport;

/**
 * A problem found while importing: a stable machine code, an English fallback message and
 * parameters for translation (translation key: PLUGIN_REDIRECT_MANAGER.IMPORT.ISSUE.<CODE>).
 */
final readonly class ImportIssue
{
    /**
     * @param array<string, scalar|null> $params
     */
    public function __construct(
        public string $code,
        public string $message,
        public array $params = [],
    ) {
    }

    /**
     * @return array{code: string, message: string, params: array<string, scalar|null>}
     */
    public function toArray(): array
    {
        return ['code' => $this->code, 'message' => $this->message, 'params' => $this->params];
    }
}
