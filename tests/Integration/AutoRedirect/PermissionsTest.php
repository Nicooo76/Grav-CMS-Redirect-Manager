<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Integration\AutoRedirect;

use Grav\Plugin\RedirectManager\Tests\Integration\Support\AccountFactory;
use Grav\Plugin\RedirectManager\Tests\Integration\Support\ApiClient;
use PHPUnit\Framework\Attributes\Group;

/** Reads need api.redirects.read, resolving needs api.redirects.manage. */
#[Group('integration')]
#[Group('auto')]
final class PermissionsTest extends AutoRedirectTestCase
{
    /** @var array<string, string> */
    private static array $passwords = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $site = self::$site;
        self::assertNotNull($site);
        self::$passwords = [
            'rmreader' => AccountFactory::create($site, 'rmreader', ['api' => ['access' => true, 'redirects' => ['read' => true]]]),
            'rmbasic' => AccountFactory::create($site, 'rmbasic', ['api' => ['access' => true]]),
        ];
    }

    public function testReadersSeeTheListAndTheBadgeButCannotResolve(): void
    {
        $this->page('03.docs', 'Docs');
        $this->page('03.docs/guide', 'Guide');
        $this->site()->writePluginConfig(['auto_redirect' => ['on_delete' => 'ask']]);
        $this->deletePage('/docs/guide');
        $reader = ApiClient::login($this->site(), 'rmreader', self::$passwords['rmreader']);

        self::assertSame(200, $reader->get('/redirects/pending')->status);
        self::assertSame(1, $reader->get('/redirects/badge')->data()['count']);
        self::assertSame(200, $reader->post('/redirects/badge/seen', [])->status, 'marking as seen is a read action');

        $id = $reader->get('/redirects/pending')->data()[0]['id'];
        $denied = $reader->post('/redirects/pending/' . $id . '/resolve', ['action' => 'gone']);
        self::assertSame(403, $denied->status, $denied->describe());
        self::assertCount(1, $this->api->get('/redirects/pending')->data(), 'still pending');
        self::assertSame([], $this->autoRules());
    }

    public function testUsersWithoutRedirectPermissionsSeeNothing(): void
    {
        $basic = ApiClient::login($this->site(), 'rmbasic', self::$passwords['rmbasic']);

        self::assertSame(403, $basic->get('/redirects/pending')->status);
        self::assertSame(403, $basic->get('/redirects/badge')->status);
        self::assertSame(403, $basic->post('/redirects/badge/seen', [])->status);
        self::assertSame(403, $basic->post('/redirects/pending/x/resolve', ['action' => 'gone'])->status);
    }

    public function testAnonymousRequestsAreRefused(): void
    {
        self::assertSame(401, $this->api->withoutAuth()->get('/redirects/pending')->status);
        self::assertSame(401, $this->api->withoutAuth()->get('/redirects/badge')->status);
    }
}
