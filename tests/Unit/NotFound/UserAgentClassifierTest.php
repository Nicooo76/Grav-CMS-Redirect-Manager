<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\NotFound;

use Grav\Plugin\RedirectManager\NotFound\UserAgentClass;
use Grav\Plugin\RedirectManager\NotFound\UserAgentClassifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(UserAgentClassifier::class)]
#[CoversClass(UserAgentClass::class)]
#[Group('notfound')]
final class UserAgentClassifierTest extends TestCase
{
    /** @return iterable<string, array{string, UserAgentClass}> */
    public static function userAgents(): iterable
    {
        $browser = UserAgentClass::Browser;
        $bot = UserAgentClass::Bot;
        $monitoring = UserAgentClass::Monitoring;
        $unknown = UserAgentClass::Unknown;

        $table = [
            // browsers
            'Chrome Windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36', $browser],
            'Firefox Windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:127.0) Gecko/20100101 Firefox/127.0', $browser],
            'Firefox Linux' => ['Mozilla/5.0 (X11; Linux x86_64; rv:109.0) Gecko/20100101 Firefox/115.0', $browser],
            'Safari macOS' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15', $browser],
            'Safari iPhone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1', $browser],
            'Chrome iOS' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/126.0.6478.153 Mobile/15E148 Safari/604.1', $browser],
            'Edge' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36 Edg/126.0.0.0', $browser],
            'Chrome Android' => ['Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Mobile Safari/537.36', $browser],
            'Samsung Internet' => ['Mozilla/5.0 (Linux; Android 13; SAMSUNG SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/24.0 Chrome/117.0.0.0 Mobile Safari/537.36', $browser],
            'Opera' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36 OPR/111.0.0.0', $browser],
            'Old Opera' => ['Opera/9.80 (Windows NT 6.1; U; en) Presto/2.10.289 Version/12.02', $browser],
            'IE 11' => ['Mozilla/5.0 (Windows NT 10.0; WOW64; Trident/7.0; rv:11.0) like Gecko', $browser],
            'IE 9' => ['Mozilla/5.0 (compatible; MSIE 9.0; Windows NT 6.1; Trident/5.0)', $browser],
            'Cubot phone (contains "bot")' => ['Mozilla/5.0 (Linux; Android 9; CUBOT X19) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/89.0 Mobile Safari/537.36', $browser],
            // search engine and SEO bots
            'Googlebot' => ['Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', $bot],
            'Googlebot smartphone' => ['Mozilla/5.0 (Linux; Android 6.0.1; Nexus 5X Build/MMB29P) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Mobile Safari/537.36 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', $bot],
            'bingbot' => ['Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)', $bot],
            'Applebot' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_5) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/13.1.1 Safari/605.1.15 (Applebot/0.1; +http://www.apple.com/go/applebot)', $bot],
            'YandexBot' => ['Mozilla/5.0 (compatible; YandexBot/3.0; +http://yandex.com/bots)', $bot],
            'Baiduspider' => ['Mozilla/5.0 (compatible; Baiduspider/2.0; +http://www.baidu.com/search/spider.html)', $bot],
            'DuckDuckBot' => ['DuckDuckBot/1.1; (+http://duckduckgo.com/duckduckbot.html)', $bot],
            'Yahoo Slurp' => ['Mozilla/5.0 (compatible; Yahoo! Slurp; http://help.yahoo.com/help/us/ysearch/slurp)', $bot],
            'AhrefsBot' => ['Mozilla/5.0 (compatible; AhrefsBot/7.0; +http://ahrefs.com/robot/)', $bot],
            'SemrushBot' => ['Mozilla/5.0 (compatible; SemrushBot/7~bl; +http://www.semrush.com/bot.html)', $bot],
            'MJ12bot' => ['Mozilla/5.0 (compatible; MJ12bot/v1.4.8; http://mj12bot.com/)', $bot],
            'DotBot' => ['Mozilla/5.0 (compatible; DotBot/1.2; +https://opensiteexplorer.org/dotbot; help@moz.com)', $bot],
            'PetalBot' => ['Mozilla/5.0 (Linux; Android 7.0;) AppleWebKit/537.36 (KHTML, like Gecko) Mobile Safari/537.36 (compatible; PetalBot;+https://webmaster.petalsearch.com/site/petalbot)', $bot],
            // AI crawlers
            'GPTBot' => ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; GPTBot/1.1; +https://openai.com/gptbot', $bot],
            'ClaudeBot' => ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; ClaudeBot/1.0; +claudebot@anthropic.com)', $bot],
            'anthropic-ai' => ['anthropic-ai', $bot],
            'CCBot' => ['CCBot/2.0 (https://commoncrawl.org/faq/)', $bot],
            'PerplexityBot' => ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; PerplexityBot/1.0; +https://perplexity.ai/perplexitybot)', $bot],
            'Bytespider' => ['Mozilla/5.0 (Linux; Android 5.0) AppleWebKit/537.36 (KHTML, like Gecko) Mobile Safari/537.36 (compatible; Bytespider; spider-feedback@bytedance.com)', $bot],
            // social link previews
            'facebookexternalhit' => ['facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)', $bot],
            'Twitterbot' => ['Twitterbot/1.0', $bot],
            'LinkedInBot' => ['LinkedInBot/1.0 (compatible; Mozilla/5.0; Apache-HttpClient +http://www.linkedin.com)', $bot],
            'Slackbot' => ['Slackbot-LinkExpanding 1.0 (+https://api.slack.com/robots)', $bot],
            // tools and libraries
            'curl' => ['curl/8.7.1', $bot],
            'Wget' => ['Wget/1.21.4', $bot],
            'python-requests' => ['python-requests/2.32.3', $bot],
            'Go http client' => ['Go-http-client/1.1', $bot],
            'okhttp' => ['okhttp/4.12.0', $bot],
            'Java' => ['Java/17.0.2', $bot],
            'libwww-perl' => ['libwww-perl/6.72', $bot],
            'Scrapy' => ['Scrapy/2.11.2 (+https://scrapy.org)', $bot],
            'Guzzle' => ['GuzzleHttp/7.8.1 curl/8.5.0 PHP/8.3.8', $bot],
            // scanners
            'zgrab' => ['Mozilla/5.0 zgrab/0.x', $bot],
            'masscan' => ['masscan/1.3 (https://github.com/robertdavidgraham/masscan)', $bot],
            'Nuclei' => ['Nuclei - Open-source project (github.com/projectdiscovery/nuclei)', $bot],
            'sqlmap' => ['sqlmap/1.8.5#stable (https://sqlmap.org)', $bot],
            'Nikto' => ['Mozilla/5.00 (Nikto/2.5.0) (Evasions:None) (Test:Port Check)', $bot],
            'WPScan' => ['WPScan v3.8.25 (https://wpscan.com/wordpress-security-scanner)', $bot],
            'HeadlessChrome' => ['Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/126.0.0.0 Safari/537.36', $bot],
            'empty' => ['', $bot],
            'blank' => ["  \t ", $bot],
            // monitoring
            'UptimeRobot' => ['Mozilla/5.0+(compatible; UptimeRobot/2.0; http://www.uptimerobot.com/)', $monitoring],
            'Pingdom' => ['Pingdom.com_bot_version_1.4_(http://www.pingdom.com/)', $monitoring],
            'StatusCake' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:103.0) Gecko/20100101 Firefox/103.0 StatusCake', $monitoring],
            'Site24x7' => ['Site24x7', $monitoring],
            'Better Uptime' => ['Better Uptime Bot Mozilla/5.0 (compatible; Better Uptime Bot; +https://betteruptime.com)', $monitoring],
            'Uptime Kuma' => ['Uptime-Kuma/1.23.11', $monitoring],
            'HetrixTools' => ['Mozilla/5.0 (compatible; HetrixTools Uptime Monitoring Bot. https://hetrixtools.com/uptime-monitoring-bot.html)', $monitoring],
            'Checkly' => ['Checkly, https://www.checklyhq.com', $monitoring],
            'Datadog synthetics' => ['Datadog/Synthetics', $monitoring],
            'New Relic Pinger' => ['NewRelicPinger/1.0 (123456)', $monitoring],
            'Freshping' => ['FreshpingBot/1.0 (+https://www.freshworks.com/website-monitoring/)', $monitoring],
            'GTmetrix' => ['Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36 GTmetrix', $monitoring],
            'Lighthouse' => ['Mozilla/5.0 (Linux; Android 11; moto g power (2022)) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/109.0.5414.83 Mobile Safari/537.36 Chrome-Lighthouse', $monitoring],
            'PageSpeed Insights' => ['Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/103.0.5060.134 Safari/537.36 Google Page Speed Insights', $monitoring],
            // unknown
            'WhatsApp' => ['WhatsApp/2.23.20.0', $unknown],
            'Instagram app' => ['Instagram 300.0.0.29.110 Android (33/13; 420dpi; 1080x2138; Google/google; Pixel 7)', $unknown],
            'Dalvik' => ['Dalvik/2.1.0 (Linux; U; Android 13; Pixel 7 Build/TQ3A.230901.001)', $unknown],
            'CFNetwork' => ['MyApp/1.0 CFNetwork/1494.0.7 Darwin/23.4.0', $unknown],
            'Mozilla without engine' => ['Mozilla/5.0', $unknown],
            'custom string' => ['MyCustomApp/1.0', $unknown],
        ];
        foreach ($table as $name => [$ua, $expected]) {
            yield $name => [$ua, $expected];
        }
    }

    #[DataProvider('userAgents')]
    public function testClassifiesRealWorldUserAgents(string $ua, UserAgentClass $expected): void
    {
        self::assertSame($expected, (new UserAgentClassifier())->classify($ua));
    }

    public function testClassificationIsCaseInsensitive(): void
    {
        $classifier = new UserAgentClassifier();

        self::assertSame(UserAgentClass::Bot, $classifier->classify('GOOGLEBOT/2.1'));
        self::assertSame(UserAgentClass::Monitoring, $classifier->classify('uptimerobot/2.0'));
        self::assertSame(UserAgentClass::Bot, $classifier->classify('CURL/8'));
    }

    public function testEnumBackingValuesAreStable(): void
    {
        self::assertSame(['browser', 'bot', 'monitoring', 'unknown'], array_map(static fn (UserAgentClass $c): string => $c->value, UserAgentClass::cases()));
    }
}
