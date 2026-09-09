<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\FetchFailedException;
use App\Support\GithubHost;
use App\Support\Path;
use App\ValueObjects\HttpResponse;

final readonly class RequestUrl
{
    private const int TIMEOUT = 60;

    private const int MAX_REDIRECTS = 5;

    public function __construct(
        private string $userAgent,
        private ?string $githubToken = null,
    ) {}

    public static function default(): self
    {
        return new self('vet (+https://github.com/laravel/vet)', self::discoverGithubToken());
    }

    public function get(string $url): string
    {
        $body = $this->getFollowingRedirects($url);

        if (trim($body) === '') {
            throw FetchFailedException::empty($url);
        }

        return $body;
    }

    public function download(string $url, string $destination): void
    {
        $directory = dirname($destination);

        if (! is_dir($directory) && ! @mkdir($directory, 0o777, true) && ! is_dir($directory)) {
            throw FetchFailedException::transport($url, sprintf('could not create the directory [%s].', $directory));
        }

        $temporary = $destination.'.'.bin2hex(random_bytes(6)).'.part';

        try {
            $body = $this->get($url);

            if (file_put_contents($temporary, $body) === false) {
                throw FetchFailedException::transport($url, sprintf('could not write to [%s].', $temporary));
            }

            if ((int) filesize($temporary) === 0) {
                throw FetchFailedException::empty($url);
            }

            if (! rename($temporary, $destination)) {
                throw FetchFailedException::transport($url, sprintf('could not move the download into [%s].', $destination));
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    private static function discoverGithubToken(): ?string
    {
        foreach (['VET_GITHUB_TOKEN', 'GITHUB_TOKEN', 'GH_TOKEN'] as $variable) {
            $value = getenv($variable);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        foreach (self::authFilePaths() as $path) {
            if (! is_file($path)) {
                continue;
            }

            $contents = @file_get_contents($path);

            if ($contents === false) {
                continue;
            }

            $decoded = json_decode($contents, true);

            if (! is_array($decoded)) {
                continue;
            }

            $oauth = $decoded['github-oauth'] ?? null;

            if (is_array($oauth) && isset($oauth['github.com']) && is_string($oauth['github.com'])) {
                return $oauth['github.com'];
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private static function authFilePaths(): array
    {
        $paths = [];

        $composerAuth = getenv('COMPOSER_AUTH_FILE');

        if (is_string($composerAuth) && $composerAuth !== '') {
            $paths[] = $composerAuth;
        }

        $composerHome = getenv('COMPOSER_HOME');
        $home = getenv('HOME');

        if (is_string($composerHome) && $composerHome !== '') {
            $paths[] = Path::join($composerHome, 'auth.json');
        } elseif (is_string($home) && $home !== '') {
            $paths[] = Path::join($home, '.composer/auth.json');
            $paths[] = Path::join($home, '.config/composer/auth.json');
        }

        return $paths;
    }

    /**
     * @return array<int, string>
     */
    private function headers(string $url): array
    {
        $headers = [
            'User-Agent: '.$this->userAgent,
            'Accept: application/json, application/zip;q=0.9, */*;q=0.8',
        ];

        if ($this->githubToken !== null && GithubHost::matches($url)) {
            $headers[] = 'Authorization: Bearer '.$this->githubToken;
        }

        return $headers;
    }

    private function getFollowingRedirects(string $url): string
    {
        $target = $url;

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $response = extension_loaded('curl')
                ? $this->getWithCurl($target)
                : $this->getWithStreams($target);

            if ($response->redirect === null) {
                return $response->body;
            }

            $target = $response->redirect;
        }

        throw FetchFailedException::transport($url, sprintf('the server sent more than [%d] redirects.', self::MAX_REDIRECTS));
    }

    private function getWithCurl(string $url): HttpResponse
    {
        $handle = curl_init($url);

        if ($handle === false) {
            throw FetchFailedException::transport($url, 'could not initialise curl.');
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_HTTPHEADER => $this->headers($url),
            CURLOPT_ENCODING => '',
        ]);

        $body = curl_exec($handle);
        $error = curl_error($handle);
        $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $location = curl_getinfo($handle, CURLINFO_REDIRECT_URL);

        curl_close($handle);

        if ($body === false || $error !== '') {
            throw FetchFailedException::transport($url, $error === '' ? 'the transfer failed.' : $error);
        }

        if ($this->redirects($status) && is_string($location) && $location !== '') {
            return HttpResponse::redirect($location);
        }

        if ($status < 200 || $status >= 300) {
            throw FetchFailedException::status($url, $status, is_string($body) ? $body : '');
        }

        return HttpResponse::body((string) $body);
    }

    private function getWithStreams(string $url): HttpResponse
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", $this->headers($url)),
                'timeout' => self::TIMEOUT,
                'follow_location' => 0,
                'max_redirects' => 1,
                'ignore_errors' => true,
            ],
        ]);

        $http_response_header = [];

        $body = @file_get_contents($url, false, $context);

        if ($body === false) {
            throw FetchFailedException::transport($url, 'the transfer failed.');
        }

        $status = 0;
        $location = null;

        foreach ($http_response_header as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $matches) === 1) {
                $status = (int) $matches[1];
                $location = null;
            }

            if (preg_match('#^Location:\s*(\S.*)$#i', $header, $matches) === 1) {
                $location = trim($matches[1]);
            }
        }

        if ($this->redirects($status) && $location !== null && $location !== '') {
            return HttpResponse::redirect($this->absolute($url, $location));
        }

        if ($status < 200 || $status >= 300) {
            throw FetchFailedException::status($url, $status, $body);
        }

        return HttpResponse::body($body);
    }

    private function redirects(int $status): bool
    {
        return $status >= 300 && $status < 400;
    }

    private function absolute(string $base, string $location): string
    {
        if (preg_match('#^[a-zA-Z][a-zA-Z0-9+.\-]*://#', $location) === 1) {
            return $location;
        }

        $parts = parse_url($base);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return $location;
        }

        if (str_starts_with($location, '//')) {
            return $parts['scheme'].':'.$location;
        }

        $origin = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');

        if (str_starts_with($location, '/')) {
            return $origin.$location;
        }

        $directory = rtrim(str_replace('\\', '/', dirname(isset($parts['path']) ? (string) $parts['path'] : '/')), '/');

        return $origin.'/'.Path::normalize($directory.'/'.$location);
    }
}
