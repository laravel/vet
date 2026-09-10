<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\FetchFailedException;
use App\Support\GithubHost;
use App\Support\Path;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use Illuminate\Support\Facades\File;
use Psr\Http\Message\ResponseInterface;

final readonly class RequestUrl
{
    private const int MAX_REDIRECTS = 5;

    private const int CONNECT_TIMEOUT = 15;

    private const int TIMEOUT = 60;

    private const string SCHEME = 'https';

    /**
     * @param  array<string, string>  $githubHeaders
     */
    public function __construct(
        private string $userAgent,
        private array $githubHeaders,
        private ClientInterface $client,
    ) {}

    public static function default(): self
    {
        return new self('vet (+https://github.com/laravel/vet)', self::discoverGithubHeaders(), app(ClientInterface::class));
    }

    /**
     * @return array<string, string>
     */
    public static function bearer(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
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

        File::ensureDirectoryExists($directory);

        if (! File::isDirectory($directory)) {
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

    /**
     * @return array<string, string>
     */
    private static function discoverGithubHeaders(): array
    {
        foreach (['VET_GITHUB_TOKEN', 'GITHUB_TOKEN', 'GH_TOKEN'] as $variable) {
            $value = getenv($variable);

            if (is_string($value) && $value !== '') {
                return self::bearer($value);
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
                return self::bearer($oauth['github.com']);
            }
        }

        return [];
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
     * @return array<string, string>
     */
    private function headers(string $url): array
    {
        $headers = [
            'User-Agent' => $this->userAgent,
            'Accept' => 'application/json, application/zip;q=0.9, */*;q=0.8',
        ];

        return GithubHost::matches($url) ? [...$headers, ...$this->githubHeaders] : $headers;
    }

    private function getFollowingRedirects(string $url): string
    {
        $target = $url;

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $this->assertSecure($target);

            $response = $this->send($target);
            $status = $response->getStatusCode();
            $location = $response->getHeaderLine('Location');

            if ($status >= 300 && $status < 400 && $location !== '') {
                $target = $this->absolute($target, $location);

                continue;
            }

            if ($status < 200 || $status >= 300) {
                throw FetchFailedException::status($target, $status, (string) $response->getBody());
            }

            return (string) $response->getBody();
        }

        throw FetchFailedException::transport($url, sprintf('the server sent more than [%d] redirects.', self::MAX_REDIRECTS));
    }

    private function send(string $url): ResponseInterface
    {
        try {
            return $this->client->request('GET', $url, [
                RequestOptions::HEADERS => $this->headers($url),
                RequestOptions::ALLOW_REDIRECTS => false,
                RequestOptions::HTTP_ERRORS => false,
                RequestOptions::CONNECT_TIMEOUT => self::CONNECT_TIMEOUT,
                RequestOptions::TIMEOUT => self::TIMEOUT,
            ]);
        } catch (GuzzleException $guzzleException) {
            throw FetchFailedException::transport($url, $guzzleException->getMessage());
        }
    }

    private function assertSecure(string $url): void
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);

        if (! is_string($scheme) || mb_strtolower($scheme) !== self::SCHEME) {
            throw FetchFailedException::insecure($url);
        }
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

        return $origin.Path::normalize($directory.'/'.$location);
    }
}
