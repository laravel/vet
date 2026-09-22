<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\VetException;
use App\Support\Json;
use App\Support\Path;
use App\ValueObjects\Credentials;
use App\ValueObjects\Project;

final class DiscoverCredentials
{
    private const string GITHUB = 'github.com';

    private const array GITHUB_VARIABLES = ['VET_GITHUB_TOKEN', 'GITHUB_TOKEN', 'GH_TOKEN'];

    public static function handle(Project $project): Credentials
    {
        $credentials = Credentials::none();

        foreach (self::authFilePaths($project) as $path) {
            $credentials = $credentials->merge(self::fromFile($path));
        }

        return $credentials
            ->merge(self::fromEnvironment())
            ->merge(self::fromGithubVariable());
    }

    /**
     * @return array<int, string>
     */
    private static function authFilePaths(Project $project): array
    {
        $paths = [...self::globalAuthFilePaths(), $project->rootPath.'/auth.json'];

        $named = getenv('COMPOSER_AUTH_FILE');

        if (is_string($named) && $named !== '') {
            $paths[] = $named;
        }

        return $paths;
    }

    /**
     * @return array<int, string>
     */
    private static function globalAuthFilePaths(): array
    {
        $composerHome = getenv('COMPOSER_HOME');

        if (is_string($composerHome) && $composerHome !== '') {
            return [Path::join($composerHome, 'auth.json')];
        }

        $paths = [];
        $xdgConfigHome = getenv('XDG_CONFIG_HOME');
        $home = getenv('HOME');

        if (is_string($xdgConfigHome) && $xdgConfigHome !== '') {
            $paths[] = Path::join($xdgConfigHome, 'composer/auth.json');
        }

        if (is_string($home) && $home !== '') {
            $paths[] = Path::join($home, '.composer/auth.json');
            $paths[] = Path::join($home, '.config/composer/auth.json');
        }

        return $paths;
    }

    private static function fromFile(string $path): Credentials
    {
        if (! is_file($path)) {
            return Credentials::none();
        }

        try {
            return Credentials::fromDocument(Json::readFile($path, 'the composer auth file'));
        } catch (VetException) {
            return Credentials::none();
        }
    }

    private static function fromEnvironment(): Credentials
    {
        $encoded = getenv('COMPOSER_AUTH');

        if (! is_string($encoded) || $encoded === '') {
            return Credentials::none();
        }

        $decoded = json_decode($encoded, true);

        /** @var array<string, mixed> $document */
        $document = is_array($decoded) ? $decoded : [];

        return Credentials::fromDocument($document);
    }

    private static function fromGithubVariable(): Credentials
    {
        foreach (self::GITHUB_VARIABLES as $variable) {
            $token = getenv($variable);

            if (is_string($token) && $token !== '') {
                return Credentials::bearer(self::GITHUB, $token);
            }
        }

        return Credentials::none();
    }
}
