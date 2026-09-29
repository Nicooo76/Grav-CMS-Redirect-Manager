<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Integration\Support;

/**
 * Logs in through the front-end login form of a TestSite (Login plugin, /login) and returns the session cookie,
 * the way a browser would hold it. The account needs the `site.login` permission. Returns null when the login
 * does not work, so a test can skip instead of failing on an unrelated setup problem. Nothing is printed.
 */
final class SessionLogin
{
    /**
     * @return string|null the value for a `Cookie` header ("name=value"), or null when the login failed
     */
    public static function cookie(TestSite $site, string $username, string $password): ?string
    {
        $form = self::request($site, null, null);
        $cookie = self::sessionCookie($form['headers']);
        if ($cookie === null || preg_match('/name="login-form-nonce" value="([0-9a-f]+)"/', $form['body'], $nonce) !== 1) {
            return null;
        }

        $login = self::request($site, $cookie, [
            'username' => $username,
            'password' => $password,
            'login-form-nonce' => $nonce[1],
            'task' => 'login.login',
        ]);
        // A successful login regenerates the session id and redirects (303); a refused one shows the form again.
        if ($login['status'] !== 303) {
            return null;
        }

        return self::sessionCookie($login['headers']) ?? $cookie;
    }

    /**
     * @param array<string, string>|null $post
     *
     * @return array{status: int, headers: string, body: string}
     */
    private static function request(TestSite $site, ?string $cookie, ?array $post): array
    {
        $ch = curl_init($site->url('/login'));
        if ($ch === false) {
            return ['status' => 0, 'headers' => '', 'body' => ''];
        }
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 60, CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1]);
        if ($cookie !== null) {
            curl_setopt($ch, CURLOPT_COOKIE, $cookie);
        }
        if ($post !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        }
        $raw = curl_exec($ch);
        if (!is_string($raw)) {
            return ['status' => 0, 'headers' => '', 'body' => ''];
        }
        $size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);

        return ['status' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'headers' => substr($raw, 0, $size), 'body' => substr($raw, $size)];
    }

    private static function sessionCookie(string $headers): ?string
    {
        return preg_match('/^set-cookie:\s*(grav-site-[^=]+=[^;\s]+)/mi', $headers, $m) === 1 ? $m[1] : null;
    }
}
