<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/** REST API, rules: list, create, read, update, delete, restore, bulk, reorder. */
#[Group('integration')]
final class ApiRulesTest extends ApiTestCase
{
    private const ERROR_KEYS = ['field', 'code', 'message', 'severity'];

    /**
     * @param array<int, array<string, mixed>> $rows
     *
     * @return list<string>
     */
    private static function sources(array $rows): array
    {
        return array_values(array_map(static fn (array $r): string => (string) $r['source'], $rows));
    }

    /**
     * @return array<string, mixed>
     */
    private function fetch(string $id): array
    {
        $response = $this->api->get('/redirects/rules/' . $id);
        self::assertSame(200, $response->status, $response->describe());
        $data = $response->data();
        self::assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
    }

    // ------------------------------------------------------------------ create

    public function testCreateAnswers201WithLocationEtagAndTheFullRule(): void
    {
        $response = $this->api->post('/redirects/rules', ['source' => '/old-page', 'target' => '/typography', 'status' => 301, 'group' => 'g', 'tags' => ['a', 'b'], 'note' => 'hello']);
        self::assertSame(201, $response->status, $response->describe());
        /** @var array<string, mixed> $rule */
        $rule = $response->data();

        self::assertMatchesRegularExpression('/^r[0-9a-f]{10,}$/', (string) $rule['id']);
        self::assertSame('/api/v1/redirects/rules/' . $rule['id'], $response->header('location'));
        self::assertMatchesRegularExpression('/^"[0-9a-f]{32}"$/', (string) $response->header('etag'));
        self::assertSame(
            ['source' => '/old-page', 'target' => '/typography', 'match_type' => 'exact', 'status' => 301, 'enabled' => true, 'priority' => 0, 'target_type' => 'route', 'note' => 'hello', 'group' => 'g', 'tags' => ['a', 'b'], 'origin' => 'manual'],
            array_intersect_key($rule, array_flip(['source', 'target', 'match_type', 'status', 'enabled', 'priority', 'target_type', 'group', 'tags', 'note', 'origin'])),
        );
        self::assertSame(['total' => 0, 'last_hit' => null, 'daily' => []], $rule['stats']);
        self::assertSame(['active'], $rule['badges']);
        self::assertSame([], $rule['issues']);
        self::assertNotEmpty($rule['created_at']);
        self::assertSame($rule['created_at'], $rule['updated_at']);

        // The ETag of the create answer is the one GET hands out for the stored rule.
        $again = $this->api->get('/redirects/rules/' . $rule['id']);
        self::assertSame($response->header('etag'), $again->header('etag'));
        self::assertSame($rule['id'], $this->site()->repository()->all()[0]->id);
    }

    public function testFrontendFollowsEveryRuleChange(): void
    {
        $this->assertNotRedirected($this->get('/old'), 'before the rule exists');

        $rule = $this->createRule(['source' => '/old', 'target' => '/typography', 'status' => 301]);
        $this->assertRedirect($this->get('/old'), 301, '/typography', 'after create');

        $patched = $this->api->patch('/redirects/rules/' . $rule['id'], ['target' => '/home', 'status' => 308]);
        self::assertSame(200, $patched->status, $patched->describe());
        $this->assertRedirect($this->get('/old'), 308, '/home', 'after PATCH');

        $this->api->patch('/redirects/rules/' . $rule['id'], ['enabled' => false]);
        $this->assertNotRedirected($this->get('/old'), 'disabled');
        $this->api->patch('/redirects/rules/' . $rule['id'], ['enabled' => true]);
        $this->assertRedirect($this->get('/old'), 308, '/home', 're-enabled');

        $deleted = $this->api->delete('/redirects/rules/' . $rule['id']);
        self::assertSame(200, $deleted->status, $deleted->describe());
        $this->assertNotRedirected($this->get('/old'), 'after DELETE');

        $restored = $this->api->post('/redirects/rules/restore', ['rules' => [$deleted->data()]]);
        self::assertSame(200, $restored->status, $restored->describe());
        $this->assertRedirect($this->get('/old'), 308, '/home', 'after restore');
    }

    public function testStatusDefaultsToTheGravRedirectDefaultCode(): void
    {
        $this->site()->writeSystemConfig(['pages' => ['redirect_default_code' => 301]]);
        self::assertSame(301, $this->createRule(['source' => '/a', 'target' => '/typography'])['status']);

        $this->site()->writeSystemConfig(['pages' => ['redirect_default_code' => 307]]);
        self::assertSame(307, $this->createRule(['source' => '/b', 'target' => '/typography'])['status']);
    }

    public function testPluginDefaultStatusBeatsTheGravDefault(): void
    {
        $this->site()->writeSystemConfig(['pages' => ['redirect_default_code' => 302]]);
        $this->site()->writePluginConfig(['redirects' => ['default_status' => 308]]);
        self::assertSame(308, $this->createRule(['source' => '/a', 'target' => '/typography'])['status']);
        self::assertSame(301, $this->createRule(['source' => '/b', 'target' => '/typography', 'status' => 301])['status'], 'an explicit status wins');
    }

    /**
     * @return iterable<string, array{array<string, mixed>, array<string, mixed>, int}> system.pages, plugin config, status of a new rule
     */
    public static function defaultStatusMatrix(): iterable
    {
        yield 'nothing set: Grav ships 302' => [[], [], 302];
        yield 'Grav 301, plugin 0' => [['redirect_default_code' => 301], ['redirects' => ['default_status' => 0]], 301];
        yield 'Grav 302, plugin 0' => [['redirect_default_code' => 302], ['redirects' => ['default_status' => 0]], 302];
        yield 'Grav 301, plugin absent' => [['redirect_default_code' => 301], [], 301];
        yield 'plugin 308 beats Grav 301' => [['redirect_default_code' => 301], ['redirects' => ['default_status' => 308]], 308];
        yield 'plugin 307 beats Grav 302' => [['redirect_default_code' => 302], ['redirects' => ['default_status' => 307]], 307];
        yield 'plugin 301 with Grav 302' => [['redirect_default_code' => 302], ['redirects' => ['default_status' => 301]], 301];
    }

    /**
     * @param array<string, mixed> $pages
     * @param array<string, mixed> $plugin
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('defaultStatusMatrix')]
    public function testNewRulesTakeTheDefaultStatusFromTheRightSetting(array $pages, array $plugin, int $expected): void
    {
        $this->site()->writeSystemConfig(['pages' => $pages]);
        if ($plugin !== []) {
            $this->site()->writePluginConfig($plugin);
        }

        $created = $this->createRule(['source' => '/default-status', 'target' => '/typography']);
        self::assertSame($expected, $created['status']);
        self::assertSame($expected, $this->get('/default-status')->status, 'the frontend answers with the stored status');
        self::assertSame($expected, $this->site()->repository()->all()[0]->status->value, 'stored in rules.yaml');

        $explicit = $expected === 301 ? 308 : 301;
        self::assertSame($explicit, $this->createRule(['source' => '/explicit', 'target' => '/typography', 'status' => $explicit])['status'], 'an explicit status is never replaced by a default');
    }

    public function testWritingRulesThroughTheApiNeverTouchesGravsSystemConfiguration(): void
    {
        $this->site()->writeSystemConfig(['pages' => ['redirect_default_route' => 301, 'redirect_default_code' => 302, 'redirect_trailing_slash' => 1]]);
        $before = $this->site()->readFile('user/config/system.yaml');
        self::assertNotNull($before);

        $created = $this->createRule(['source' => '/a', 'target' => '/typography']);
        $other = $this->createRule(['source' => '/b', 'target' => '/typography', 'status' => 308]);
        self::assertSame(200, $this->api->patch('/redirects/rules/' . $created['id'], ['target' => '/home', 'note' => 'edited'])->status);
        self::assertSame(200, $this->api->post('/redirects/rules/bulk', ['action' => 'disable', 'ids' => [$other['id']]])->status);
        self::assertSame(200, $this->api->post('/redirects/rules/reorder', ['ids' => [$other['id'], $created['id']]])->status);
        $import = $this->api->post('/redirects/import/commit', ['content' => "/c /typography 301\n", 'format' => 'netlify']);
        self::assertSame(200, $import->status, $import->describe());
        self::assertSame(200, $this->api->delete('/redirects/rules/' . $created['id'])->status);
        self::assertSame(200, $this->api->post('/redirects/rules/bulk', ['action' => 'delete', 'ids' => [$other['id']]])->status);

        self::assertSame($before, $this->site()->readFile('user/config/system.yaml'), 'system.yaml is byte-identical after create, edit, bulk, reorder, import and delete');
        self::assertSame(1, count($this->site()->repository()->all()), 'only the imported rule is left');
    }

    public function testStatus410NeedsNoTargetAndAnswersGone(): void
    {
        $rule = $this->createRule(['source' => '/gone', 'status' => 410]);
        self::assertSame('', $rule['target']);
        self::assertSame(410, $this->get('/gone')->status);
    }

    public function testDryRunValidatesWithoutSaving(): void
    {
        $response = $this->api->post('/redirects/rules', ['source' => '/dry', 'target' => '/typography', 'status' => 301], ['dry_run' => 1]);
        self::assertSame(200, $response->status, $response->describe());
        $data = $response->data();
        self::assertIsArray($data);
        self::assertSame(['rule', 'issues', 'preview'], array_keys($data));
        self::assertSame('/dry', $data['rule']['source']);
        self::assertSame([], $data['issues']);
        self::assertSame([], $this->site()->repository()->all());
        $this->assertNotRedirected($this->get('/dry'));

        $invalid = $this->api->post('/redirects/rules', ['source' => '/dry', 'target' => '//evil.example'], ['dry_run' => 'true']);
        self::assertSame(200, $invalid->status, 'a dry run reports errors in issues instead of failing');
        self::assertSame('target_protocol_relative', $invalid->data()['issues'][0]['code']);
        self::assertSame('error', $invalid->data()['issues'][0]['severity']);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string, 2: string}>
     */
    public static function invalidRules(): array
    {
        return [
            'protocol relative target' => [['source' => '/x', 'target' => '//evil.example'], 'target', 'target_protocol_relative'],
            'unsupported status' => [['source' => '/x', 'target' => '/typography', 'status' => 999], 'status', 'invalid_value'],
            'unknown match type' => [['source' => '/x', 'target' => '/typography', 'match_type' => 'fuzzy'], 'match_type', 'invalid_value'],
            'catastrophic regex' => [['source' => '/(a+)+$', 'target' => '/typography', 'match_type' => 'regex'], 'source', 'regex_catastrophic'],
            'regex syntax error' => [['source' => '/(a', 'target' => '/typography', 'match_type' => 'regex'], 'source', 'regex_invalid'],
            'empty source' => [['source' => '', 'target' => '/typography'], 'source', 'source_empty'],
            'source is a full url' => [['source' => 'https://example.com/x', 'target' => '/typography'], 'source', 'source_invalid'],
            'redirect without target' => [['source' => '/x', 'status' => 301], 'target', 'target_required'],
            'wrong type' => [['source' => ['/x'], 'target' => '/typography'], 'source', 'invalid_type'],
            'inverted dates' => [['source' => '/x', 'target' => '/typography', 'active_from' => '2030-01-02', 'expires_at' => '2030-01-01'], 'expires_at', 'dates_inverted'],
            'self redirect' => [['source' => '/x', 'target' => '/x'], 'target', 'self_redirect'],
            'external host is not allowed' => [['source' => '/x', 'target' => 'https://evil.example/x', 'target_type' => 'url'], 'target', 'target_host_not_allowed'],
            'javascript target' => [['source' => '/x', 'target' => 'javascript:alert(1)', 'target_type' => 'url'], 'target', 'target_scheme'],
        ];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('invalidRules')]
    public function testInvalidRulesAreRefusedWith422(array $body, string $field, string $code): void
    {
        $response = $this->api->post('/redirects/rules', $body);
        $this->assertProblem($response, 422);
        self::assertSame(422, $response->json['status'] ?? null);
        self::assertNotEmpty($response->json['detail'] ?? '');
        $error = $response->errors()[0] ?? [];
        foreach (self::ERROR_KEYS as $key) {
            self::assertArrayHasKey($key, $error, 'error entry has ' . $key);
        }
        self::assertSame($field, $error['field']);
        self::assertSame($code, $error['code']);
        self::assertSame('error', $error['severity']);
        self::assertNotSame('', $error['message']);
        self::assertSame([], $this->site()->repository()->all(), 'nothing was stored');
    }

    public function testRedirectLoopIsRefusedAndNamesTheCycle(): void
    {
        $this->createRule(['source' => '/a', 'target' => '/b', 'status' => 301]);
        $response = $this->api->post('/redirects/rules', ['source' => '/b', 'target' => '/a', 'status' => 301]);
        $this->assertProblem($response, 422);
        $error = $response->errors()[0];
        self::assertSame(['target', 'loop', 'error'], [$error['field'], $error['code'], $error['severity']]);
        self::assertSame(['/b', '/a', '/b'], $error['params']['chain']);
        self::assertCount(1, $this->site()->repository()->all());
    }

    public function testChainIsAWarningNotAnError(): void
    {
        $this->createRule(['source' => '/a', 'target' => '/b', 'status' => 301]);
        $response = $this->api->post('/redirects/rules', ['source' => '/b', 'target' => '/c', 'status' => 301]);
        self::assertSame(201, $response->status, $response->describe());
        $rule = $response->data();
        self::assertContains('chain', $rule['badges']);
        $issue = $rule['issues'][0];
        self::assertSame(['chain', 'warning'], [$issue['code'], $issue['severity']]);
        self::assertSame(['/a', '/b', '/c'], $issue['params']['chain']);
        self::assertSame('/c', $issue['params']['shortcut']);
        self::assertCount(2, $this->site()->repository()->all());
    }

    public function testConflictsAndDuplicatesComeBackAsWarnings(): void
    {
        $first = $this->createRule(['source' => '/a', 'target' => '/typography', 'status' => 301]);
        $other = $this->api->post('/redirects/rules', ['source' => '/a', 'target' => '/home', 'status' => 302]);
        self::assertSame(201, $other->status, $other->describe());
        self::assertContains('conflict', $other->data()['badges']);
        self::assertSame(['conflict', 'shadowed'], array_column($other->data()['issues'], 'code'));
        self::assertSame([$first['id']], $other->data()['issues'][0]['params']['rule_ids']);
        self::assertSame(['warning', 'warning'], array_column($other->data()['issues'], 'severity'));

        $same = $this->api->post('/redirects/rules', ['source' => '/a', 'target' => '/typography', 'status' => 301]);
        self::assertSame(201, $same->status, $same->describe());
        self::assertContains('duplicate', array_column($same->data()['issues'], 'code'));
        self::assertCount(3, $this->site()->repository()->all());
        $this->assertRedirect($this->get('/a'), 301, '/typography');
    }

    public function testExternalTargetsNeedTheHostToBeAllowedAndTargetTypeUrl(): void
    {
        $this->site()->writePluginConfig(['security' => ['allowed_hosts' => ['example.com', '*.example.org']]]);
        $rule = $this->createRule(['source' => '/ext', 'target' => 'https://example.com/x?y=1', 'target_type' => 'url', 'status' => 302]);
        self::assertSame('url', $rule['target_type']);
        $this->assertRedirect($this->get('/ext'), 302, 'https://example.com/x?y=1');
        self::assertSame(201, $this->api->post('/redirects/rules', ['source' => '/sub', 'target' => 'https://shop.example.org/', 'target_type' => 'url', 'status' => 301])->status, 'wildcard host');
        $this->assertProblem($this->api->post('/redirects/rules', ['source' => '/other', 'target' => 'https://evil.example/', 'target_type' => 'url', 'status' => 301]), 422);

        // An absolute URL without target_type is inferred as a url target.
        $bare = $this->api->post('/redirects/rules', ['source' => '/bare', 'target' => 'https://example.com/x', 'status' => 301]);
        self::assertSame(201, $bare->status, $bare->describe());
        self::assertSame('url', is_array($bare->data()) ? $bare->data()['target_type'] : null);
    }

    // ------------------------------------------------------------------ read, update, delete

    public function testGetOneRuleWithStatsBadgesAndIssues(): void
    {
        $rule = $this->createRule(['source' => '/a', 'target' => '/typography', 'status' => 301]);
        $response = $this->api->get('/redirects/rules/' . $rule['id']);
        self::assertSame(200, $response->status);
        self::assertNotNull($response->header('etag'));
        $data = $response->data();
        foreach (['stats', 'badges', 'issues', 'conditions', 'created_at'] as $key) {
            self::assertArrayHasKey($key, $data);
        }
        self::assertSame($rule['id'], $data['id']);
    }

    public function testUnknownRuleIsAProblemJson404ForEveryVerb(): void
    {
        $this->assertProblem($this->api->get('/redirects/rules/rnope'), 404);
        $this->assertProblem($this->api->patch('/redirects/rules/rnope', ['note' => 'x']), 404);
        $this->assertProblem($this->api->delete('/redirects/rules/rnope'), 404);
        $this->assertProblem($this->api->post('/redirects/rules/rnope/shorten-chain'), 404);
        $body = $this->api->get('/redirects/rules/rnope')->json;
        self::assertSame(404, $body['status'] ?? null);
        self::assertStringContainsString('rnope', (string) ($body['detail'] ?? ''));
    }

    public function testPatchChangesOnlyTheGivenFields(): void
    {
        $rule = $this->createRule(['source' => '/a', 'target' => '/typography', 'status' => 301, 'note' => 'keep', 'group' => 'old']);
        usleep(1_100_000); // updated_at has a resolution of one second
        $response = $this->api->patch('/redirects/rules/' . $rule['id'], ['group' => 'new', 'priority' => 5]);
        self::assertSame(200, $response->status, $response->describe());
        $data = $response->data();
        self::assertSame(['/a', '/typography', 301, 'keep', 'new', 5], [$data['source'], $data['target'], $data['status'], $data['note'], $data['group'], $data['priority']]);
        self::assertSame($rule['created_at'], $data['created_at']);
        self::assertGreaterThan($rule['updated_at'], $data['updated_at']);
        self::assertSame($response->header('etag'), $this->api->get('/redirects/rules/' . $rule['id'])->header('etag'));
    }

    public function testIfMatchGuardsAgainstLostUpdates(): void
    {
        $rule = $this->createRule(['source' => '/a', 'target' => '/typography', 'status' => 301]);
        $etag = (string) $this->api->get('/redirects/rules/' . $rule['id'])->header('etag');

        $stale = $this->api->patch('/redirects/rules/' . $rule['id'], ['note' => 'x'], ['If-Match' => '"0123456789abcdef0123456789abcdef"']);
        $this->assertProblem($stale, 409);
        self::assertSame('', $this->fetch($rule['id'])['note'], 'a refused update changes nothing');

        $fresh = $this->api->patch('/redirects/rules/' . $rule['id'], ['note' => 'first'], ['If-Match' => $etag]);
        self::assertSame(200, $fresh->status, $fresh->describe());

        $again = $this->api->patch('/redirects/rules/' . $rule['id'], ['note' => 'second'], ['If-Match' => $etag]);
        $this->assertProblem($again, 409, 'the old ETag is stale after a change');
        $this->assertProblem($this->api->delete('/redirects/rules/' . $rule['id'], [], null, ['If-Match' => $etag]), 409);
        self::assertSame('first', $this->fetch($rule['id'])['note']);

        $ok = $this->api->patch('/redirects/rules/' . $rule['id'], ['note' => 'third'], ['If-Match' => (string) $fresh->header('etag')]);
        self::assertSame(200, $ok->status);
    }

    public function testPatchIsValidatedLikeCreate(): void
    {
        $a = $this->createRule(['source' => '/a', 'target' => '/b', 'status' => 301]);
        $this->createRule(['source' => '/b', 'target' => '/typography', 'status' => 301]);

        $loop = $this->api->patch('/redirects/rules/' . $this->createRule(['source' => '/c', 'target' => '/typography', 'status' => 301])['id'], ['target' => '/a']);
        self::assertSame(200, $loop->status, 'c -> a -> b -> typography is a chain, not a loop');
        $response = $this->api->patch('/redirects/rules/' . $a['id'], ['target' => '/c']);
        $this->assertProblem($response, 422);
        self::assertSame('loop', $response->errors()[0]['code']);
        self::assertSame('/b', $this->fetch($a['id'])['target']);

        $bad = $this->api->patch('/redirects/rules/' . $a['id'], ['status' => 999]);
        $this->assertProblem($bad, 422);
        self::assertSame('status', $bad->errors()[0]['field']);
    }

    public function testDeleteReturnsTheRuleAndASecondDeleteIs404(): void
    {
        $rule = $this->createRule(['source' => '/a', 'target' => '/typography', 'status' => 301, 'note' => 'undo me']);
        $deleted = $this->api->delete('/redirects/rules/' . $rule['id']);
        self::assertSame(200, $deleted->status, $deleted->describe());
        self::assertSame($rule['id'], $deleted->data()['id']);
        self::assertSame('undo me', $deleted->data()['note']);
        self::assertSame([], $this->site()->repository()->all());
        $this->assertProblem($this->api->delete('/redirects/rules/' . $rule['id']), 404);
        $this->assertProblem($this->api->get('/redirects/rules/' . $rule['id']), 404);
    }

    public function testRestoreBringsBackDeletedRulesWithTheirIds(): void
    {
        $a = $this->createRule(['source' => '/a', 'target' => '/typography', 'status' => 301, 'group' => 'g']);
        $b = $this->createRule(['source' => '/b', 'target' => '/home', 'status' => 302, 'tags' => ['t']]);
        $bulk = $this->api->post('/redirects/rules/bulk', ['action' => 'delete', 'ids' => [$a['id'], $b['id']]]);
        self::assertSame(2, $bulk->data()['affected']);
        self::assertSame([], $this->site()->repository()->all());

        $restored = $this->api->post('/redirects/rules/restore', ['rules' => $bulk->data()['rules']]);
        self::assertSame(200, $restored->status, $restored->describe());
        self::assertSame(2, $restored->data()['restored']);

        $list = $this->api->get('/redirects/rules', ['sort' => 'source', 'dir' => 'asc'])->data();
        self::assertSame([$a['id'], $b['id']], array_column($list, 'id'));
        self::assertSame(['g', ['t']], [$list[0]['group'], $list[1]['tags']]);
        self::assertSame($a['created_at'], $list[0]['created_at']);
    }

    public function testRestoreRefusesEmptyOrIncompleteInput(): void
    {
        $empty = $this->api->post('/redirects/rules/restore', ['rules' => []]);
        $this->assertProblem($empty, 422);
        self::assertSame('rules', $empty->errors()[0]['field']);

        $noId = $this->api->post('/redirects/rules/restore', ['rules' => [['source' => '/a', 'target' => '/b']]]);
        $this->assertProblem($noId, 422);
        self::assertSame('rules.0', $noId->errors()[0]['field']);
        self::assertSame([], $this->site()->repository()->all());
    }

    // ------------------------------------------------------------------ bulk, reorder

    public function testBulkEnableDisableAndSetStatus(): void
    {
        $a = $this->createRule(['source' => '/a', 'target' => '/typography', 'status' => 301]);
        $b = $this->createRule(['source' => '/b', 'target' => '/typography', 'status' => 301]);
        $ids = [$a['id'], $b['id']];

        $off = $this->api->post('/redirects/rules/bulk', ['action' => 'disable', 'ids' => $ids]);
        self::assertSame(200, $off->status, $off->describe());
        self::assertSame(2, $off->data()['affected']);
        self::assertSame([false, false], array_column($off->data()['rules'], 'enabled'));
        self::assertSame([], $off->data()['skipped']);
        $this->assertNotRedirected($this->get('/a'));

        // Nothing changes when the rule already is in the wanted state.
        self::assertSame(0, $this->api->post('/redirects/rules/bulk', ['action' => 'disable', 'ids' => $ids])->data()['affected']);

        $on = $this->api->post('/redirects/rules/bulk', ['action' => 'enable', 'ids' => $ids]);
        self::assertSame(2, $on->data()['affected']);
        $this->assertRedirect($this->get('/a'), 301, '/typography');

        $status = $this->api->post('/redirects/rules/bulk', ['action' => 'set_status', 'ids' => $ids, 'value' => 308]);
        self::assertSame(2, $status->data()['affected']);
        $this->assertRedirect($this->get('/b'), 308, '/typography');

        $gone = $this->api->post('/redirects/rules/bulk', ['action' => 'set_status', 'ids' => [$a['id']], 'value' => 410]);
        self::assertSame(410, $gone->data()['rules'][0]['status']);
        self::assertSame('', $gone->data()['rules'][0]['target'], 'a 410 rule loses its target');
        self::assertSame(410, $this->get('/a')->status);
    }

    public function testBulkGroupAndTags(): void
    {
        $a = $this->createRule(['source' => '/a', 'target' => '/typography', 'status' => 301, 'tags' => ['keep']]);
        $b = $this->createRule(['source' => '/b', 'target' => '/typography', 'status' => 301]);
        $ids = [$a['id'], $b['id']];

        $group = $this->api->post('/redirects/rules/bulk', ['action' => 'set_group', 'ids' => $ids, 'value' => 'Blog']);
        self::assertSame(['Blog', 'Blog'], array_column($group->data()['rules'], 'group'));

        $added = $this->api->post('/redirects/rules/bulk', ['action' => 'add_tag', 'ids' => $ids, 'value' => 'seo']);
        self::assertSame(2, $added->data()['affected']);
        self::assertSame(0, $this->api->post('/redirects/rules/bulk', ['action' => 'add_tag', 'ids' => $ids, 'value' => 'seo'])->data()['affected'], 'the tag is there already');
        self::assertSame([['keep', 'seo'], ['seo']], array_column($this->api->get('/redirects/rules', ['sort' => 'source', 'dir' => 'asc'])->data(), 'tags'));

        $removed = $this->api->post('/redirects/rules/bulk', ['action' => 'remove_tag', 'ids' => $ids, 'value' => 'seo']);
        self::assertSame(2, $removed->data()['affected']);
        self::assertSame([['keep'], []], array_column($this->api->get('/redirects/rules', ['sort' => 'source', 'dir' => 'asc'])->data(), 'tags'));

        self::assertSame(2, $this->api->post('/redirects/rules/bulk', ['action' => 'set_group', 'ids' => $ids, 'value' => ''])->data()['affected']);
        self::assertSame([], $this->api->get('/redirects/groups')->data()['groups']);
    }

    public function testBulkDeleteReturnsTheDeletedRulesAndSkipsUnknownIds(): void
    {
        $a = $this->createRule(['source' => '/a', 'target' => '/typography', 'status' => 301]);
        $b = $this->createRule(['source' => '/b', 'target' => '/typography', 'status' => 301]);

        $response = $this->api->post('/redirects/rules/bulk', ['action' => 'delete', 'ids' => [$a['id'], 'rghost']]);
        self::assertSame(200, $response->status, $response->describe());
        $data = $response->data();
        self::assertSame(1, $data['affected']);
        self::assertSame([$a['id']], array_column($data['rules'], 'id'));
        self::assertSame([['id' => 'rghost', 'reason' => 'not_found', 'errors' => []]], $data['skipped']);
        self::assertSame([$b['id']], array_column($this->api->get('/redirects/rules')->data(), 'id'));

        $mixed = $this->api->post('/redirects/rules/bulk', ['action' => 'disable', 'ids' => [$b['id'], 'rghost']]);
        self::assertSame(1, $mixed->data()['affected']);
        self::assertSame('rghost', $mixed->data()['skipped'][0]['id']);
    }

    public function testBulkRefusesBadRequests(): void
    {
        $rule = $this->createRule(['source' => '/a', 'target' => '/typography', 'status' => 301]);
        $unknown = $this->api->post('/redirects/rules/bulk', ['action' => 'explode', 'ids' => [$rule['id']]]);
        $this->assertProblem($unknown, 422);
        self::assertSame('action', $unknown->errors()[0]['field']);

        $this->assertProblem($this->api->post('/redirects/rules/bulk', ['action' => 'enable', 'ids' => []]), 422);
        $this->assertProblem($this->api->post('/redirects/rules/bulk', ['action' => 'set_status', 'ids' => [$rule['id']], 'value' => 999]), 422);
        $this->assertProblem($this->api->post('/redirects/rules/bulk', ['action' => 'add_tag', 'ids' => [$rule['id']]]), 422);
    }

    public function testBulkSetStatusSkipsRulesThatWouldBecomeInvalid(): void
    {
        $this->rules([
            ['id' => 'ok', 'source' => '/ok', 'target' => '/typography', 'status' => 301],
            ['id' => 'ext', 'source' => '/ext', 'target' => 'https://example.com/x', 'status' => 301, 'target_type' => 'url'],
        ]);
        $response = $this->api->post('/redirects/rules/bulk', ['action' => 'set_status', 'ids' => ['ok', 'ext'], 'value' => 200]);
        self::assertSame(200, $response->status, $response->describe());
        self::assertSame(['ok'], array_column($response->data()['rules'], 'id'));
        $skipped = $response->data()['skipped'];
        self::assertSame(['ext', 'invalid', 'passthrough_external'], [$skipped[0]['id'], $skipped[0]['reason'], $skipped[0]['errors'][0]['code']]);
    }

    public function testReorderAssignsDescendingPriorities(): void
    {
        $a = $this->createRule(['source' => '/a', 'target' => '/typography', 'status' => 301]);
        $b = $this->createRule(['source' => '/b', 'target' => '/typography', 'status' => 301]);
        $c = $this->createRule(['source' => '/c', 'target' => '/typography', 'status' => 301]);

        $response = $this->api->post('/redirects/rules/reorder', ['ids' => [$c['id'], $a['id'], $b['id']]]);
        self::assertSame(200, $response->status, $response->describe());
        self::assertGreaterThan(0, $response->data()['affected']);

        $list = $this->api->get('/redirects/rules')->data();
        self::assertSame(['/c', '/a', '/b'], self::sources($list), 'default order is by priority');
        $priorities = array_column($list, 'priority');
        $sorted = $priorities;
        rsort($sorted);
        self::assertSame($sorted, $priorities);
        self::assertSame(3, count(array_unique($priorities)));

        $this->api->post('/redirects/rules/reorder', ['ids' => [$b['id'], $c['id']]]);
        self::assertSame(['/b', '/c'], array_values(array_intersect(self::sources($this->api->get('/redirects/rules')->data()), ['/b', '/c'])));
    }

    public function testReorderRefusesUnknownIdsAndSingleRules(): void
    {
        $a = $this->createRule(['source' => '/a', 'target' => '/typography', 'status' => 301]);
        $this->assertProblem($this->api->post('/redirects/rules/reorder', ['ids' => [$a['id']]]), 422);
        $this->assertProblem($this->api->post('/redirects/rules/reorder', ['ids' => [$a['id'], 'rghost']]), 404);
    }

    // ------------------------------------------------------------------ list

    /**
     * @return list<array<string, mixed>>
     */
    private function seedList(): array
    {
        $now = new \DateTimeImmutable();
        $rows = [
            ['id' => 'r1', 'source' => '/alpha', 'target' => '/typography', 'status' => 301, 'group' => 'Blog', 'note' => 'Findme', 'priority' => 5, 'tags' => ['t1'], 'created_at' => $now->modify('-3 days')->format(DATE_ATOM)],
            ['id' => 'r2', 'source' => '/beta/*', 'target' => '/home/$1', 'match_type' => 'wildcard', 'status' => 302, 'group' => 'Shop', 'priority' => 3, 'created_at' => $now->modify('-2 days')->format(DATE_ATOM)],
            ['id' => 'r3', 'source' => '^/gamma/(\d+)$', 'target' => '/typography', 'match_type' => 'regex', 'status' => 301, 'origin' => 'import', 'enabled' => false, 'created_at' => $now->modify('-1 day')->format(DATE_ATOM)],
            ['id' => 'r4', 'source' => '/delta', 'target' => '/typography', 'status' => 308, 'expires_at' => $now->modify('-1 day')->format(DATE_ATOM), 'created_at' => $now->modify('-9 days')->format(DATE_ATOM)],
            ['id' => 'r5', 'source' => '/epsilon', 'target' => '/typography', 'status' => 301, 'active_from' => $now->modify('+5 days')->format(DATE_ATOM), 'created_at' => $now->format(DATE_ATOM)],
            ['id' => 'r6', 'source' => '/zeta', 'target' => '/alpha', 'status' => 301, 'created_at' => $now->modify('-4 days')->format(DATE_ATOM)],
        ];
        $this->rules($rows);

        return $rows;
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return list<string>
     */
    private function listIds(array $query): array
    {
        $response = $this->api->get('/redirects/rules', $query);
        self::assertSame(200, $response->status, $response->describe());

        return array_values(array_map('strval', array_column((array) $response->data(), 'id')));
    }

    public function testListFilters(): void
    {
        $this->seedList();
        $filter = function (array $query): array {
            $ids = $this->listIds($query);
            sort($ids);

            return $ids;
        };

        self::assertSame(['r1', 'r2', 'r3', 'r4', 'r5', 'r6'], $filter([]));
        self::assertSame(['r1', 'r6'], $filter(['q' => 'alpha']), 'q searches source and target');
        self::assertSame(['r1'], $filter(['q' => 'findme']), 'q searches the note, case-insensitively');
        self::assertSame(['r1'], $filter(['q' => 't1']), 'q searches tags');
        self::assertSame(['r2'], $filter(['match_type' => 'wildcard']));
        self::assertSame(['r3'], $filter(['match_type' => 'regex']));
        self::assertSame(['r2'], $filter(['status' => 302]));
        self::assertSame(['r4'], $filter(['status' => 308]));
        self::assertSame(['r1', 'r2', 'r6'], $filter(['state' => 'active']));
        self::assertSame(['r3'], $filter(['state' => 'disabled']));
        self::assertSame(['r4'], $filter(['state' => 'expired']));
        self::assertSame(['r5'], $filter(['state' => 'scheduled']));
        self::assertSame(['r1'], $filter(['group' => 'Blog']));
        self::assertSame(['r3'], $filter(['origin' => 'import']));
        self::assertSame(['r1', 'r2', 'r6'], $filter(['badge' => 'active']));
        self::assertSame(['r4'], $filter(['badge' => 'expired']));
        self::assertSame(['r6'], $filter(['unused_days' => 1, 'q' => 'zeta']), 'unused_days combines with other filters');
        self::assertSame([], $filter(['q' => 'nothing-matches-this']));
    }

    public function testListBadgeChainAndLoopFilters(): void
    {
        $this->rules([
            ['id' => 'a', 'source' => '/a', 'target' => '/b'],
            ['id' => 'b', 'source' => '/b', 'target' => '/typography'],
            ['id' => 'l1', 'source' => '/l1', 'target' => '/l2'],
            ['id' => 'l2', 'source' => '/l2', 'target' => '/l1'],
        ]);
        // The badge sits on the rule that starts the chain (the create answer additionally flags the second rule).
        self::assertSame(['a'], $this->listIds(['badge' => 'chain']));
        $loops = $this->listIds(['badge' => 'loop']);
        sort($loops);
        self::assertSame(['l1', 'l2'], $loops);
        $counts = $this->api->get('/redirects/rules')->meta()['counts'];
        self::assertSame(1, $counts['chain']);
        self::assertSame(2, $counts['loop']);
    }

    public function testListSortsAndPages(): void
    {
        $this->seedList();
        self::assertSame(['r1', 'r2'], array_slice($this->listIds([]), 0, 2), 'default: priority high to low');
        self::assertSame(['/alpha', '/beta/*', '/delta', '/epsilon', '/zeta', '^/gamma/(\d+)$'], self::sources($this->api->get('/redirects/rules', ['sort' => 'source'])->data()));
        self::assertSame(['^/gamma/(\d+)$', '/zeta', '/epsilon', '/delta', '/beta/*'], array_slice(self::sources($this->api->get('/redirects/rules', ['sort' => 'source', 'dir' => 'desc'])->data()), 0, 5));
        self::assertSame(['r4', 'r2'], array_slice($this->listIds(['sort' => 'status', 'dir' => 'desc']), 0, 2), 'sorted by status: 308, 302, then the 301s');
        self::assertSame('r5', $this->listIds(['sort' => 'created_at'])[0], 'newest first');
        self::assertSame('r4', $this->listIds(['sort' => 'created_at', 'dir' => 'asc'])[0]);

        $page = $this->api->get('/redirects/rules', ['sort' => 'source', 'per_page' => 2, 'page' => 2]);
        self::assertSame(['/delta', '/epsilon'], self::sources($page->data()));
        $meta = $page->meta();
        self::assertSame([6, 2, 2], [$meta['total'], $meta['page'], $meta['per_page']]);
        self::assertSame(['page' => 2, 'per_page' => 2, 'total' => 6, 'total_pages' => 3], $meta['pagination']);
        $links = $page->json['links'] ?? [];
        foreach (['self', 'first', 'prev', 'next'] as $rel) {
            self::assertArrayHasKey($rel, $links, $rel . ' link');
        }
        self::assertStringContainsString('page=3', $links['next']);
        self::assertSame(['/api/v1/redirects/rules?'], [substr($links['self'], 0, 24)]);
    }

    public function testListMetaCarriesGroupsAndBadgeCounts(): void
    {
        $this->seedList();
        $meta = $this->api->get('/redirects/rules')->meta();
        self::assertSame(['Blog', 'Shop'], $meta['groups']);
        self::assertSame(['active' => 3, 'disabled' => 1, 'expired' => 1, 'scheduled' => 1], array_intersect_key($meta['counts'], array_flip(['active', 'disabled', 'expired', 'scheduled'])));
        self::assertSame(['active', 'disabled', 'expired', 'scheduled', 'chain', 'loop', 'conflict', 'dead_target', 'unused'], array_keys($meta['counts']));

        $filtered = $this->api->get('/redirects/rules', ['group' => 'Blog'])->meta();
        self::assertSame(1, $filtered['total']);
        self::assertSame(['Blog', 'Shop'], $filtered['groups'], 'the group list ignores the filter');
        self::assertSame(1, $filtered['counts']['active'], 'counts follow the filter');
    }

    public function testListCapsPerPageAt500AndRejectsBadParameters(): void
    {
        $this->seedList();
        self::assertSame(500, $this->api->get('/redirects/rules', ['per_page' => 9999])->meta()['per_page']);
        foreach ([['sort' => 'nope'], ['dir' => 'sideways'], ['match_type' => 'fuzzy'], ['state' => 'weird'], ['badge' => 'shiny'], ['status' => 'abc'], ['page' => 'x']] as $query) {
            $response = $this->api->get('/redirects/rules', $query);
            $this->assertProblem($response, 422, json_encode($query, JSON_THROW_ON_ERROR));
            self::assertSame((string) array_key_first($query), $response->errors()[0]['field']);
        }
    }

    public function testListShowsHitStatisticsAfterFrontendHits(): void
    {
        $rule = $this->createRule(['source' => '/hit', 'target' => '/typography', 'status' => 301]);
        $this->get('/hit');
        $this->get('/hit');
        $row = $this->api->get('/redirects/rules')->data()[0];
        self::assertSame($rule['id'], $row['id']);
        self::assertSame(2, $row['stats']['total']);
        self::assertNotNull($row['stats']['last_hit']);
        self::assertSame([date('Y-m-d') => 2], $row['stats']['daily']);
        self::assertSame($row['id'], $this->listIds(['sort' => 'hits'])[0]);
    }
}
