<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\App;

use Grav\Plugin\RedirectManager\App\Exception\InvalidInputException;
use Grav\Plugin\RedirectManager\App\Exception\ResourceNotFoundException;
use Grav\Plugin\RedirectManager\App\Exception\RevisionConflictException;
use Grav\Plugin\RedirectManager\App\Exception\RuleValidationException;
use Grav\Plugin\RedirectManager\App\SiteContext;
use Grav\Plugin\RedirectManager\App\SuggestionService;
use Grav\Plugin\RedirectManager\Domain\Rule;
use Grav\Plugin\RedirectManager\NotFound\NotFoundEntry;
use Grav\Plugin\RedirectManager\NotFound\UserAgentClass;
use Grav\Plugin\RedirectManager\Suggest\Suggestion;
use Grav\Plugin\RedirectManager\Suggest\SuggestionReason;
use Grav\Plugin\RedirectManager\Suggest\SuggestionStore;
use Grav\Plugin\RedirectManager\Tests\Unit\App\Support\AppTestCase;
use Grav\Plugin\RedirectManager\Tests\Unit\Suggest\PageTreeFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

#[CoversClass(SuggestionService::class)]
#[Group('app')]
final class SuggestionServiceTest extends AppTestCase
{
    /** 0.783 */
    private const TYPO = '/blog/grav-tips-and-trick';
    /** 0.74 */
    private const TYPO_2 = '/products/widget-pr';
    /** 0.558 */
    private const TYPO_3 = '/blog/my-pots';

    protected function setUp(): void
    {
        parent::setUp();
        $this->useSite();
    }

    /**
     * The app over the page fixture (bilingual: en default, de). Rebuild after seeding rules: the matcher is
     * created once per app.
     *
     * @param array<string, mixed> $config
     */
    private function useSite(array $config = []): void
    {
        $this->app = $this->makeApp(
            $config,
            PageTreeFixture::pages(),
            new SiteContext(baseUrl: 'http://localhost:8080', languages: ['en', 'de'], defaultLanguage: 'en'),
        );
    }

    private function svc(): SuggestionService
    {
        return $this->app->suggestions();
    }

    private function store(): SuggestionStore
    {
        return $this->app->services()->suggestionStore();
    }

    private function hit(string $path, string $when = '-1 hour', UserAgentClass $class = UserAgentClass::Browser, ?string $language = null): void
    {
        $this->app->services()->logStore()->append(new NotFoundEntry(
            $this->clock->now()->modify($when),
            $path,
            '',
            '',
            'UA/1.0',
            $class,
            null,
            $language,
            'example.org',
            'GET',
        ));
    }

    /**
     * Stores an open suggestion directly, bypassing the page index.
     *
     * @return string the record id
     */
    private function record(string $path, string $target, float $score, string $source = SuggestionStore::SOURCE_NOT_FOUND): string
    {
        $record = $this->store()->upsertOpen($path, new Suggestion($target, $score, SuggestionReason::SimilarRoute, 'Title of ' . $target), $source);
        self::assertNotNull($record);

        return $record['id'];
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<string>
     */
    private static function column(array $rows, string $key): array
    {
        return array_map(static fn (array $r): string => (string) $r[$key], $rows);
    }

    /**
     * @return list<string> names of the fired events, without the rule-saved ones
     */
    private function suggestionEvents(): array
    {
        return array_values(array_filter($this->eventNames(), static fn (string $n): bool => $n === 'onSuggestionCreated'));
    }

    // ================================================================ suggest

    public function testSuggestReturnsLiveSuggestionsForAPath(): void
    {
        $result = $this->svc()->suggest(self::TYPO);

        self::assertSame('/blog/grav-tips-and-tricks', $result[0]['target']);
        self::assertSame(0.783, $result[0]['score']);
        self::assertSame('similar_route', $result[0]['reason']);
        self::assertSame('Grav tips and tricks', $result[0]['page_title']);
        self::assertArrayHasKey('details', $result[0]);
        self::assertSame([], $this->store()->all(), 'a live suggestion is not stored');
    }

    public function testSuggestClampsTheLimit(): void
    {
        self::assertCount(1, $this->svc()->suggest('/old/faq', null, 0));
        self::assertCount(1, $this->svc()->suggest('/old/faq', null, -3));
        self::assertCount(2, $this->svc()->suggest('/old/faq', null, 2));
        self::assertLessThanOrEqual(20, count($this->svc()->suggest('/old/faq', null, 500)));
    }

    public function testSuggestUsesTheLanguageOfThePath(): void
    {
        $result = $this->svc()->suggest('/de/ueber-uns-alt');

        self::assertSame('/ueber-uns', $result[0]['target']);
    }

    public function testSuggestTakesALanguageParameter(): void
    {
        $de = $this->svc()->suggest('/blog/mein-beitrag-alt', ' DE ');

        self::assertSame('/blog/mein-beitrag', $de[0]['target']);
        // a blank language counts as none
        self::assertNotSame([], $this->svc()->suggest(self::TYPO, '  '));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function emptyPaths(): iterable
    {
        yield 'empty' => [''];
        yield 'blank' => ['   '];
    }

    #[DataProvider('emptyPaths')]
    public function testSuggestNeedsAPath(string $path): void
    {
        try {
            $this->svc()->suggest($path);
            self::fail('Expected InvalidInputException.');
        } catch (InvalidInputException $e) {
            self::assertSame('path', $e->field);
            self::assertSame('required', $e->errorCode);
        }
    }

    // ================================================================ generate

    public function testGenerateStoresASuggestionForAPathWithASimilarPage(): void
    {
        $this->hit(self::TYPO);

        $counts = $this->svc()->generate();

        self::assertSame(['paths' => 1, 'suggested' => 1, 'created' => 1, 'improved' => 0, 'rejected_before' => 0, 'no_suggestion' => 0, 'skipped_with_rule' => 0], $counts);
        $rows = $this->svc()->list();
        self::assertCount(1, $rows);
        self::assertSame(self::TYPO, $rows[0]['path']);
        self::assertSame('/blog/grav-tips-and-tricks', $rows[0]['target']);
        self::assertSame(0.783, $rows[0]['score']);
        self::assertSame('similar_route', $rows[0]['reason']);
        self::assertSame('Grav tips and tricks', $rows[0]['page_title']);
        self::assertSame(1, $rows[0]['hits']);
        self::assertSame('open', $rows[0]['status']);
        self::assertSame('404', $rows[0]['source']);
        self::assertSame('2026-09-29T10:00:00+00:00', $rows[0]['created_at']);
        self::assertNull($rows[0]['decided_at']);
        self::assertStringStartsWith('s', $rows[0]['id']);
    }

    public function testGenerateFiresOnSuggestionCreatedOnce(): void
    {
        $this->hit(self::TYPO);

        $this->svc()->generate();

        self::assertSame(['onSuggestionCreated'], $this->eventNames());
        $suggestion = $this->events[0]['payload']['suggestion'];
        self::assertSame(self::TYPO, $suggestion['path']);
        self::assertSame('/blog/grav-tips-and-tricks', $suggestion['target']);
        self::assertSame(0.783, $suggestion['score']);
    }

    public function testASecondRunCreatesNothingAndFiresNothing(): void
    {
        $this->hit(self::TYPO);
        $this->svc()->generate();
        $before = $this->store()->all();

        $counts = $this->svc()->generate();

        self::assertSame(1, $counts['paths']);
        self::assertSame(0, $counts['suggested']);
        self::assertSame(0, $counts['created']);
        self::assertSame(0, $counts['improved']);
        self::assertSame($before, $this->store()->all());
        self::assertCount(1, $this->suggestionEvents());
    }

    public function testAStrongerLaterCandidateCountsAsImproved(): void
    {
        $this->record(self::TYPO, '/blog', 0.51);
        $this->hit(self::TYPO);

        $counts = $this->svc()->generate();

        self::assertSame(1, $counts['improved']);
        self::assertSame(0, $counts['created']);
        self::assertSame(1, $counts['suggested']);
        $rows = $this->svc()->list();
        self::assertCount(1, $rows);
        self::assertSame('/blog/grav-tips-and-tricks', $rows[0]['target']);
        self::assertSame(0.783, $rows[0]['score']);
        self::assertSame(['onSuggestionCreated'], $this->eventNames());
        self::assertSame(0.783, $this->events[0]['payload']['suggestion']['score']);
    }

    public function testAWeakerLaterCandidateChangesNothing(): void
    {
        $this->record(self::TYPO, '/blog', 0.95);
        $this->hit(self::TYPO);

        $counts = $this->svc()->generate();

        self::assertSame(0, $counts['suggested']);
        self::assertSame(0.95, $this->svc()->list()[0]['score']);
        self::assertSame([], $this->events);
    }

    public function testPathsWithARuleAreSkipped(): void
    {
        $this->seedRules([['id' => 'covered', 'source' => self::TYPO, 'target' => '/about']]);
        $this->useSite();
        $this->hit(self::TYPO);
        $this->hit(self::TYPO_2);

        $counts = $this->svc()->generate();

        self::assertSame(2, $counts['paths']);
        self::assertSame(1, $counts['skipped_with_rule']);
        self::assertSame(1, $counts['created']);
        self::assertSame([self::TYPO_2], self::column($this->svc()->list(), 'path'));
    }

    public function testALanguageRuleCoversOnlyPathsOfThatLanguage(): void
    {
        $this->seedRules([['id' => 'de-rule', 'source' => '/contact-us', 'target' => '/kontakt', 'conditions' => ['languages' => ['de']]]]);
        $this->useSite();
        $this->hit('/de/contact-us');
        $this->hit('/en/contact-us');

        $counts = $this->svc()->generate();

        self::assertSame(1, $counts['skipped_with_rule']);
        self::assertSame(['/en/contact-us'], self::column($this->svc()->list(), 'path'));
    }

    public function testADisabledRuleCoversNothing(): void
    {
        $this->seedRules([['id' => 'off', 'source' => self::TYPO, 'target' => '/about', 'enabled' => false]]);
        $this->useSite();
        $this->hit(self::TYPO);

        $counts = $this->svc()->generate();

        self::assertSame(0, $counts['skipped_with_rule']);
        self::assertSame(1, $counts['created']);
    }

    public function testResolvedPathsAreSkippedUntilTheyGetANewHit(): void
    {
        $this->hit(self::TYPO, '-3 hours');
        $this->app->services()->resolvedPaths()->markResolved(self::TYPO, $this->clock->now()->modify('-2 hours'));

        self::assertSame(0, $this->svc()->generate()['paths']);
        self::assertSame([], $this->store()->all());

        $this->hit(self::TYPO, '-1 hour');

        self::assertSame(1, $this->svc()->generate()['created']);
    }

    public function testBotEntriesAreSkipped(): void
    {
        $this->hit(self::TYPO, class: UserAgentClass::Bot);

        self::assertSame(0, $this->svc()->generate()['paths']);

        $this->hit(self::TYPO_2, class: UserAgentClass::Unknown);
        self::assertSame(1, $this->svc()->generate()['paths']);
    }

    public function testDaysLimitTheLookBack(): void
    {
        $this->hit(self::TYPO, '-40 days');
        $this->hit(self::TYPO_2, '-2 days');

        self::assertSame(1, $this->svc()->generate()['paths']); // default: 30 days
        self::assertSame(2, $this->svc()->generate(60)['paths']);
        self::assertSame(0, $this->svc()->generate(1)['paths']);
    }

    public function testDaysOutOfRangeAreInvalid(): void
    {
        foreach ([0, -1, 367] as $days) {
            try {
                $this->svc()->generate($days);
                self::fail('Expected InvalidInputException for ' . $days);
            } catch (InvalidInputException $e) {
                self::assertSame('days', $e->field);
            }
        }
        self::assertSame(0, $this->svc()->generate(366)['paths']);
        self::assertSame(0, $this->svc()->generate(1)['paths']);
    }

    public function testPathsWithoutAGoodEnoughPageAreCounted(): void
    {
        $this->hit('/zzzz/qqqq');
        $this->hit(self::TYPO);

        $counts = $this->svc()->generate();

        self::assertSame(2, $counts['paths']);
        self::assertSame(1, $counts['no_suggestion']);
        self::assertSame(1, $counts['created']);
    }

    public function testTheMinimumScoreIsConfigurable(): void
    {
        $this->hit(self::TYPO);   // 0.783
        $this->hit(self::TYPO_3); // 0.558

        $this->useSite(['suggestions' => ['min_score' => 0.7]]);
        $counts = $this->svc()->generate();

        self::assertSame(1, $counts['created']);
        self::assertSame(1, $counts['no_suggestion']);
        self::assertSame([self::TYPO], self::column($this->svc()->list(), 'path'));
    }

    public function testARejectedSuggestionIsNotProposedAgain(): void
    {
        $this->hit(self::TYPO);
        $this->svc()->generate();
        $id = $this->svc()->list()[0]['id'];
        $this->svc()->reject($id);

        $counts = $this->svc()->generate();

        self::assertSame(1, $counts['rejected_before']);
        self::assertSame(0, $counts['created']);
        self::assertSame([], $this->svc()->list());
        self::assertCount(1, $this->suggestionEvents());
    }

    public function testTheLanguageOfALoggedPathGuidesTheSuggestion(): void
    {
        $this->hit('/blog/mein-beitrag-alt', language: 'de');

        $this->svc()->generate();

        self::assertSame('/blog/mein-beitrag', $this->svc()->list()[0]['target']);
    }

    public function testDryRunStoresNothingAndReturnsAPreview(): void
    {
        $this->hit(self::TYPO);
        $this->hit(self::TYPO_2);

        $counts = $this->svc()->generate(null, true);

        self::assertSame(2, $counts['created']);
        self::assertSame(2, $counts['suggested']);
        self::assertSame([], $this->store()->all());
        self::assertSame([], $this->events);
        self::assertCount(2, $counts['preview']);
        $preview = [];
        foreach ($counts['preview'] as $row) {
            $preview[$row['path']] = $row;
        }
        self::assertSame(['path', 'target', 'score', 'reason', 'page_title'], array_keys($preview[self::TYPO]));
        self::assertSame('/blog/grav-tips-and-tricks', $preview[self::TYPO]['target']);
        self::assertSame(0.783, $preview[self::TYPO]['score']);
        self::assertSame('similar_route', $preview[self::TYPO]['reason']);
    }

    public function testADryRunPreviewLeavesOutWhatIsAlreadyStoredAndRejected(): void
    {
        $this->hit(self::TYPO);
        $this->hit(self::TYPO_2);
        $this->hit(self::TYPO_3);
        $this->record(self::TYPO, '/blog/grav-tips-and-tricks', 0.783); // same strength: nothing to do
        $this->record(self::TYPO_3, '/blog/my-post', 0.558);
        $rejected = $this->store()->find($this->record(self::TYPO_2, '/products/widget-pro', 0.5));
        self::assertNotNull($rejected);
        $this->store()->reject($rejected['id']);

        $counts = $this->svc()->generate(null, true);

        self::assertSame(1, $counts['rejected_before']);
        self::assertSame(0, $counts['created']);
        self::assertSame([], $counts['preview']);
    }

    public function testADryRunPreviewShowsImprovements(): void
    {
        $this->record(self::TYPO, '/blog', 0.51);
        $this->hit(self::TYPO);

        $counts = $this->svc()->generate(null, true);

        self::assertSame(1, $counts['improved']);
        self::assertSame('/blog/grav-tips-and-tricks', $counts['preview'][0]['target']);
        self::assertSame('/blog', $this->store()->all()[0]['target'], 'a dry run does not change the record');
        self::assertSame([], $this->events);
    }

    public function testARealRunHasNoPreviewKey(): void
    {
        self::assertArrayNotHasKey('preview', $this->svc()->generate());
    }

    // ================================================================ suggestForPaths

    public function testSuggestForPathsStoresSuggestionsWithTheGivenSource(): void
    {
        $result = $this->svc()->suggestForPaths([self::TYPO_3, self::TYPO_3, '/zzzz/qqqq'], SuggestionStore::SOURCE_SITEMAP);

        self::assertSame(['paths' => 2, 'suggested' => 1], $result);
        $rows = $this->svc()->list();
        self::assertSame('sitemap', $rows[0]['source']);
        self::assertSame('/blog/my-post', $rows[0]['target']);
        self::assertCount(1, $this->suggestionEvents());
    }

    public function testSuggestForPathsSkipsPathsWithARule(): void
    {
        $this->seedRules([['id' => 'covered', 'source' => self::TYPO_3, 'target' => '/about']]);
        $this->useSite();

        $result = $this->svc()->suggestForPaths([self::TYPO_3], SuggestionStore::SOURCE_CRAWLER);

        self::assertSame(['paths' => 1, 'suggested' => 0], $result);
        self::assertSame([], $this->store()->all());
    }

    public function testSuggestForPathsWorksWithPathsOfAnyLanguage(): void
    {
        $result = $this->svc()->suggestForPaths(['/de/ueber-uns-alt'], SuggestionStore::SOURCE_SITEMAP);

        self::assertSame(1, $result['suggested']);
        self::assertSame('/ueber-uns', $this->svc()->list()[0]['target']);
    }

    // ================================================================ list

    private function seedRecords(): void
    {
        $this->record('/old-a', '/blog', 0.9);
        $this->record('/old-b', '/about', 0.6, SuggestionStore::SOURCE_SITEMAP);
        $this->record('/old-c', '/contact', 0.6);
        $accepted = $this->record('/old-d', '/team', 0.7);
        $rejected = $this->record('/old-e', '/news', 0.8);
        $this->store()->accept($accepted);
        $this->store()->reject($rejected);
    }

    public function testListDefaultsToOpenSuggestionsBestScoreFirst(): void
    {
        $this->seedRecords();

        $rows = $this->svc()->list();

        self::assertSame(['/old-a', '/old-b', '/old-c'], self::column($rows, 'path')); // equal scores: by path
        self::assertSame(['open'], array_values(array_unique(self::column($rows, 'status'))));
    }

    public function testListFiltersByStatus(): void
    {
        $this->seedRecords();

        self::assertSame(['/old-d'], self::column($this->svc()->list(['status' => 'accepted']), 'path'));
        self::assertSame(['/old-e'], self::column($this->svc()->list(['status' => 'rejected']), 'path'));
        self::assertSame(['/old-a', '/old-e', '/old-d', '/old-b', '/old-c'], self::column($this->svc()->list(['status' => 'all']), 'path'));
        self::assertNotNull($this->svc()->list(['status' => 'accepted'])[0]['decided_at']);
    }

    public function testListFiltersByMinimumScore(): void
    {
        $this->seedRecords();

        self::assertSame(['/old-a'], self::column($this->svc()->list(['min_score' => '0.7']), 'path'));
        self::assertSame(['/old-a', '/old-b', '/old-c'], self::column($this->svc()->list(['min_score' => 0.6]), 'path'));
        self::assertSame([], $this->svc()->list(['min_score' => '0.95']));
        self::assertCount(3, $this->svc()->list(['min_score' => '']));
    }

    public function testListFiltersBySource(): void
    {
        $this->seedRecords();

        self::assertSame(['/old-b'], self::column($this->svc()->list(['source' => 'sitemap']), 'path'));
        self::assertSame(['/old-a', '/old-c'], self::column($this->svc()->list(['source' => '404']), 'path'));
    }

    public function testListRefusesUnknownValues(): void
    {
        foreach ([['status' => 'pending'], ['min_score' => 'high']] as $query) {
            try {
                $this->svc()->list($query);
                self::fail('Expected InvalidInputException.');
            } catch (InvalidInputException $e) {
                self::assertSame(array_key_first($query), $e->field);
            }
        }
    }

    public function testListOfNothingIsEmpty(): void
    {
        self::assertSame([], $this->svc()->list());
    }

    public function testListShowsTheHitsOfTheLastNinetyDays(): void
    {
        $this->record('/old-a', '/blog', 0.9);
        $this->record('/old-b', '/about', 0.8);
        $this->record('/old-c', '/contact', 0.7);
        $this->hit('/old-a');
        $this->hit('/old-a', '-10 days');
        $this->hit('/old-a', '-60 days');
        $this->hit('/old-a', '-120 days');
        $this->hit('/old-b', '-5 days', UserAgentClass::Bot);

        $hits = [];
        foreach ($this->svc()->list() as $row) {
            $hits[$row['path']] = $row['hits'];
        }

        self::assertSame(['/old-a' => 3, '/old-b' => 1, '/old-c' => 0], $hits);
    }

    // ================================================================ accept

    public function testAcceptCreatesARuleWithOriginSuggestion(): void
    {
        $id = $this->record('/old-a', '/blog', 0.9);

        $result = $this->svc()->accept($id);

        $rule = $result['rule'];
        self::assertSame('/old-a', $rule['source']);
        self::assertSame('/blog', $rule['target']);
        self::assertSame('suggestion', $rule['origin']);
        self::assertSame('page', $rule['target_type']);
        self::assertSame(301, $rule['status']);
        self::assertSame('exact', $rule['match_type']);
        self::assertArrayHasKey('stats', $rule);
        self::assertArrayHasKey('badges', $rule);
        self::assertArrayHasKey('issues', $rule);
        self::assertSame($id, $result['suggestion']['id']);
        self::assertSame('accepted', $result['suggestion']['status']);
        self::assertNotNull($result['suggestion']['decided_at']);

        $stored = $this->app->services()->repository()->all();
        self::assertCount(1, $stored);
        self::assertSame($rule['id'], $stored[0]->id);
        self::assertSame(['onRedirectRuleSaved'], $this->eventNames());
        self::assertSame('create', $this->events[0]['payload']['action']);
        self::assertSame([], $this->svc()->list());
    }

    public function testAcceptUsesTheConfiguredDefaultStatus(): void
    {
        $this->useSite(['redirects' => ['default_status' => 302]]);
        $id = $this->record('/old-a', '/blog', 0.9);

        self::assertSame(302, $this->svc()->accept($id)['rule']['status']);
    }

    public function testAcceptTurnsALanguagePrefixIntoACondition(): void
    {
        $id = $this->record('/de/alt', '/kontakt', 0.9);

        $rule = $this->svc()->accept($id)['rule'];

        self::assertSame('/alt', $rule['source']);
        self::assertSame(['de'], $rule['conditions']['languages']);
        self::assertSame('/kontakt', $rule['target']);
    }

    public function testAcceptedLanguageRulesOnlyApplyToThatLanguage(): void
    {
        $id = $this->record('/de/alt', '/kontakt', 0.9);
        $this->svc()->accept($id);
        $this->useSite();

        $tester = $this->app->tester();

        self::assertSame(200, $tester->test(['url' => '/de/alt'])['final']['status']);
        self::assertNull($tester->test(['url' => '/en/alt'])['result']);
    }

    public function testAcceptWithABodyTarget(): void
    {
        $id = $this->record('/old-a', '/blog', 0.9);

        $rule = $this->svc()->accept($id, ['target' => ' /products '])['rule'];

        self::assertSame('/products', $rule['target']);
        self::assertSame('page', $rule['target_type']);
    }

    public function testAcceptWithAnExternalBodyTargetSetsTheUrlType(): void
    {
        $this->useSite(['security' => ['allowed_hosts' => ['ext.example']]]);
        $id = $this->record('/old-a', '/blog', 0.9);

        $rule = $this->svc()->accept($id, ['target' => 'https://ext.example/landing'])['rule'];

        self::assertSame('url', $rule['target_type']);
        self::assertSame('https://ext.example/landing', $rule['target']);
    }

    public function testAcceptWithABodyStatus(): void
    {
        $id = $this->record('/old-a', '/blog', 0.9);

        self::assertSame(308, $this->svc()->accept($id, ['status' => 308])['rule']['status']);
    }

    public function testAcceptWithAGoneStatusDropsTheTarget(): void
    {
        $id = $this->record('/old-a', '/blog', 0.9);

        $rule = $this->svc()->accept($id, ['status' => 410])['rule'];

        self::assertSame(410, $rule['status']);
        self::assertSame('', $rule['target']);
    }

    public function testABlankBodyTargetKeepsTheSuggestedOne(): void
    {
        $id = $this->record('/old-a', '/blog', 0.9);

        self::assertSame('/blog', $this->svc()->accept($id, ['target' => '  '])['rule']['target']);
    }

    public function testAValidationFailureLeavesTheSuggestionOpen(): void
    {
        $this->seedRules([['id' => 'back', 'source' => '/blog', 'target' => '/old-a']]);
        $this->useSite();
        $id = $this->record('/old-a', '/blog', 0.9); // /old-a -> /blog -> /old-a

        try {
            $this->svc()->accept($id);
            self::fail('Expected RuleValidationException.');
        } catch (RuleValidationException $e) {
            self::assertSame('loop', $e->errors()[0]->code);
        }

        self::assertSame('open', $this->store()->find($id)['status'] ?? null);
        self::assertSame(['back'], array_map(static fn (Rule $r): string => $r->id, $this->app->services()->repository()->all()));
        self::assertSame([], $this->events);
        self::assertSame([$id], self::column($this->svc()->list(), 'id'));
    }

    public function testAnInvalidBodyStatusLeavesTheSuggestionOpen(): void
    {
        $id = $this->record('/old-a', '/blog', 0.9);

        try {
            $this->svc()->accept($id, ['status' => 999]);
            self::fail('Expected RuleValidationException.');
        } catch (RuleValidationException $e) {
            self::assertSame('status', $e->issues[0]->field);
        }

        self::assertSame('open', $this->store()->find($id)['status'] ?? null);
    }

    public function testAcceptingTwiceIsAConflict(): void
    {
        $id = $this->record('/old-a', '/blog', 0.9);
        $this->svc()->accept($id);

        $this->expectException(RevisionConflictException::class);
        $this->expectExceptionMessage('already accepted');
        try {
            $this->svc()->accept($id);
        } finally {
            self::assertCount(1, $this->app->services()->repository()->all());
        }
    }

    public function testAcceptingARejectedSuggestionIsAConflict(): void
    {
        $id = $this->record('/old-a', '/blog', 0.9);
        $this->svc()->reject($id);

        $this->expectException(RevisionConflictException::class);
        $this->expectExceptionMessage('already rejected');
        $this->svc()->accept($id);
    }

    public function testAcceptingAnUnknownSuggestionIsNotFound(): void
    {
        try {
            $this->svc()->accept('s-missing');
            self::fail('Expected ResourceNotFoundException.');
        } catch (ResourceNotFoundException $e) {
            self::assertSame('suggestion', $e->kind);
            self::assertSame('s-missing', $e->id);
        }
    }

    // ================================================================ reject

    public function testRejectMarksTheSuggestion(): void
    {
        $id = $this->record('/old-a', '/blog', 0.9);

        $row = $this->svc()->reject($id);

        self::assertSame($id, $row['id']);
        self::assertSame('rejected', $row['status']);
        self::assertNotNull($row['decided_at']);
        self::assertSame([], $this->svc()->list());
        self::assertSame([$id], self::column($this->svc()->list(['status' => 'rejected']), 'id'));
        self::assertSame([], $this->app->services()->repository()->all());
    }

    public function testRejectIsRepeatable(): void
    {
        $id = $this->record('/old-a', '/blog', 0.9);
        $this->svc()->reject($id);

        self::assertSame('rejected', $this->svc()->reject($id)['status']);
    }

    public function testRejectingAnAcceptedSuggestionIsAConflict(): void
    {
        $id = $this->record('/old-a', '/blog', 0.9);
        $this->svc()->accept($id);

        $this->expectException(RevisionConflictException::class);
        $this->svc()->reject($id);
    }

    public function testRejectingAnUnknownSuggestionIsNotFound(): void
    {
        $this->expectException(ResourceNotFoundException::class);
        $this->svc()->reject('s-missing');
    }

    // ================================================================ bulkAccept

    private function seedBulk(): void
    {
        $this->record('/old-a', '/blog', 0.95);
        $this->record('/old-b', '/about', 0.85);
        $this->record('/old-c', '/contact', 0.6);
    }

    public function testBulkAcceptDefaultsToTheConfiguredScore(): void
    {
        $this->seedBulk();

        $result = $this->svc()->bulkAccept();

        self::assertSame(0.9, $result['min_score']);
        self::assertSame(1, $result['count']);
        self::assertSame(['/old-a'], self::column($result['rows'], 'path'));

        $this->useSite(['suggestions' => ['bulk_accept_score' => 0.8]]);
        self::assertSame(1, $this->svc()->bulkAccept(dryRun: true)['count']); // /old-a is accepted already
        self::assertSame(0.8, $this->svc()->bulkAccept(dryRun: true)['min_score']);
    }

    public function testBulkAcceptDryRunPreviewsWithoutChangingAnything(): void
    {
        $this->seedBulk();

        $result = $this->svc()->bulkAccept(0.8, null, true);

        self::assertSame(0.8, $result['min_score']);
        self::assertSame(2, $result['count']);
        self::assertSame(['/old-a', '/old-b'], self::column($result['rows'], 'path'));
        self::assertArrayNotHasKey('rules', $result);
        self::assertSame([], $this->app->services()->repository()->all());
        self::assertCount(3, $this->svc()->list());
        self::assertSame([], $this->events);
    }

    public function testBulkAcceptCreatesRulesOnlyForSuggestionsAtOrAboveTheScore(): void
    {
        $this->seedBulk();

        $result = $this->svc()->bulkAccept(0.85);

        self::assertSame(2, $result['count']);
        self::assertSame(['accepted', 'accepted'], self::column($result['rows'], 'status'));
        self::assertSame([], $result['skipped']);
        self::assertSame(['/old-a', '/old-b'], self::column($result['rules'], 'source'));
        self::assertSame(['/blog', '/about'], self::column($result['rules'], 'target'));
        self::assertSame(['suggestion', 'suggestion'], self::column($result['rules'], 'origin'));
        self::assertSame(['page', 'page'], self::column($result['rules'], 'target_type'));
        self::assertEqualsCanonicalizing(['/old-a', '/old-b'], array_map(static fn (Rule $r): string => $r->source, $this->app->services()->repository()->all()));
        // what is left is exactly the weak one
        self::assertSame(['/old-c'], self::column($this->svc()->list(), 'path'));
        self::assertSame(['create', 'create'], array_map(static fn (array $e): string => $e['payload']['action'], $this->events));
    }

    public function testBulkAcceptWithAnIdFilter(): void
    {
        $this->seedBulk();
        $idB = $this->svc()->list()[1]['id'];

        $result = $this->svc()->bulkAccept(0.5, [$idB, 'unknown']);

        self::assertSame(1, $result['count']);
        self::assertSame(['/old-b'], self::column($result['rows'], 'path'));
        self::assertCount(2, $this->svc()->list());
    }

    public function testBulkAcceptIncludesTheBoundaryScore(): void
    {
        $this->seedBulk();

        self::assertSame(3, $this->svc()->bulkAccept(0.6, null, true)['count']);
        self::assertSame(3, $this->svc()->bulkAccept(0.0, null, true)['count']);
        self::assertSame(0, $this->svc()->bulkAccept(1.0, null, true)['count']);
    }

    public function testBulkAcceptSkipsSuggestionsWhoseRuleIsInvalidAndKeepsThemOpen(): void
    {
        $this->seedRules([['id' => 'back', 'source' => '/about', 'target' => '/old-b']]);
        $this->useSite();
        $this->seedBulk(); // /old-b -> /about would loop with the stored rule

        $result = $this->svc()->bulkAccept(0.5);

        self::assertSame(2, $result['count']);
        self::assertSame(['/old-a', '/old-c'], self::column($result['rules'], 'source'));
        self::assertCount(1, $result['skipped']);
        self::assertSame($this->idOfPath('/old-b'), $result['skipped'][0]['id']);
        self::assertSame('loop', $result['skipped'][0]['issues'][0]['code']);
        self::assertSame('error', $result['skipped'][0]['issues'][0]['severity']);
        self::assertSame(['/old-b'], self::column($this->svc()->list(), 'path'));
        self::assertCount(3, $this->app->services()->repository()->all());
    }

    private function idOfPath(string $path): string
    {
        foreach ($this->store()->all() as $record) {
            if ($record['path'] === $path) {
                return $record['id'];
            }
        }
        self::fail('No record for ' . $path);
    }

    public function testBulkAcceptChecksTheRulesAgainstEachOther(): void
    {
        $this->record('/loop-a', '/loop-b', 0.9);
        $this->record('/loop-b', '/loop-a', 0.8);

        $result = $this->svc()->bulkAccept(0.5);

        self::assertSame(1, $result['count']);
        self::assertCount(1, $result['skipped']);
        self::assertSame(['/loop-b'], self::column($this->svc()->list(), 'path'));
    }

    public function testBulkAcceptOfNothingIsEmpty(): void
    {
        $result = $this->svc()->bulkAccept(0.5);

        self::assertSame(0, $result['count']);
        self::assertSame([], $result['rows']);
        self::assertSame([], $result['rules']);
        self::assertSame([], $result['skipped']);
        self::assertSame([], $this->events);
    }

    public function testBulkAcceptTurnsLanguagePrefixesIntoConditions(): void
    {
        $this->record('/de/alt', '/kontakt', 0.9);

        $result = $this->svc()->bulkAccept(0.5);

        self::assertSame(['/alt'], self::column($result['rules'], 'source'));
        self::assertSame(['de'], $result['rules'][0]['conditions']['languages']);
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function badScores(): iterable
    {
        yield 'below zero' => [-0.1];
        yield 'above one' => [1.5];
    }

    #[DataProvider('badScores')]
    public function testBulkAcceptScoreOutOfRangeIsInvalid(float $score): void
    {
        $this->seedBulk();

        try {
            $this->svc()->bulkAccept($score);
            self::fail('Expected InvalidInputException.');
        } catch (InvalidInputException $e) {
            self::assertSame('min_score', $e->field);
        }

        self::assertCount(3, $this->svc()->list());
    }
}
