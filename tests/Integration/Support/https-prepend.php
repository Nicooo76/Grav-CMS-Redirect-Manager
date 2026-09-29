<?php

declare(strict_types=1);

/**
 * auto_prepend_file for the test site of ProxyHeadersTest: `php -S` has no TLS, so a request that carries
 * `X-Test-Https: on` (or `off`) is served as if the web server had set the HTTPS variable, the way Apache and
 * nginx do behind a TLS listener. Requests without the header are untouched.
 */
if (getenv('RM_COVERAGE_DIR') !== false && is_file(dirname(__DIR__, 2) . '/Support/coverage-prepend.php')) {
    require_once dirname(__DIR__, 2) . '/Support/coverage-prepend.php';
}

$rmHttps = $_SERVER['HTTP_X_TEST_HTTPS'] ?? null;
if (is_string($rmHttps) && $rmHttps !== '') {
    $_SERVER['HTTPS'] = $rmHttps;
    if (strtolower($rmHttps) !== 'off') {
        $_SERVER['SERVER_PORT'] = '443';
        $_SERVER['REQUEST_SCHEME'] = 'https';
    }
}
unset($rmHttps);
