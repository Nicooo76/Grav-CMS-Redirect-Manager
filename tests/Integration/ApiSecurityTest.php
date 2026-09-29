<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Integration;

use Grav\Plugin\RedirectManager\Tests\Integration\Support\AccountFactory;
use Grav\Plugin\RedirectManager\Tests\Integration\Support\ApiClient;
use Grav\Plugin\RedirectManager\Tests\Integration\Support\ApiResponse;
use Grav\Plugin\RedirectManager\Tests\Integration\Support\SessionLogin;
use PHPUnit\Framework\Attributes\Group;

/**
 * REST API, access control: authentication on every route, read and manage permissions, and the same-origin
 * rule for writes that are signed in by the session cookie alone.
 */
#[Group('integration')]
final class ApiSecurityTest extends ApiTestCase
{
    /** @var array<string, string> username => password */
    private static array $extraPasswords = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $site = self::$site;
        self::assertNotNull($site);
        self::$extraPasswords = [
            // may write, may not read: the two permissions are independent
            'rmmanager' => AccountFactory::create($site, 'rmmanager', ['api' => ['access' => true, 'redirects' => ['manage' => true]]]),
            // may log in to the front end (session cookie) and use the whole redirect API
            'rmsession' => AccountFactory::create($site, 'rmsession', ['site' => ['login' => true], 'api' => ['access' => true, 'redirects' => ['read' => true, 'manage' => true]]]),
            // the same with the read permission only
            'rmsessionreader' => AccountFactory::create($site, 'rmsessionreader', ['site' => ['login' => true], 'api' => ['access' => true, 'redirects' => ['read' => true]]]),
        ];
    }

    /**
     * Routes that need `api.redirects.read`: [method, path, query, body]. {rid} is replaced by an existing rule id.
     *
     * @return list<array{0: string, 1: string, 2: array<string, mixed>, 3: array<string, mixed>|null}>
     */
    private static function readRoutes(): array
    {
        return [
            ['GET', '/redirects/rules', [], null],
            ['GET', '/redirects/rules/{rid}', [], null],
            ['POST', '/redirects/rules/validate', [], ['source' => '/v', 'target' => '/typography']],
            ['GET', '/redirects/analysis', [], null],
            ['GET', '/redirects/groups', [], null],
            ['POST', '/redirects/test', [], ['url' => '/x']],
            ['GET', '/redirects/404', [], null],
            ['GET', '/redirects/404/trend', [], null],
            ['GET', '/redirects/404/entries', ['path' => '/x'], null],
            ['GET', '/redirects/suggest', ['path' => '/x'], null],
            ['GET', '/redirects/suggestions', [], null],
            ['GET', '/redirects/import/formats', [], null],
            ['GET', '/redirects/export', [], null],
            ['GET', '/redirects/site-config', [], null],
            ['GET', '/redirects/stats', [], null],
            ['GET', '/redirects/checks', [], null],
            ['GET', '/redirects/pages', [], null],
        ];
    }

    /**
     * Routes that need `api.redirects.manage`: [method, path, query]. The body is always an empty JSON object.
     *
     * @return list<array{0: string, 1: string, 2: array<string, mixed>}>
     */
    private static function manageRoutes(): array
    {
        return [
            ['POST', '/redirects/rules', []],
            ['POST', '/redirects/rules/restore', []],
            ['POST', '/redirects/rules/bulk', []],
            ['POST', '/redirects/rules/reorder', []],
            ['PATCH', '/redirects/rules/{rid}', []],
            ['DELETE', '/redirects/rules/{rid}', []],
            ['POST', '/redirects/rules/{rid}/shorten-chain', []],
            ['POST', '/redirects/404/ignore', []],
            ['POST', '/redirects/404/resolve', []],
            ['DELETE', '/redirects/404', []],
            ['POST', '/redirects/suggestions/generate', []],
            ['POST', '/redirects/suggestions/bulk-accept', []],
            ['POST', '/redirects/suggestions/{sid}/accept', []],
            ['POST', '/redirects/suggestions/{sid}/reject', []],
            ['POST', '/redirects/import/preview', []],
            ['POST', '/redirects/import/commit', []],
            ['POST', '/redirects/import/sitemap', []],
            ['POST', '/redirects/site-config/import', []],
            ['POST', '/redirects/checks/run', []],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function stored(): array
    {
        return array_map(static fn ($rule): array => $rule->toArray(), $this->site()->repository()->all());
    }

    private static function fill(string $path, string $rid, string $sid): string
    {
        return str_replace(['{rid}', '{sid}'], [$rid, $sid], $path);
    }

    /**
     * @param array<string, mixed>      $query
     * @param array<string, mixed>|null $body
     */
    private function call(ApiClient $client, string $method, string $path, array $query = [], ?array $body = null): ApiResponse
    {
        return $client->request($method, $path, $method === 'GET' ? null : ($body ?? []), $query);
    }

    private function extraClient(string $username): ApiClient
    {
        return ApiClient::login($this->site(), $username, self::$extraPasswords[$username]);
    }

    /**
     * A logged-in front-end session of the account that may use the whole redirect API, as a Cookie header value.
     */
    private function sessionCookie(): string
    {
        $cookie = SessionLogin::cookie($this->site(), 'rmsession', self::$extraPasswords['rmsession']);
        if ($cookie === null) {
            self::markTestSkipped('The front-end login (/login of the Login plugin) did not give a session cookie in this environment.');
        }

        return $cookie;
    }

    // ------------------------------------------------------------------ no credentials

    public function testEveryRouteNeedsAuthentication(): void
    {
        $rule = $this->createRule(['source' => '/a', 'target' => '/typography', 'status' => 301]);
        $anonymous = $this->api->withoutAuth();
        $checked = 0;
        foreach (self::readRoutes() as [$method, $path, $query, $body]) {
            $response = $this->call($anonymous, $method, self::fill($path, $rule['id'], 'snope'), $query, $body);
            $this->assertProblem($response, 401, $method . ' ' . $path);
            ++$checked;
        }
        foreach (self::manageRoutes() as [$method, $path, $query]) {
            $response = $this->call($anonymous, $method, self::fill($path, $rule['id'], 'snope'), $query);
            $this->assertProblem($response, 401, $method . ' ' . $path);
            ++$checked;
        }
        self::assertSame(36, $checked);
        self::assertSame(['/a'], array_column($this->stored(), 'source'), 'nothing changed');
    }

    public function testBadCredentialsAreRefused(): void
    {
        $this->assertProblem($this->api->withHeaders(['X-API-Token' => 'garbage'])->get('/redirects/rules'), 401);
        $this->assertProblem($this->api->withoutAuth()->withHeaders(['Authorization' => 'Bearer garbage'])->get('/redirects/rules'), 401);
        $this->assertProblem($this->api->withoutAuth()->withHeaders(['X-API-Key' => 'garbage'])->get('/redirects/rules'), 401);
        $this->assertProblem($this->api->withoutAuth()->post('/redirects/rules', ['source' => '/a', 'target' => '/typography']), 401);
        self::assertSame([], $this->site()->repository()->all());
    }

    // ------------------------------------------------------------------ reader

    public function testReaderMayCallEveryReadRoute(): void
    {
        $rule = $this->createRule(['source' => '/a', 'target' => '/typography', 'status' => 301]);
        foreach (self::readRoutes() as [$method, $path, $query, $body]) {
            $response = $this->call($this->reader, $method, self::fill($path, $rule['id'], 'snope'), $query, $body);
            self::assertSame(200, $response->status, $method . ' ' . $path . ' ' . $response->describe());
        }
    }

    public function testReaderCanTestAndValidateWithoutSavingAnything(): void
    {
        $tested = $this->reader->post('/redirects/test', ['url' => '/a']);
        self::assertSame(200, $tested->status, $tested->describe());
        $validated = $this->reader->post('/redirects/rules/validate', ['source' => '/a', 'target' => '/typography', 'sample' => '/a']);
        self::assertSame(200, $validated->status, $validated->describe());
        self::assertSame('/a', $validated->data()['preview']['sample']);
        self::assertSame([], $this->site()->repository()->all(), 'validate is a read: it never stores');
    }

    public function testReaderIsRefusedOnEveryManageRouteBeforeAnythingIsValidated(): void
    {
        $rule = $this->createRule(['source' => '/a', 'target' => '/typography', 'status' => 301]);
        $before = $this->stored();

        foreach (self::manageRoutes() as [$method, $path, $query]) {
            // An empty body would be a 422 for the routes that check their input: the permission must come first.
            $response = $this->call($this->reader, $method, self::fill($path, $rule['id'], 'snope'), $query);
            $this->assertProblem($response, 403, $method . ' ' . $path);
            self::assertStringContainsString('api.redirects.manage', (string) ($response->json['detail'] ?? ''), $method . ' ' . $path);
        }
        // Unknown ids are 403 too, not 404: the answer does not reveal what exists.
        $this->assertProblem($this->reader->patch('/redirects/rules/rnope', ['note' => 'x']), 403);
        $this->assertProblem($this->reader->delete('/redirects/rules/rnope'), 403);

        self::assertSame($before, $this->stored(), 'no rule changed');
        self::assertNull($this->site()->readFile('user/config/plugins/redirect-manager.yaml'), 'no configuration was written');
        self::assertSame([], $this->site()->notFoundEntries());
    }

    // ------------------------------------------------------------------ basic (api.access only)

    public function testAUserWithOnlyApiAccessIsRefusedEverywhere(): void
    {
        $rule = $this->createRule(['source' => '/a', 'target' => '/typography', 'status' => 301]);
        foreach (self::readRoutes() as [$method, $path, $query, $body]) {
            $response = $this->call($this->basic, $method, self::fill($path, $rule['id'], 'snope'), $query, $body);
            $this->assertProblem($response, 403, $method . ' ' . $path);
            self::assertStringContainsString('api.redirects.read', (string) ($response->json['detail'] ?? ''), $method . ' ' . $path);
        }
        foreach (self::manageRoutes() as [$method, $path, $query]) {
            $this->assertProblem($this->call($this->basic, $method, self::fill($path, $rule['id'], 'snope'), $query), 403, $method . ' ' . $path);
        }
        self::assertCount(1, $this->site()->repository()->all());
    }

    // ------------------------------------------------------------------ manager (write without read)

    public function testManagePermissionDoesNotIncludeReading(): void
    {
        $manager = $this->extraClient('rmmanager');
        $created = $manager->post('/redirects/rules', ['source' => '/a', 'target' => '/typography', 'status' => 301]);
        self::assertSame(201, $created->status, $created->describe());
        $this->assertRedirect($this->get('/a'), 301, '/typography');

        $this->assertProblem($manager->get('/redirects/rules'), 403);
        $this->assertProblem($manager->post('/redirects/test', ['url' => '/a']), 403);
        $this->assertProblem($manager->post('/redirects/rules/validate', ['source' => '/b', 'target' => '/typography']), 403);
        self::assertSame(200, $manager->delete('/redirects/rules/' . $created->data()['id'])->status);
    }

    public function testManagerPassesThePermissionCheckOfEveryManageRoute(): void
    {
        $manager = $this->extraClient('rmmanager');
        foreach (self::manageRoutes() as [$method, $path, $query]) {
            $response = $this->call($manager, $method, self::fill($path, 'rnope', 'snope'), $query);
            self::assertNotContains($response->status, [401, 403], $method . ' ' . $path . ' ' . $response->describe());
        }
    }

    // ------------------------------------------------------------------ token forms

    public function testTheTokenCanTravelInAuthorizationOrXApiToken(): void
    {
        [$user, $password] = ApiClient::adminCredentials() ?? ['', ''];
        $login = (new ApiClient($this->site()))->post('/auth/token', ['username' => $user, 'password' => $password]);
        $access = (string) ($login->data()['access_token'] ?? '');
        self::assertNotSame('', $access);

        $bearer = (new ApiClient($this->site(), ['Authorization' => 'Bearer ' . $access]))->get('/redirects/rules');
        self::assertSame(200, $bearer->status, $bearer->describe());
        $header = (new ApiClient($this->site(), ['X-API-Token' => $access]))->get('/redirects/rules');
        self::assertSame(200, $header->status, $header->describe());
    }

    // ------------------------------------------------------------------ cookie only (CSRF)

    public function testASessionCookieAloneSignsInForReads(): void
    {
        $cookie = $this->sessionCookie();
        $client = new ApiClient($this->site(), ['Cookie' => $cookie]);
        self::assertSame(200, $client->get('/redirects/rules')->status);
        self::assertSame(200, $client->get('/redirects/rules', [], ['Origin' => 'https://evil.example'])->status, 'reads are not forgeable');
    }

    public function testACrossOriginWriteSignedInByTheCookieAloneIsRefused(): void
    {
        $cookie = $this->sessionCookie();
        $client = new ApiClient($this->site(), ['Cookie' => $cookie]);
        $rule = $this->createRule(['source' => '/a', 'target' => '/typography', 'status' => 301]);

        $refused = $client->post('/redirects/rules', ['source' => '/evil', 'target' => '/typography', 'status' => 301], [], ['Origin' => 'https://evil.example']);
        $this->assertProblem($refused, 403);
        self::assertStringContainsString('session cookie alone', (string) ($refused->json['detail'] ?? ''));
        self::assertSame(['/a'], array_column($this->stored(), 'source'), 'nothing was created');

        // The Referer counts when there is no Origin; a form post cannot send JSON, so text/plain is the browser-form case.
        $this->assertProblem($client->post('/redirects/rules', ['source' => '/evil'], [], ['Referer' => 'https://evil.example/page']), 403);
        $form = $client->request('POST', '/redirects/rules', null, [], ['Content-Type' => 'text/plain', 'Origin' => 'https://evil.example']);
        $this->assertProblem($form, 403);
        $this->assertProblem($client->request('POST', '/redirects/rules', null, [], ['Content-Type' => 'application/x-www-form-urlencoded']), 403, 'no Origin and no JSON: could be a plain HTML form');
        $this->assertProblem($client->post('/redirects/rules', ['source' => '/evil'], [], ['Origin' => 'null']), 403, 'a sandboxed page sends the literal null');

        $this->assertProblem($client->patch('/redirects/rules/' . $rule['id'], ['note' => 'pwned'], ['Origin' => 'https://evil.example']), 403);
        $this->assertProblem($client->delete('/redirects/rules/' . $rule['id'], [], null, ['Origin' => 'https://evil.example']), 403);
        self::assertSame('', $this->stored()[0]['note'], 'the rule is untouched');
        self::assertCount(1, $this->stored());
    }

    public function testEveryManageRouteRefusesACrossOriginCookieWrite(): void
    {
        $cookie = $this->sessionCookie();
        $client = new ApiClient($this->site(), ['Cookie' => $cookie]);
        $rule = $this->createRule(['source' => '/a', 'target' => '/typography', 'status' => 301]);
        foreach (self::manageRoutes() as [$method, $path, $query]) {
            $response = $client->request($method, self::fill($path, $rule['id'], 'snope'), [], $query, ['Origin' => 'https://evil.example']);
            $this->assertProblem($response, 403, $method . ' ' . $path);
        }
        self::assertCount(1, $this->site()->repository()->all());
    }

    public function testACookieWriteFromThisSiteIsAccepted(): void
    {
        $cookie = $this->sessionCookie();
        $client = new ApiClient($this->site(), ['Cookie' => $cookie]);
        $body = ['source' => '/own', 'target' => '/typography', 'status' => 301];

        $own = $client->post('/redirects/rules', $body, [], ['Origin' => $this->site()->url('')]);
        self::assertSame(201, $own->status, $own->describe());
        $referer = $client->post('/redirects/rules', ['source' => '/own2'] + $body, [], ['Referer' => $this->site()->url('/admin')]);
        self::assertSame(201, $referer->status, $referer->describe());
        $json = $client->post('/redirects/rules', ['source' => '/own3'] + $body);
        self::assertSame(201, $json->status, 'no Origin, but a JSON body: something a plain form cannot send');
        $this->assertRedirect($this->get('/own3'), 301, '/typography');
    }

    public function testAnApiTokenIsNotAffectedByTheOriginRule(): void
    {
        $cookie = $this->sessionCookie();
        $client = $this->api->withHeaders(['Cookie' => $cookie, 'Origin' => 'https://evil.example']);
        $response = $client->post('/redirects/rules', ['source' => '/token', 'target' => '/typography', 'status' => 301]);
        self::assertSame(201, $response->status, 'a token in a header proves itself; the cookie is not what signs this request in');
        self::assertSame(201, $this->api->withHeaders(['Origin' => 'https://evil.example'])->post('/redirects/rules', ['source' => '/token2', 'target' => '/typography', 'status' => 301])->status);
    }

    public function testACookieUserIsStillLimitedByTheirPermissions(): void
    {
        $cookie = SessionLogin::cookie($this->site(), 'rmsessionreader', self::$extraPasswords['rmsessionreader']);
        if ($cookie === null) {
            self::markTestSkipped('The front-end login (/login of the Login plugin) did not give a session cookie in this environment.');
        }
        $client = new ApiClient($this->site(), ['Cookie' => $cookie]);
        self::assertSame(200, $client->get('/redirects/rules')->status);
        $write = $client->post('/redirects/rules', ['source' => '/own', 'target' => '/typography'], [], ['Origin' => $this->site()->url('')]);
        $this->assertProblem($write, 403, 'same origin, but the account may only read');
        self::assertStringContainsString('api.redirects.manage', (string) ($write->json['detail'] ?? ''));
        self::assertSame([], $this->stored());
    }
}
