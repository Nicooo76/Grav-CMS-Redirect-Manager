<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Grav\Support;

use Psr\Log\AbstractLogger;
use Stringable;

/** PSR logger that keeps every record for assertions. */
final class ArrayLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string}> */
    public array $records = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => (string) $level, 'message' => (string) $message];
    }

    /**
     * @return list<string>
     */
    public function messages(string $level): array
    {
        $out = [];
        foreach ($this->records as $record) {
            if ($record['level'] === $level) {
                $out[] = $record['message'];
            }
        }

        return $out;
    }
}
