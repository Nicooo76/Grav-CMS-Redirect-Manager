<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\ImportExport;

use Grav\Plugin\RedirectManager\Domain\Rule;

/** The rule set every roundtrip test starts from: one rule per feature, unique sources. */
final class FixtureRules
{
    /**
     * @return array<string, Rule> keyed by a readable name; ids are r-<name>
     */
    public static function all(): array
    {
        $defs = [
            'exact_301' => ['source' => '/old-page', 'target' => '/new-page'],
            'exact_302' => ['source' => '/temp', 'target' => '/elsewhere', 'status' => 302],
            'exact_307' => ['source' => '/tmp307', 'target' => '/x307', 'status' => 307],
            'exact_308' => ['source' => '/perm308', 'target' => '/x308', 'status' => 308],
            'gone_410' => ['source' => '/gone-page', 'status' => 410],
            'legal_451' => ['source' => '/legal', 'status' => 451],
            'alias_200' => ['source' => '/alias', 'target' => '/actual-page', 'status' => 200],
            'cs_noslash' => ['source' => '/Case/Sensitive', 'target' => '/case-target', 'case_sensitive' => true, 'ignore_trailing_slash' => false],
            'cs_slash' => ['source' => '/case-slash', 'target' => '/t', 'case_sensitive' => true],
            'ci_noslash' => ['source' => '/nocase-noslash', 'target' => '/t2', 'ignore_trailing_slash' => false],
            'gone_cs' => ['source' => '/gone-cs', 'status' => 410, 'case_sensitive' => true, 'ignore_trailing_slash' => false],
            'wild_blog' => ['source' => '/blog/*', 'target' => '/news/$1', 'match_type' => 'wildcard'],
            'wild_cs' => ['source' => '/docs/v1/*', 'target' => '/docs/$1', 'match_type' => 'wildcard', 'case_sensitive' => true],
            'wild_external' => ['source' => '/media/*', 'target' => 'https://cdn.example.org/media/$1', 'match_type' => 'wildcard', 'target_type' => 'url'],
            'regex_number' => ['source' => '^/product/(\d+)$', 'target' => '/shop/$1', 'match_type' => 'regex'],
            'regex_named' => ['source' => '^/(?<year>\d{4})/(?<slug>[^/]+)$', 'target' => '/archive/{year}/{slug}', 'match_type' => 'regex', 'case_sensitive' => true],
            'regex_files' => ['source' => '^/files/(.+)$', 'target' => '/download/$1', 'match_type' => 'regex', 'status' => 302],
            'grouped' => ['source' => '/grouped', 'target' => '/g-target', 'group' => 'Kampagne', 'note' => 'Herbst, mit "Komma"'],
            'disabled' => ['source' => '/disabled', 'target' => '/d-target', 'enabled' => false],
            'priority' => ['source' => '/prio', 'target' => '/p-target', 'priority' => 5],
            'query_exact' => ['source' => '/search?q=1', 'target' => '/found', 'query_mode' => 'exact'],
            'query_params' => ['source' => '/store', 'target' => '/shop', 'query_mode' => 'params', 'query_params' => ['id' => null, 'cat' => 'x']],
            'query_pass' => ['source' => '/pass-query', 'target' => '/target', 'query_mode' => 'pass'],
            'host' => ['source' => '/hosted', 'target' => '/h-target', 'conditions' => ['hosts' => ['example.com', 'www.example.com']]],
            'host_wild' => ['source' => '/wildhost', 'target' => '/wh-target', 'conditions' => ['hosts' => ['*.example.org']]],
            'scheme' => ['source' => '/secure', 'target' => '/s-target', 'conditions' => ['schemes' => ['https']]],
            'header_ua' => ['source' => '/bot', 'target' => '/bot-target', 'conditions' => ['rules' => [['kind' => 'header', 'name' => 'User-Agent', 'operator' => 'contains', 'value' => 'Googlebot']]]],
            'header_ref' => ['source' => '/from-partner', 'target' => '/partner', 'conditions' => ['rules' => [['kind' => 'header', 'name' => 'Referer', 'operator' => 'starts_with', 'value' => 'https://partner.example']]]],
            'cookie' => ['source' => '/cookie', 'target' => '/c-target', 'conditions' => ['rules' => [['kind' => 'cookie', 'name' => 'beta', 'operator' => 'equals', 'value' => '1']]]],
            'language' => ['source' => '/de-only', 'target' => '/de-target', 'conditions' => ['languages' => ['de']]],
            'expiry' => ['source' => '/campaign', 'target' => '/c', 'expires_at' => '2030-01-01T00:00:00+00:00', 'active_from' => '2029-01-01T00:00:00+00:00'],
            'soft' => ['source' => '/soft', 'target' => '/soft-target', 'only_if_not_found' => true],
            'chain' => ['source' => '/chain', 'target' => '/chain-2', 'continue' => true],
            'tags' => ['source' => '/tagged', 'target' => '/tag-target', 'tags' => ['seo', 'migration']],
            'page_target' => ['source' => '/page-source', 'target' => '/pages/x', 'target_type' => 'page'],
            'unicode' => ['source' => '/über-uns', 'target' => '/about'],
            'space' => ['source' => '/my page', 'target' => '/my-page'],
            'lang_target' => ['source' => '/lang-rule', 'target' => '/{lang}/home'],
        ];

        $out = [];
        $n = 0;
        foreach ($defs as $name => $def) {
            $out[$name] = Rule::fromArray($def + [
                'id' => 'r' . str_pad((string) ++$n, 3, '0', STR_PAD_LEFT) . '-' . $name,
                'created_at' => '2026-01-01T00:00:00+00:00',
                'updated_at' => '2026-01-01T00:00:00+00:00',
            ]);
        }

        return $out;
    }

    /**
     * @param list<string> $names
     * @return list<Rule>
     */
    public static function only(array $names): array
    {
        $all = self::all();

        return array_values(array_map(static fn (string $n): Rule => $all[$n], $names));
    }
}
