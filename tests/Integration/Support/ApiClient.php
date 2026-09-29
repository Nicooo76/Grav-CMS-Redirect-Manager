<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Integration\Support;

use RuntimeException;

/**
 * Minimal JSON client for the Grav API of a TestSite: JWT login, plain requests with any headers.
 * Credentials are never printed; failures name only the status.
 */
final class ApiClient
{
    /**
     * @param array<string, string> $headers sent with every request
     */
    public function __construct(private readonly TestSite $site, private readonly array $headers = [])
    {
    }

    /**
     * Logs in with username and password (POST /api/v1/auth/token) and returns a client that sends the JWT in X-API-Token.
     */
    public static function login(TestSite $site, string $username, string $password): self
    {
        $anonymous = new self($site);
        $response = $anonymous->post('/auth/token', ['username' => $username, 'password' => $password]);
        $data = $response->data();
        $token = is_array($data) ? ($data['access_token'] ?? null) : null;
        if ($response->status !== 200 || !is_string($token)) {
            throw new RuntimeException('API login failed with HTTP ' . $response->status);
        }

        return new self($site, ['X-API-Token' => $token]);
    }

    /**
     * Credentials of the setup script's super admin (.grav/credentials.env), or null when the file is missing.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function adminCredentials(): ?array
    {
        $base = TestSite::baseDir();
        if ($base === null) {
            return null;
        }
        $file = dirname($base) . '/credentials.env';
        if (!is_file($file)) {
            return null;
        }
        $values = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $pos = strpos($line, '=');
            if ($pos !== false) {
                $values[trim(substr($line, 0, $pos))] = trim(substr($line, $pos + 1));
            }
        }

        return isset($values['RM_API_USER'], $values['RM_API_PASSWORD']) ? [$values['RM_API_USER'], $values['RM_API_PASSWORD']] : null;
    }

    public function withHeaders(array $headers): self
    {
        return new self($this->site, array_replace($this->headers, $headers));
    }

    public function withoutAuth(): self
    {
        return new self($this->site, array_diff_key($this->headers, ['X-API-Token' => 1, 'X-API-Key' => 1, 'Authorization' => 1]));
    }

    /**
     * @param array<string, mixed>  $query
     * @param array<string, string> $headers
     */
    public function get(string $path, array $query = [], array $headers = []): ApiResponse
    {
        return $this->request('GET', $path, null, $query, $headers);
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, mixed>  $query
     * @param array<string, string> $headers
     */
    public function post(string $path, array $body = [], array $query = [], array $headers = []): ApiResponse
    {
        return $this->request('POST', $path, $body, $query, $headers);
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     */
    public function patch(string $path, array $body, array $headers = []): ApiResponse
    {
        return $this->request('PATCH', $path, $body, [], $headers);
    }

    /**
     * @param array<string, mixed>       $query
     * @param array<string, mixed>|null  $body
     * @param array<string, string>      $headers
     */
    public function delete(string $path, array $query = [], ?array $body = null, array $headers = []): ApiResponse
    {
        return $this->request('DELETE', $path, $body, $query, $headers);
    }

    /**
     * @param array<string, mixed>|null $body    JSON body (null = none, [] = "{}")
     * @param array<string, mixed>      $query
     * @param array<string, string>     $headers
     */
    public function request(string $method, string $path, ?array $body = null, array $query = [], array $headers = []): ApiResponse
    {
        $url = $this->site->url('/api/v1' . $path);
        if ($query !== []) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }
        $all = array_replace(['Accept' => 'application/json'], $this->headers, $headers);
        $lines = [];
        foreach ($all as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('curl_init failed');
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        ]);
        if ($body !== null) {
            $json = json_encode($body === [] ? new \stdClass() : $body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $lines[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $lines);
        $raw = curl_exec($ch);
        if (!is_string($raw)) {
            throw new RuntimeException(sprintf('%s %s failed: %s', $method, $path, curl_error($ch)));
        }
        $size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $parsed = [];
        foreach (explode("\r\n", trim(substr($raw, 0, $size))) as $line) {
            $pos = strpos($line, ':');
            if ($pos !== false) {
                $parsed[strtolower(trim(substr($line, 0, $pos)))][] = trim(substr($line, $pos + 1));
            }
        }
        $text = substr($raw, $size);
        $decoded = json_decode($text, true);

        return new ApiResponse($status, $parsed, $text, is_array($decoded) ? $decoded : null);
    }
}
