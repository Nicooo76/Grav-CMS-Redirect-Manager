<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Integration\Support;

use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * Creates limited API users in a TestSite with the Login plugin's CLI (`bin/plugin login new-user`) and then
 * narrows the account's `access` map. Returns the generated password (only to log in, never print it).
 */
final class AccountFactory
{
    /**
     * @param array<string, mixed> $access the account's `access` map, e.g. ['api' => ['access' => true, 'redirects' => ['read' => true]]]
     *
     * @return string the password
     */
    public static function create(TestSite $site, string $username, array $access): string
    {
        $password = bin2hex(random_bytes(10)) . 'Aa1';
        $command = [
            TestSite::phpBinary(), 'bin/plugin', 'login', 'new-user', '-n',
            '-u', $username, '-p', $password, '-e', $username . '@example.invalid', '-P', 'a', '--admin-type=api',
            '-N', ucfirst($username), '-t', 'Test', '-s', 'enabled',
        ];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $site->dir);
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot run bin/plugin.');
        }
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        $code = proc_close($process);
        $file = $site->dir . '/user/accounts/' . $username . '.yaml';
        if ($code !== 0 || !is_file($file)) {
            throw new RuntimeException('new-user failed: ' . substr($out, 0, 500));
        }

        $account = Yaml::parseFile($file);
        $account = is_array($account) ? $account : [];
        $account['access'] = $access;
        file_put_contents($file, Yaml::dump($account, 6, 2));

        return $password;
    }
}
