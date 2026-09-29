<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Integration\AutoRedirect;

use Grav\Plugin\RedirectManager\Domain\Rule;
use Grav\Plugin\RedirectManager\Domain\RuleSource;
use Grav\Plugin\RedirectManager\Tests\Integration\IntegrationTestCase;
use Grav\Plugin\RedirectManager\Tests\Integration\Support\ApiClient;
use Grav\Plugin\RedirectManager\Tests\Integration\Support\ApiResponse;

/**
 * Base class of the automatic-redirect tests: a temp Grav site with the API plugin, a super admin token (from
 * .grav/credentials.env, never printed) and helpers to edit pages through the REST API like Admin 2 does.
 *
 * Ports: the caller's RM_PORT_RANGE, 8300-8399 when none is set, so parallel runs and the dev site stay apart.
 */
abstract class AutoRedirectTestCase extends IntegrationTestCase
{
    protected ApiClient $api;

    public static function setUpBeforeClass(): void
    {
        putenv('RM_PORT_RANGE=' . (getenv('RM_PORT_RANGE') ?: '8300-8399'));
        parent::setUpBeforeClass();
        if (ApiClient::adminCredentials() === null) {
            self::markTestSkipped('No API test user. Run scripts/setup-test-site.sh first.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        [$user, $password] = ApiClient::adminCredentials() ?? ['', ''];
        $this->api = ApiClient::login($this->site(), $user, $password);
    }

    /**
     * Writes a page file (user/pages/<folder>/default[.lang].md).
     */
    protected function page(string $folder, string $title, string $header = '', ?string $language = null): void
    {
        $file = 'default' . ($language !== null ? '.' . $language : '') . '.md';
        $this->site()->writeFile('user/pages/' . $folder . '/' . $file, "---\ntitle: $title\n$header---\nBody of $title\n");
    }

    /**
     * @param array<string, mixed> $body
     */
    protected function patchPage(string $route, array $body, ?string $language = null): ApiResponse
    {
        $response = $this->api->request('PATCH', '/pages/' . ltrim($route, '/'), $body, $language !== null ? ['lang' => $language] : []);
        self::assertSame(200, $response->status, $response->describe());

        return $response;
    }

    protected function setSlug(string $route, ?string $slug, ?string $language = null): void
    {
        $this->patchPage($route, ['header' => ['slug' => $slug]], $language);
    }

    protected function movePage(string $route, string $parent, ?string $slug = null): ApiResponse
    {
        $body = ['parent' => $parent] + ($slug !== null ? ['slug' => $slug] : []);
        $response = $this->api->post('/pages/' . ltrim($route, '/') . '/move', $body);
        self::assertSame(200, $response->status, $response->describe());

        return $response;
    }

    protected function deletePage(string $route, ?string $language = null): ApiResponse
    {
        $response = $this->api->delete('/pages/' . ltrim($route, '/'), $language !== null ? ['lang' => $language] : []);
        self::assertSame(204, $response->status, $response->describe());

        return $response;
    }

    /**
     * @return list<Rule>
     */
    protected function storedRules(): array
    {
        return $this->site()->repository()->all();
    }

    /**
     * Auto rules as compact strings "source -> target [languages] status", sorted.
     *
     * @return list<string>
     */
    protected function autoRules(): array
    {
        $out = [];
        foreach ($this->storedRules() as $rule) {
            if ($rule->origin !== RuleSource::Auto) {
                continue;
            }
            $languages = $rule->conditions->languages;
            $out[] = sprintf(
                '%s -> %s%s%s',
                $rule->source,
                $rule->target === '' ? '(none)' : $rule->target,
                $languages === [] ? '' : ' [' . implode(',', $languages) . ']',
                $rule->status->value === 301 ? '' : ' ' . $rule->status->value,
            );
        }
        sort($out);

        return $out;
    }

    /** Multi-language system config (English default, German), URLs with language prefix. */
    protected function multilanguage(): void
    {
        $this->site()->writeSystemConfig(['languages' => ['supported' => ['en', 'de'], 'default_lang' => 'en', 'include_default_lang' => true]]);
    }
}
