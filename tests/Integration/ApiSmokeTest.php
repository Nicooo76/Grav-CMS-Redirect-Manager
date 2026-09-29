<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class ApiSmokeTest extends ApiTestCase
{
    public function testCreateRuleRedirectsTheFrontend(): void
    {
        $rule = $this->createRule(['source' => '/old', 'target' => '/typography', 'status' => 301]);
        self::assertSame(301, $rule['status']);
        $this->assertRedirect($this->get('/old'), 301, '/typography');
    }

    public function testReaderCannotWrite(): void
    {
        $this->assertProblem($this->reader->post('/redirects/rules', ['source' => '/a', 'target' => '/b']), 403);
        self::assertSame(200, $this->reader->get('/redirects/rules')->status);
        self::assertSame(403, $this->basic->get('/redirects/rules')->status);
        self::assertSame(401, $this->api->withoutAuth()->get('/redirects/rules')->status);
    }
}
