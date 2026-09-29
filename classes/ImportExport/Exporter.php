<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\ImportExport;

use Grav\Plugin\RedirectManager\Domain\Rule;
use Grav\Plugin\RedirectManager\ImportExport\Support\Lossy;
use Grav\Plugin\RedirectManager\Util\Clock;
use Grav\Plugin\RedirectManager\Util\SystemClock;
use InvalidArgumentException;

final class Exporter
{
    /** Formats that can carry the enabled flag; for the others disabled rules are skipped. */
    private const KEEPS_DISABLED = [Format::Csv, Format::Json, Format::Yaml, Format::WordpressJson, Format::WordpressCsv];

    /** Formats that keep the {lang} placeholder untouched (own formats). */
    private const KEEPS_LANG = [Format::Csv, Format::Json, Format::Yaml];

    private readonly Clock $clock;

    public function __construct(?Clock $clock = null)
    {
        $this->clock = $clock ?? new SystemClock();
    }

    /**
     * @param list<Rule> $rules
     */
    public function export(array $rules, Format $format, ExportOptions $options = new ExportOptions()): ExportResult
    {
        $adapter = Adapters::exporter($format, $this->clock);
        if ($adapter === null) {
            throw new InvalidArgumentException('The format ' . $format->value . ' cannot be exported.');
        }

        $skipped = [];
        $selected = [];
        foreach ($rules as $rule) {
            if ($options->onlyEnabled && !$rule->enabled) {
                continue;
            }
            if ($options->group !== null && $rule->group !== $options->group) {
                continue;
            }
            if ($options->statuses !== null && !in_array($rule->status->value, $options->statuses, true)) {
                continue;
            }
            if (!$rule->enabled && !in_array($format, self::KEEPS_DISABLED, true)) {
                $skipped[] = new ExportNote($rule->id, 'disabled', 'The format has no enabled flag; disabled rules are not exported.');
                continue;
            }
            if (Lossy::usesLangPlaceholder($rule) && !in_array($format, self::KEEPS_LANG, true)) {
                $skipped[] = new ExportNote($rule->id, 'lang_placeholder', 'The {lang} placeholder is not supported by this format.');
                continue;
            }
            $selected[] = $rule;
        }
        usort($selected, self::compare(...));

        $result = $adapter->export($selected, $options);

        return new ExportResult(
            $result->content,
            $format->filename(),
            $format->mimeType(),
            array_merge($skipped, $result->skipped),
            $result->lossy,
            $result->exported,
        );
    }

    /** Same order the matcher uses: priority, match type, age, id. */
    private static function compare(Rule $a, Rule $b): int
    {
        return ($b->priority <=> $a->priority)
            ?: ($a->matchType->order() <=> $b->matchType->order())
            ?: (($a->createdAt?->getTimestamp() ?? 0) <=> ($b->createdAt?->getTimestamp() ?? 0))
            ?: strcmp($a->id, $b->id);
    }
}
