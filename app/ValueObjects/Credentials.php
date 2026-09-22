<?php

declare(strict_types=1);

namespace App\ValueObjects;

use App\Support\GithubHost;
use App\Support\Json;

final readonly class Credentials
{
    private const string GITHUB = 'github.com';

    private const string SCHEME = 'https';

    /**
     * @param  array<string, array<string, string>>  $headersByOrigin
     */
    private function __construct(
        private array $headersByOrigin,
    ) {}

    public static function none(): self
    {
        return new self([]);
    }

    public static function bearer(string $origin, string $token): self
    {
        return new self([self::normalize($origin) => self::bearerHeader($token)]);
    }

    /**
     * @param  array<string, mixed>  $document
     */
    public static function fromDocument(array $document): self
    {
        $headers = [];

        foreach (Json::array($document, 'github-oauth') as $origin => $token) {
            self::set($headers, $origin, is_string($token) ? self::bearerHeader($token) : []);
        }

        foreach (Json::array($document, 'gitlab-oauth') as $origin => $token) {
            $token = is_array($token) ? Json::string($token, 'token') : $token;

            self::set($headers, $origin, is_string($token) ? self::bearerHeader($token) : []);
        }

        foreach (Json::array($document, 'gitlab-token') as $origin => $token) {
            self::set($headers, $origin, self::gitlabTokenHeader($token));
        }

        foreach (Json::array($document, 'forgejo-token') as $origin => $credential) {
            self::set($headers, $origin, is_array($credential) ? self::basicHeader($credential, 'username', 'token') : []);
        }

        foreach (Json::array($document, 'http-basic') as $origin => $credential) {
            self::set($headers, $origin, is_array($credential) ? self::basicHeader($credential, 'username', 'password') : []);
        }

        foreach (Json::array($document, 'bearer') as $origin => $token) {
            self::set($headers, $origin, is_string($token) ? self::bearerHeader($token) : []);
        }

        foreach (Json::array($document, 'custom-headers') as $origin => $lines) {
            self::set($headers, $origin, is_array($lines) ? self::customHeaders($lines) : []);
        }

        return new self($headers);
    }

    public function merge(self $other): self
    {
        return new self([...$this->headersByOrigin, ...$other->headersByOrigin]);
    }

    /**
     * @return array<string, string>
     */
    public function headersFor(string $url): array
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host']) || mb_strtolower($parts['scheme']) !== self::SCHEME) {
            return [];
        }

        $host = self::normalize($parts['host']);
        $origin = isset($parts['port']) ? $host.':'.$parts['port'] : $host;
        $candidates = [$origin, $host];

        if (GithubHost::matches($url)) {
            $candidates[] = self::GITHUB;
        }

        foreach ($candidates as $candidate) {
            if (isset($this->headersByOrigin[$candidate])) {
                return $this->headersByOrigin[$candidate];
            }
        }

        return [];
    }

    /**
     * @param  array<string, array<string, string>>  $headers
     * @param  array<string, string>  $value
     */
    private static function set(array &$headers, int|string $origin, array $value): void
    {
        if (! is_string($origin) || $value === []) {
            return;
        }

        $headers[self::normalize($origin)] = $value;
    }

    private static function normalize(string $origin): string
    {
        return mb_strtolower(rtrim($origin, '.'));
    }

    /**
     * @return array<string, string>
     */
    private static function bearerHeader(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }

    /**
     * @return array<string, string>
     */
    private static function gitlabTokenHeader(mixed $token): array
    {
        if (is_string($token)) {
            return ['PRIVATE-TOKEN' => $token];
        }

        return is_array($token) ? self::basicHeader($token, 'username', 'token') : [];
    }

    /**
     * @param  array<array-key, mixed>  $credential
     * @return array<string, string>
     */
    private static function basicHeader(array $credential, string $userKey, string $secretKey): array
    {
        $username = Json::string($credential, $userKey);
        $secret = Json::string($credential, $secretKey);

        if ($username === null || $secret === null) {
            return [];
        }

        return ['Authorization' => 'Basic '.base64_encode($username.':'.$secret)];
    }

    /**
     * @param  array<array-key, mixed>  $lines
     * @return array<string, string>
     */
    private static function customHeaders(array $lines): array
    {
        $headers = [];

        foreach ($lines as $line) {
            if (! is_string($line)) {
                continue;
            }

            $colon = mb_strpos($line, ':');

            if ($colon === false || trim(mb_substr($line, 0, $colon)) === '') {
                continue;
            }

            $headers[trim(mb_substr($line, 0, $colon))] = trim(mb_substr($line, $colon + 1));
        }

        return $headers;
    }
}
