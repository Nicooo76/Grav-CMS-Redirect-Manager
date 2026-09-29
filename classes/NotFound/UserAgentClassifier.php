<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\NotFound;

/**
 * Sorts user agent strings into browser, bot, monitoring and unknown.
 *
 * Order matters: monitoring services first (several call themselves "bot"),
 * then bots and tools, then browsers. An empty user agent counts as bot,
 * real browsers always send one.
 *
 * The generic "bot" match skips "cubot" (a phone brand whose browser UA contains it).
 * All patterns are case-insensitive constants, PCRE compiles them once per process.
 */
final class UserAgentClassifier
{
    private const MONITORING = '~UptimeRobot|Pingdom|StatusCake|Site24x7|Better ?Uptime|Uptime-?Kuma|Hetrix|Checkly|Datadog'
        . '|NewRelicPinger|Freshping|GTmetrix|Lighthouse|PageSpeed|Page Speed|Zabbix|Nagios|check_http|Monitis|Uptimia'
        . '|Dotcom-Monitor|Dynatrace|Catchpoint|WebPageTest|Pulsetic|Cronitor|Healthchecks|Blackbox Exporter|Sematext~i';

    private const BOT = '~(?<!cu)bot|crawl|spider|slurp|fetch|scan|headless|Googlebot|Applebot|YandexBot|Baiduspider'
        . '|DuckDuckBot|AhrefsBot|SemrushBot|MJ12bot|DotBot|PetalBot|GPTBot|ClaudeBot|anthropic-ai|CCBot|PerplexityBot'
        . '|Bytespider|facebookexternalhit|Twitterbot|LinkedInBot|Pinterest|Embedly|Meta-ExternalAgent|GoogleOther'
        . '|Google-InspectionTool|Mediapartners-Google|Feedly|Feedbin|ia_archiver|Sogou|Exabot|Screaming Frog'
        . '|curl|wget|python-requests|python-urllib|aiohttp|httpx|axios|Go-http-client|okhttp|Java/|libwww|Apache-HttpClient'
        . '|GuzzleHttp|HTTPie|PostmanRuntime|Mechanize|Jakarta|Scrapy|zgrab|masscan|Nuclei|sqlmap|nikto|WPScan~i';

    private const BROWSER_START = '~^(?:Mozilla|Opera)/\d~i';

    private const BROWSER_ENGINE = '~AppleWebKit|Gecko|Trident|Presto|Chrome|Safari|Firefox|MSIE|Edge|Edg/|OPR/|Opera~i';

    public function classify(string $userAgent): UserAgentClass
    {
        $ua = trim($userAgent);
        if ($ua === '') {
            return UserAgentClass::Bot;
        }
        if (preg_match(self::MONITORING, $ua) === 1) {
            return UserAgentClass::Monitoring;
        }
        if (preg_match(self::BOT, $ua) === 1) {
            return UserAgentClass::Bot;
        }
        if (preg_match(self::BROWSER_START, $ua) === 1 && preg_match(self::BROWSER_ENGINE, $ua) === 1) {
            return UserAgentClass::Browser;
        }

        return UserAgentClass::Unknown;
    }
}
