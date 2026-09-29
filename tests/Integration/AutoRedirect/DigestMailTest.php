<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Integration\AutoRedirect;

use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Yaml\Yaml;

/**
 * The digest job sends through Grav's email plugin. The tests point the plugin's sendmail transport at a script that
 * writes the message to a file, so nothing leaves the machine.
 */
#[Group('integration')]
#[Group('scheduler')]
final class DigestMailTest extends SchedulerTestCase
{
    private string $mailFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        $script = $this->site()->dir . '/tmp/mail-capture.sh';
        $this->mailFile = $this->site()->dir . '/tmp/mail.eml';
        $this->site()->writeFile('tmp/mail-capture.sh', "#!/bin/sh\ncat >> " . escapeshellarg($this->mailFile) . "\necho >> " . escapeshellarg($this->mailFile) . "\n");
        chmod($script, 0755);
    }

    /**
     * @param array<string, mixed> $email overrides for user/config/plugins/email.yaml
     */
    private function configureEmail(array $email = []): void
    {
        $config = array_replace_recursive([
            'enabled' => true,
            'from' => 'reports@example.org',
            'mailer' => ['engine' => 'sendmail', 'sendmail' => ['bin' => $this->site()->dir . '/tmp/mail-capture.sh -oi -t']],
        ], $email);
        $this->site()->writeFile('user/config/plugins/email.yaml', Yaml::dump($config, 6, 2));
    }

    /**
     * @param array<string, mixed> $notifications
     */
    private function configureDigest(array $notifications = []): void
    {
        $this->site()->writePluginConfig(['base_url' => 'https://shop.example', 'notifications' => $notifications + ['email_digest' => 'daily', 'email_to' => 'editor@example.org']]);
    }

    private function mail(): string
    {
        return is_file($this->mailFile) ? (string) file_get_contents($this->mailFile) : '';
    }

    private function seedNumbers(): void
    {
        $this->rules([['id' => 'r1', 'source' => '/old-shop', 'target' => '/typography'], ['id' => 'dead', 'source' => '/gone', 'target' => '/nope']]);
        $this->hits404('/missing-a', 5);
        $this->hits404('/missing-b', 2);
        $this->recordRuleHits('r1', 4);
    }

    public function testDigestIsSentThroughTheEmailPlugin(): void
    {
        $this->configureEmail();
        $this->configureDigest();
        $this->seedNumbers();

        $result = $this->runJob('redirect-manager-digest');

        self::assertStringContainsString('"sent":true', $result['output']);
        $mail = $this->mail();
        self::assertMatchesRegularExpression('/^Subject: Daily redirect report for .*: 7 404 requests/m', $mail);
        self::assertMatchesRegularExpression('/^To: .*editor@example\.org/m', $mail);
        self::assertMatchesRegularExpression('/^From: .*reports@example\.org/m', $mail);
        self::assertStringContainsString('text/plain', $mail);
        self::assertStringContainsString('text/html', $mail);
        self::assertStringContainsString('/missing-a', $mail);
        self::assertStringContainsString('/old-shop', $mail);
        self::assertStringContainsString('https://shop.example/admin/plugin/redirect-manager', $mail);
    }

    public function testWeeklyDigestSubject(): void
    {
        $this->configureEmail();
        $this->configureDigest(['email_digest' => 'weekly']);

        $this->runJob('redirect-manager-digest');

        self::assertMatchesRegularExpression('/^Subject: Weekly redirect report/m', $this->mail());
    }

    public function testDigestIsSkippedWhenTheEmailPluginIsOff(): void
    {
        $this->configureEmail(['mailer' => ['engine' => 'none']]);
        $this->configureDigest();

        $result = $this->runJob('redirect-manager-digest');

        self::assertStringContainsString('email_plugin_unavailable', $result['output']);
        self::assertStringContainsString('the email plugin is not enabled', $this->site()->gravLog());
        self::assertSame('', $this->mail());
    }

    public function testDigestIsSkippedWhenTheEmailPluginIsDisabled(): void
    {
        $this->configureEmail(['enabled' => false]);
        $this->configureDigest();

        $result = $this->runJob('redirect-manager-digest');

        self::assertStringContainsString('email_plugin_unavailable', $result['output']);
        self::assertSame('', $this->mail());
    }

    public function testDigestNeedsARecipient(): void
    {
        $this->configureEmail();
        $this->configureDigest(['email_to' => '']);

        $result = $this->runJob('redirect-manager-digest');

        self::assertStringContainsString('no_recipient', $result['output']);
        self::assertSame('', $this->mail());
    }

    public function testDigestIsGermanForAGermanSite(): void
    {
        $this->site()->writeSystemConfig(['languages' => ['supported' => ['de', 'en'], 'default_lang' => 'de']]);
        $this->configureEmail();
        $this->configureDigest();
        $this->hits404('/missing-a', 3);

        $this->runJob('redirect-manager-digest');

        self::assertStringContainsString('Tagesbericht', $this->decodedSubject());
    }

    private function decodedSubject(): string
    {
        if (preg_match('/^Subject:(.*(?:\n[ \t].*)*)/m', $this->mail(), $m) !== 1) {
            return '';
        }

        return iconv_mime_decode(trim($m[1]), 0, 'UTF-8') ?: '';
    }
}
