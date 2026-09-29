<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/** REST API, suggestions: live suggest, generate, list, accept, reject, bulk-accept. */
#[Group('integration')]
final class ApiSuggestionsTest extends ApiTestCase
{
    private const BROWSER = 'Mozilla/5.0 (Macintosh) AppleWebKit/537.36 Chrome/120 Safari/537.36';

    protected function setUp(): void
    {
        parent::setUp();
        $this->site()->writePage('blog-post', 'Blog Post', 'x', null, '20');
        $this->site()->writePage('kontakt', 'Kontakt', 'x', null, '30');
    }

    private function miss(string $path, int $times = 1): void
    {
        for ($i = 0; $i < $times; ++$i) {
            self::assertSame(404, $this->get($path, ['headers' => ['User-Agent' => self::BROWSER]])->status, $path);
        }
    }

    /**
     * Three 404 paths: /typograpy (0.66), /blog-pots (0.58), /blog/blog-post (0.95) and /zzzzqqq that gets no suggestion.
     */
    private function seed404s(): void
    {
        $this->miss('/typograpy', 3);
        $this->miss('/blog-pots', 2);
        $this->miss('/blog/blog-post');
        $this->miss('/zzzzqqq');
    }

    /**
     * @return array<string, array<string, mixed>> path => suggestion row
     */
    private function generateAndList(): array
    {
        $this->seed404s();
        $generated = $this->api->post('/redirects/suggestions/generate');
        self::assertSame(200, $generated->status, $generated->describe());
        $list = $this->api->get('/redirects/suggestions');
        self::assertSame(200, $list->status, $list->describe());

        /** @var array<string, array<string, mixed>> $rows */
        $rows = array_column((array) $list->data(), null, 'path');

        return $rows;
    }

    public function testLiveSuggestionsForOnePath(): void
    {
        $response = $this->api->get('/redirects/suggest', ['path' => '/typograpy']);
        self::assertSame(200, $response->status, $response->describe());
        $best = $response->data()[0];
        self::assertSame(['/typography', 'similar_route', 'Typography'], [$best['target'], $best['reason'], $best['page_title']]);
        self::assertGreaterThan(0.5, $best['score']);
        self::assertLessThanOrEqual(1.0, $best['score']);
        self::assertArrayHasKey('details', $best);

        $slug = $this->api->get('/redirects/suggest', ['path' => '/blog/blog-post'])->data()[0];
        self::assertSame(['/blog-post', 'same_slug'], [$slug['target'], $slug['reason']]);
        $normalized = $this->api->get('/redirects/suggest', ['path' => '/Kontakt/'])->data()[0];
        self::assertSame('/kontakt', $normalized['target']);
        self::assertGreaterThan(0.9, $normalized['score']);

        $fallback = $this->api->get('/redirects/suggest', ['path' => '/zzzzqqq'])->data()[0];
        self::assertSame(['/', 'home_fallback'], [$fallback['target'], $fallback['reason']]);
        self::assertLessThan(0.5, $fallback['score']);
        self::assertLessThanOrEqual(1, count($this->api->get('/redirects/suggest', ['path' => '/blog/blog-post', 'limit' => 1])->data()));
    }

    public function testLiveSuggestionsNeedAPath(): void
    {
        $response = $this->api->get('/redirects/suggest');
        $this->assertProblem($response, 422);
        self::assertSame(['path', 'required'], [$response->errors()[0]['field'], $response->errors()[0]['code']]);
        $this->assertProblem($this->api->get('/redirects/suggest', ['path' => '  ']), 422);
    }

    public function testGenerateStoresSuggestionsForOpen404Paths(): void
    {
        $this->seed404s();
        $generated = $this->api->post('/redirects/suggestions/generate');
        self::assertSame(200, $generated->status, $generated->describe());
        self::assertSame(
            ['paths' => 4, 'suggested' => 3, 'created' => 3, 'improved' => 0, 'rejected_before' => 0, 'no_suggestion' => 1, 'skipped_with_rule' => 0],
            $generated->data(),
            '/zzzzqqq only has the 0.1 home fallback, below suggestions.min_score',
        );

        $rows = array_column($this->api->get('/redirects/suggestions')->data(), null, 'path');
        self::assertSame(['/blog/blog-post', '/typograpy', '/blog-pots'], array_keys($rows), 'best score first');
        self::assertSame(['/typography', 'open', '404', 3], [$rows['/typograpy']['target'], $rows['/typograpy']['status'], $rows['/typograpy']['source'], $rows['/typograpy']['hits']]);
        self::assertSame(['/blog-post', 'same_slug'], [$rows['/blog/blog-post']['target'], $rows['/blog/blog-post']['reason']]);
        self::assertMatchesRegularExpression('/^s[0-9a-f]{10,}$/', (string) $rows['/typograpy']['id']);
        foreach (['id', 'path', 'target', 'score', 'reason', 'page_title', 'hits', 'status', 'source', 'created_at', 'decided_at'] as $key) {
            self::assertArrayHasKey($key, $rows['/typograpy']);
        }
        self::assertNull($rows['/typograpy']['decided_at']);
        self::assertSame(3, $this->api->get('/redirects/suggestions')->meta()['total']);
        self::assertSame(3, $this->api->get('/redirects/stats')->data()['open_suggestions']);
    }

    public function testGenerateIsIdempotentAndSkipsPathsWithARule(): void
    {
        $this->seed404s();
        $this->api->post('/redirects/suggestions/generate');
        $again = $this->api->post('/redirects/suggestions/generate')->data();
        self::assertSame([0, 0, 0], [$again['suggested'], $again['created'], $again['improved']], 'nothing new the second time');
        self::assertCount(3, $this->api->get('/redirects/suggestions')->data());

        $this->createRule(['source' => '/typograpy', 'target' => '/typography', 'status' => 301]);
        self::assertSame(1, $this->api->post('/redirects/suggestions/generate')->data()['skipped_with_rule']);
    }

    public function testGenerateWithoutAny404IsAnEmptyRun(): void
    {
        self::assertSame(
            ['paths' => 0, 'suggested' => 0, 'created' => 0, 'improved' => 0, 'rejected_before' => 0, 'no_suggestion' => 0, 'skipped_with_rule' => 0],
            $this->api->post('/redirects/suggestions/generate')->data(),
        );
        self::assertSame([], $this->api->get('/redirects/suggestions')->data());
    }

    public function testListFilters(): void
    {
        $rows = $this->generateAndList();
        self::assertCount(3, $rows);

        self::assertSame(['/blog/blog-post'], array_column($this->api->get('/redirects/suggestions', ['min_score' => 0.9])->data(), 'path'));
        self::assertSame(['/blog/blog-post', '/typograpy'], array_column($this->api->get('/redirects/suggestions', ['min_score' => '0.6'])->data(), 'path'));
        self::assertSame(3, $this->api->get('/redirects/suggestions', ['source' => '404'])->meta()['total']);
        self::assertSame([], $this->api->get('/redirects/suggestions', ['source' => 'sitemap'])->data());
        self::assertSame([], $this->api->get('/redirects/suggestions', ['status' => 'accepted'])->data());
        self::assertSame([], $this->api->get('/redirects/suggestions', ['status' => 'rejected'])->data());

        $this->api->post('/redirects/suggestions/' . $rows['/typograpy']['id'] . '/reject');
        self::assertCount(2, $this->api->get('/redirects/suggestions')->data(), 'open is the default');
        self::assertCount(3, $this->api->get('/redirects/suggestions', ['status' => 'all'])->data());
        self::assertSame(['/typograpy'], array_column($this->api->get('/redirects/suggestions', ['status' => 'rejected'])->data(), 'path'));
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function badListQueries(): array
    {
        return [
            'status' => [['status' => 'maybe'], 'status'],
            'min_score' => [['min_score' => 'high'], 'min_score'],
        ];
    }

    /**
     * @param array<string, mixed> $query
     */
    #[DataProvider('badListQueries')]
    public function testListRejectsBadQueries(array $query, string $field): void
    {
        $response = $this->api->get('/redirects/suggestions', $query);
        $this->assertProblem($response, 422);
        self::assertSame($field, $response->errors()[0]['field']);
    }

    public function testAcceptCreatesARuleWithOriginSuggestionAndTheFrontendRedirects(): void
    {
        $rows = $this->generateAndList();
        $this->assertNotRedirected($this->get('/typograpy'));

        $response = $this->api->post('/redirects/suggestions/' . $rows['/typograpy']['id'] . '/accept', ['status' => 301]);
        self::assertSame(201, $response->status, $response->describe());
        $data = $response->data();
        self::assertSame('/api/v1/redirects/rules/' . $data['rule']['id'], $response->header('location'));
        self::assertSame(['/typograpy', '/typography', 301, 'suggestion', 'page'], [$data['rule']['source'], $data['rule']['target'], $data['rule']['status'], $data['rule']['origin'], $data['rule']['target_type']]);
        self::assertSame('accepted', $data['suggestion']['status']);
        self::assertNotNull($data['suggestion']['decided_at']);
        $this->assertRedirect($this->get('/typograpy'), 301, '/typography');

        $stored = $this->api->get('/redirects/rules/' . $data['rule']['id']);
        self::assertSame(200, $stored->status);
        self::assertSame('suggestion', $stored->data()['origin']);
        self::assertCount(2, $this->api->get('/redirects/suggestions')->data());
        self::assertSame(['/typograpy'], array_column($this->api->get('/redirects/suggestions', ['status' => 'accepted'])->data(), 'path'));
    }

    public function testAcceptTakesTargetAndStatusOverrides(): void
    {
        $rows = $this->generateAndList();
        $response = $this->api->post('/redirects/suggestions/' . $rows['/blog-pots']['id'] . '/accept', ['target' => '/kontakt', 'status' => 307]);
        self::assertSame(201, $response->status, $response->describe());
        self::assertSame(['/kontakt', 307], [$response->data()['rule']['target'], $response->data()['rule']['status']]);
        $this->assertRedirect($this->get('/blog-pots'), 307, '/kontakt');

        $gone = $this->api->post('/redirects/suggestions/' . $rows['/typograpy']['id'] . '/accept', ['status' => 410]);
        self::assertSame([410, ''], [$gone->data()['rule']['status'], $gone->data()['rule']['target']]);
        self::assertSame(410, $this->get('/typograpy')->status);
    }

    public function testAcceptUsesTheDefaultStatusWhenNoneIsGiven(): void
    {
        $this->site()->writeSystemConfig(['pages' => ['redirect_default_code' => 308]]);
        $rows = $this->generateAndList();
        $response = $this->api->post('/redirects/suggestions/' . $rows['/typograpy']['id'] . '/accept');
        self::assertSame(308, $response->data()['rule']['status']);
    }

    public function testAcceptingTwiceIs409AndCreatesNoSecondRule(): void
    {
        $rows = $this->generateAndList();
        $id = (string) $rows['/typograpy']['id'];
        self::assertSame(201, $this->api->post('/redirects/suggestions/' . $id . '/accept')->status);
        $this->assertProblem($this->api->post('/redirects/suggestions/' . $id . '/accept'), 409);
        self::assertCount(1, $this->site()->repository()->all());
    }

    public function testAcceptIsValidatedLikeARule(): void
    {
        $rows = $this->generateAndList();
        $id = (string) $rows['/typograpy']['id'];
        $bad = $this->api->post('/redirects/suggestions/' . $id . '/accept', ['target' => '//evil.example']);
        $this->assertProblem($bad, 422);
        self::assertSame('target_protocol_relative', $bad->errors()[0]['code']);
        $this->assertProblem($this->api->post('/redirects/suggestions/' . $id . '/accept', ['status' => 999]), 422);
        self::assertSame([], $this->site()->repository()->all());
        self::assertSame('open', $this->api->get('/redirects/suggestions', ['status' => 'all', 'min_score' => 0.6])->data()[1]['status'], 'a refused accept leaves the suggestion open');
    }

    public function testRejectMarksTheSuggestionAndGenerateDoesNotBringItBack(): void
    {
        $rows = $this->generateAndList();
        $id = (string) $rows['/typograpy']['id'];
        $response = $this->api->post('/redirects/suggestions/' . $id . '/reject');
        self::assertSame(200, $response->status, $response->describe());
        self::assertSame(['rejected', '/typograpy'], [$response->data()['status'], $response->data()['path']]);
        self::assertNotNull($response->data()['decided_at']);

        $again = $this->api->post('/redirects/suggestions/generate')->data();
        self::assertSame([1, 0], [$again['rejected_before'], $again['created']]);
        self::assertNotContains('/typograpy', array_column($this->api->get('/redirects/suggestions')->data(), 'path'));
        $this->assertProblem($this->api->post('/redirects/suggestions/' . $id . '/accept'), 409);
        self::assertSame([], $this->site()->repository()->all());
    }

    public function testRejectAfterAcceptIs409(): void
    {
        $rows = $this->generateAndList();
        $id = (string) $rows['/typograpy']['id'];
        $this->api->post('/redirects/suggestions/' . $id . '/accept');
        $this->assertProblem($this->api->post('/redirects/suggestions/' . $id . '/reject'), 409);
    }

    public function testUnknownSuggestionIs404(): void
    {
        $this->assertProblem($this->api->post('/redirects/suggestions/snope/accept'), 404);
        $this->assertProblem($this->api->post('/redirects/suggestions/snope/reject'), 404);
    }

    public function testBulkAcceptDryRunPreviewsWithoutChangingAnything(): void
    {
        $rows = $this->generateAndList();
        $response = $this->api->post('/redirects/suggestions/bulk-accept', ['min_score' => 0.6, 'dry_run' => true]);
        self::assertSame(200, $response->status, $response->describe());
        $data = $response->data();
        self::assertSame([0.6, 2], [$data['min_score'], $data['count']]);
        self::assertSame(['/blog/blog-post', '/typograpy'], array_column($data['rows'], 'path'));
        self::assertArrayNotHasKey('rules', $data);
        self::assertSame([], $this->site()->repository()->all());
        self::assertSame(['open', 'open', 'open'], array_column($this->api->get('/redirects/suggestions')->data(), 'status'));
        self::assertSame($rows['/typograpy']['id'], $data['rows'][1]['id']);
    }

    public function testBulkAcceptCreatesRulesForSuggestionsAboveTheScore(): void
    {
        $this->generateAndList();
        $response = $this->api->post('/redirects/suggestions/bulk-accept', ['min_score' => 0.6]);
        self::assertSame(200, $response->status, $response->describe());
        $data = $response->data();
        self::assertSame(2, $data['count']);
        self::assertSame(['accepted', 'accepted'], array_column($data['rows'], 'status'));
        self::assertEqualsCanonicalizing(['/blog/blog-post', '/typograpy'], array_column($data['rules'], 'source'));
        self::assertSame(['suggestion', 'suggestion'], array_column($data['rules'], 'origin'));
        self::assertSame([], $data['skipped']);

        self::assertCount(2, $this->site()->repository()->all());
        $this->assertRedirect($this->get('/typograpy'), 302, '/typography', 'the default status of the site is used');
        $this->assertRedirect($this->get('/blog/blog-post'), 302, '/blog-post');
        self::assertNotContains('/blog-pots', array_column($this->api->get('/redirects/rules')->data(), 'source'), 'below the score, still open');
        self::assertSame(['/blog-pots'], array_column($this->api->get('/redirects/suggestions')->data(), 'path'));
    }

    public function testBulkAcceptDefaultsToTheConfiguredScoreAndTakesIds(): void
    {
        $rows = $this->generateAndList();
        $preview = $this->api->post('/redirects/suggestions/bulk-accept', ['dry_run' => true])->data();
        self::assertSame(0.9, $preview['min_score'], 'suggestions.bulk_accept_score');
        self::assertSame(['/blog/blog-post'], array_column($preview['rows'], 'path'));

        $only = $this->api->post('/redirects/suggestions/bulk-accept', ['min_score' => 0.5, 'ids' => [$rows['/blog-pots']['id']]])->data();
        self::assertSame(1, $only['count']);
        self::assertSame('/blog-pots', $only['rules'][0]['source']);
        self::assertCount(1, $this->site()->repository()->all());
    }

    public function testBulkAcceptSkipsSuggestionsThatWouldBeInvalid(): void
    {
        $rows = $this->generateAndList();
        // A rule that appears after the suggestions were made turns /typograpy -> /typography into a loop.
        $this->rules([['id' => 'back', 'source' => '/typography', 'target' => '/typograpy', 'status' => 301]]);
        $data = $this->api->post('/redirects/suggestions/bulk-accept', ['min_score' => 0.6])->data();
        self::assertSame(1, $data['count']);
        self::assertSame(['/blog/blog-post'], array_column($data['rules'], 'source'));
        self::assertSame($rows['/typograpy']['id'], $data['skipped'][0]['id']);
        self::assertSame('error', $data['skipped'][0]['issues'][0]['severity']);
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function badBulkBodies(): array
    {
        return [
            'score not a number' => [['min_score' => 'high']],
            'score above 1' => [['min_score' => 1.5]],
            'score below 0' => [['min_score' => -0.1]],
        ];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('badBulkBodies')]
    public function testBulkAcceptRefusesBadScores(array $body): void
    {
        $response = $this->api->post('/redirects/suggestions/bulk-accept', $body);
        $this->assertProblem($response, 422);
        self::assertSame('min_score', $response->errors()[0]['field']);
    }
}
