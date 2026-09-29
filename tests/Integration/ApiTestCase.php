<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Integration;

use Grav\Plugin\RedirectManager\Tests\Integration\Support\AccountFactory;
use Grav\Plugin\RedirectManager\Tests\Integration\Support\ApiClient;
use Grav\Plugin\RedirectManager\Tests\Integration\Support\ApiResponse;

/**
 * Base class of the REST API tests: a temp Grav site with the API plugin, and clients for three users.
 *
 *  - $this->api      super admin (all permissions)
 *  - $this->reader   only api.access + api.redirects.read
 *  - $this->basic    only api.access
 *
 * The site is reset before every test (which also drops the API's JWT secret), so the tokens are fetched per test
 * ($api in setUp(), $reader and $basic on first use). The JWT secret is pinned per class, see setUp(). Credentials of the super admin come from .grav/credentials.env and are never printed.
 */
abstract class ApiTestCase extends IntegrationTestCase
{
    protected ApiClient $api;
    protected ApiClient $reader;
    protected ApiClient $basic;

    /** @var array<string, string> username => password, created once per class */
    private static array $passwords = [];

    /** JWT signing secret of the test site, constant per class (see setUp()). */
    private static string $jwtSecret = '';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        if (ApiClient::adminCredentials() === null) {
            self::markTestSkipped('No API test user. Run scripts/setup-test-site.sh first.');
        }
        $site = self::$site;
        self::assertNotNull($site);
        self::$jwtSecret = bin2hex(random_bytes(32));
        self::$passwords = [
            'rmreader' => AccountFactory::create($site, 'rmreader', ['api' => ['access' => true, 'redirects' => ['read' => true]]]),
            'rmbasic' => AccountFactory::create($site, 'rmbasic', ['api' => ['access' => true]]),
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        // reset() drops user/config, so the API would mint a new JWT secret (api-private.php) for every test. The
        // server's opcache can keep serving the previous file for up to two seconds (revalidate_freq), which shows
        // up as a random 401 right after a slow test. The same secret in every test makes a stale copy harmless.
        $this->site()->writeFile('user/config/plugins/api-private.php', "<?php\n\nreturn '" . self::$jwtSecret . "';\n");
        [$user, $password] = ApiClient::adminCredentials() ?? ['', ''];
        $this->api = ApiClient::login($this->site(), $user, $password);
        // The limited users log in on first use (__get): most tests never need them and every login costs ~0.2 s.
        unset($this->reader, $this->basic);
    }

    public function __get(string $name): ApiClient
    {
        return match ($name) {
            'reader' => $this->reader = ApiClient::login($this->site(), 'rmreader', self::$passwords['rmreader']),
            'basic' => $this->basic = ApiClient::login($this->site(), 'rmbasic', self::$passwords['rmbasic']),
            default => throw new \LogicException('Unknown property ' . $name),
        };
    }

    /**
     * Creates a rule through the API and returns its data (fails the test when it is refused).
     *
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    protected function createRule(array $fields): array
    {
        $response = $this->api->post('/redirects/rules', $fields);
        self::assertSame(201, $response->status, $response->describe());
        $data = $response->data();
        self::assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
    }

    protected function assertProblem(ApiResponse $response, int $status, string $message = ''): void
    {
        self::assertSame($status, $response->status, $message . ' ' . $response->describe());
        self::assertStringContainsString('application/problem+json', (string) $response->header('content-type'), $message);
    }
}
