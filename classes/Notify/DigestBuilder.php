<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Notify;

/**
 * Builds the daily/weekly report mail (subject, plain text, HTML) in English or German.
 *
 * No Grav dependency: the texts live in a small dictionary in this class. Unknown locales fall
 * back to English; "de_DE", "de-AT" and similar map to German. Everything that comes from the
 * data (paths, rule sources, site name) is escaped in the HTML, and links are only emitted for
 * http(s) URLs.
 */
final class DigestBuilder
{
    private const TOP_LIMIT = 10;
    private const TEXT_WIDTH = 76;

    /** @var array<string, array<string, string>> */
    private const TEXTS = [
        'en' => [
            'subject_daily' => 'Daily redirect report for %1$s: %2$s 404 requests',
            'subject_weekly' => 'Weekly redirect report for %1$s: %2$s 404 requests',
            'title' => 'Redirect report for %s',
            'daily' => 'Daily report',
            'weekly' => 'Weekly report',
            'to' => 'to',
            'not_found_total' => '404 requests',
            'new_paths' => 'New 404 paths',
            'redirect_hits' => 'Redirect hits',
            'open_suggestions' => 'Open suggestions',
            'dead_count' => 'Dead targets',
            'top_404' => 'Most requested missing paths',
            'top_rules' => 'Most used rules',
            'dead' => 'Redirect targets that do not answer',
            'none' => 'None.',
            'hit' => 'hit',
            'hits' => 'hits',
            'status' => 'status',
            'error' => 'error',
            'open_admin' => 'Open Redirect Manager',
            'footer' => 'Sent by Redirect Manager for %s.',
        ],
        'de' => [
            'subject_daily' => 'Tagesbericht Weiterleitungen für %1$s: %2$s 404-Aufrufe',
            'subject_weekly' => 'Wochenbericht Weiterleitungen für %1$s: %2$s 404-Aufrufe',
            'title' => 'Weiterleitungsbericht für %s',
            'daily' => 'Tagesbericht',
            'weekly' => 'Wochenbericht',
            'to' => 'bis',
            'not_found_total' => '404-Aufrufe',
            'new_paths' => 'Neue 404-Pfade',
            'redirect_hits' => 'Weiterleitungen ausgeführt',
            'open_suggestions' => 'Offene Vorschläge',
            'dead_count' => 'Tote Ziele',
            'top_404' => 'Am häufigsten aufgerufene fehlende Pfade',
            'top_rules' => 'Am häufigsten genutzte Regeln',
            'dead' => 'Weiterleitungsziele, die nicht antworten',
            'none' => 'Keine.',
            'hit' => 'Aufruf',
            'hits' => 'Aufrufe',
            'status' => 'Status',
            'error' => 'Fehler',
            'open_admin' => 'Redirect Manager öffnen',
            'footer' => 'Gesendet vom Redirect Manager für %s.',
        ],
    ];

    public function build(DigestData $d, string $locale = 'en'): Digest
    {
        $lang = strtolower(substr($locale, 0, 2));
        $t = self::TEXTS[$lang] ?? self::TEXTS['en'];
        $de = $lang === 'de';

        $site = $d->siteName !== '' ? $d->siteName : $d->siteUrl;
        $subjectKey = $d->period === DigestPeriod::Daily ? 'subject_daily' : 'subject_weekly';
        $subject = sprintf($t[$subjectKey], $this->oneLine($site), $this->number($d->notFoundTotal, $de));

        $range = $this->range($d, $t['to']);
        $periodLine = $t[$d->period === DigestPeriod::Daily ? 'daily' : 'weekly'] . ', ' . $range;
        $summary = [
            [$t['not_found_total'], $this->number($d->notFoundTotal, $de)],
            [$t['new_paths'], $this->number($d->newPaths, $de)],
            [$t['redirect_hits'], $this->number($d->redirectHits, $de)],
            [$t['open_suggestions'], $this->number($d->openSuggestions, $de)],
            [$t['dead_count'], $this->number(count($d->deadTargets), $de)],
        ];

        $topPaths = [];
        foreach (array_slice($d->notFoundTopPaths, 0, self::TOP_LIMIT, true) as $path => $hits) {
            $topPaths[] = [$this->oneLine((string) $path), $this->number($hits, $de) . ' ' . $t[$hits === 1 ? 'hit' : 'hits']];
        }
        $topRules = [];
        foreach (array_slice($d->topRules, 0, self::TOP_LIMIT) as $rule) {
            $topRules[] = [$this->oneLine($rule['source']), $this->number($rule['hits'], $de) . ' ' . $t[$rule['hits'] === 1 ? 'hit' : 'hits']];
        }
        $dead = [];
        foreach ($d->deadTargets as $row) {
            $info = [];
            if (isset($row['status'])) {
                $info[] = $t['status'] . ' ' . $row['status'];
            }
            if (isset($row['error']) && $row['error'] !== '') {
                $info[] = $t['error'] . ' ' . $this->oneLine($row['error']);
            }
            $dead[] = [
                $this->oneLine($row['source']) . ' -> ' . $this->oneLine($row['target']),
                $info === [] ? '' : implode(', ', $info),
            ];
        }

        $text = $this->renderText($t, $site, $periodLine, $summary, $topPaths, $topRules, $dead, $d);
        $html = $this->renderHtml($t, $lang, $subject, $site, $periodLine, $summary, $topPaths, $topRules, $dead, $d);

        return new Digest($this->oneLine($subject), $text, $html);
    }

    /**
     * @param array<string, string>            $t
     * @param list<array{0: string, 1: string}> $summary
     * @param list<array{0: string, 1: string}> $topPaths
     * @param list<array{0: string, 1: string}> $topRules
     * @param list<array{0: string, 1: string}> $dead
     */
    private function renderText(array $t, string $site, string $periodLine, array $summary, array $topPaths, array $topRules, array $dead, DigestData $d): string
    {
        $labelWidth = 0;
        foreach ($summary as [$label]) {
            $labelWidth = max($labelWidth, mb_strlen($label));
        }

        $out = [];
        $out[] = $this->wrap(sprintf($t['title'], $this->oneLine($site)));
        $out[] = $this->wrap($periodLine);
        $out[] = '';
        foreach ($summary as [$label, $value]) {
            $out[] = $label . ':' . str_repeat(' ', $labelWidth - mb_strlen($label) + 2) . $value;
        }

        foreach ([[$t['top_404'], $topPaths], [$t['top_rules'], $topRules], [$t['dead'], $dead]] as [$heading, $rows]) {
            $out[] = '';
            $out[] = $this->wrap($heading);
            if ($rows === []) {
                $out[] = '  ' . $t['none'];
                continue;
            }
            foreach ($rows as $i => [$main, $extra]) {
                $prefix = ($heading === $t['dead']) ? '- ' : (($i + 1) . '. ');
                $line = $prefix . $main . ($extra !== '' ? ' (' . $extra . ')' : '');
                $out[] = $this->wrap($line, '  ', str_repeat(' ', mb_strlen($prefix) + 2));
            }
        }

        if ($this->isHttpUrl($d->adminUrl)) {
            $out[] = '';
            $out[] = $t['open_admin'] . ':';
            $out[] = $d->adminUrl;
        }
        $out[] = '';
        $out[] = '--';
        $out[] = $this->wrap(sprintf($t['footer'], $this->oneLine($site)));

        return implode("\n", $out) . "\n";
    }

    /**
     * @param array<string, string>            $t
     * @param list<array{0: string, 1: string}> $summary
     * @param list<array{0: string, 1: string}> $topPaths
     * @param list<array{0: string, 1: string}> $topRules
     * @param list<array{0: string, 1: string}> $dead
     */
    private function renderHtml(array $t, string $lang, string $subject, string $site, string $periodLine, array $summary, array $topPaths, array $topRules, array $dead, DigestData $d): string
    {
        $h = fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $html = '<!DOCTYPE html>' . "\n"
            . '<html lang="' . $h(isset(self::TEXTS[$lang]) ? $lang : 'en') . '">' . "\n"
            . '<head><meta charset="utf-8"><title>' . $h($this->oneLine($subject)) . '</title></head>' . "\n"
            . '<body style="margin:0;padding:24px;background:#f4f4f4;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.5;color:#222222;">' . "\n"
            . '<div style="max-width:600px;margin:0 auto;padding:24px;background:#ffffff;border:1px solid #dddddd;">' . "\n";

        $title = sprintf($t['title'], $this->oneLine($site));
        if ($this->isHttpUrl($d->siteUrl)) {
            $title = '<a href="' . $h($d->siteUrl) . '" style="color:#222222;text-decoration:none;">' . $h($title) . '</a>';
        } else {
            $title = $h($title);
        }
        $html .= '<h1 style="margin:0 0 4px 0;font-size:20px;line-height:1.3;">' . $title . '</h1>' . "\n"
            . '<p style="margin:0 0 20px 0;color:#555555;">' . $h($periodLine) . '</p>' . "\n"
            . '<table role="presentation" cellpadding="0" cellspacing="0" style="border-collapse:collapse;width:100%;margin:0 0 24px 0;">' . "\n";
        foreach ($summary as [$label, $value]) {
            $html .= '<tr><td style="padding:6px 0;border-bottom:1px solid #eeeeee;">' . $h($label) . '</td>'
                . '<td style="padding:6px 0;border-bottom:1px solid #eeeeee;text-align:right;font-weight:bold;">' . $h($value) . '</td></tr>' . "\n";
        }
        $html .= '</table>' . "\n";

        foreach ([[$t['top_404'], $topPaths, 'ol'], [$t['top_rules'], $topRules, 'ol'], [$t['dead'], $dead, 'ul']] as [$heading, $rows, $tag]) {
            $html .= '<h2 style="margin:0 0 8px 0;font-size:16px;">' . $h($heading) . '</h2>' . "\n";
            if ($rows === []) {
                $html .= '<p style="margin:0 0 24px 0;color:#555555;">' . $h($t['none']) . '</p>' . "\n";
                continue;
            }
            $html .= '<' . $tag . ' style="margin:0 0 24px 0;padding-left:24px;">' . "\n";
            foreach ($rows as [$main, $extra]) {
                $html .= '<li style="margin:0 0 4px 0;word-break:break-all;">' . $h($main)
                    . ($extra !== '' ? ' <span style="color:#555555;">(' . $h($extra) . ')</span>' : '') . '</li>' . "\n";
            }
            $html .= '</' . $tag . '>' . "\n";
        }

        if ($this->isHttpUrl($d->adminUrl)) {
            $html .= '<p style="margin:0 0 24px 0;"><a href="' . $h($d->adminUrl) . '" style="color:#0b5cad;">' . $h($t['open_admin']) . '</a></p>' . "\n";
        }
        $html .= '<p style="margin:0;padding-top:12px;border-top:1px solid #eeeeee;font-size:13px;color:#777777;">'
            . $h(sprintf($t['footer'], $this->oneLine($site))) . '</p>' . "\n"
            . '</div>' . "\n</body>\n</html>\n";

        return $html;
    }

    private function range(DigestData $d, string $to): string
    {
        $from = $d->from->format('Y-m-d');
        $end = $d->to->format('Y-m-d');

        return $from === $end ? $from : $from . ' ' . $to . ' ' . $end;
    }

    private function number(int $n, bool $de): string
    {
        return $de ? number_format($n, 0, ',', '.') : number_format($n, 0, '.', ',');
    }

    /** Collapses control characters, so untrusted values cannot inject lines into subject or text. */
    private function oneLine(string $value): string
    {
        return trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value));
    }

    private function isHttpUrl(string $url): bool
    {
        return preg_match('#^https?://[^\s<>"]+$#i', $url) === 1;
    }

    /** Wraps at TEXT_WIDTH characters (multibyte safe); words longer than a line are cut. */
    private function wrap(string $text, string $firstIndent = '', string $nextIndent = ''): string
    {
        $lines = [];
        $line = $firstIndent;
        $empty = true;
        foreach (preg_split('/\s+/u', trim($text)) ?: [] as $word) {
            while (mb_strlen($line) + ($empty ? 0 : 1) + mb_strlen($word) > self::TEXT_WIDTH) {
                if (!$empty && mb_strlen($nextIndent) + mb_strlen($word) <= self::TEXT_WIDTH) {
                    // The word fits on a line of its own.
                    $lines[] = $line;
                    $line = $nextIndent;
                    $empty = true;
                    continue;
                }
                $room = self::TEXT_WIDTH - mb_strlen($line) - ($empty ? 0 : 1);
                if ($room < 1) {
                    if (!$empty) {
                        $lines[] = $line;
                        $line = $nextIndent;
                        $empty = true;
                        continue;
                    }
                    $room = 1;
                }
                $lines[] = $line . ($empty ? '' : ' ') . mb_substr($word, 0, $room);
                $word = mb_substr($word, $room);
                $line = $nextIndent;
                $empty = true;
            }
            if ($word === '') {
                continue;
            }
            $line .= ($empty ? '' : ' ') . $word;
            $empty = false;
        }
        $lines[] = $line;

        return implode("\n", $lines);
    }
}
