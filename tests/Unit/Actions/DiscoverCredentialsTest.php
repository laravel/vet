<?php

declare(strict_types=1);

use App\Actions\DiscoverCredentials;
use App\Support\Json;
use App\ValueObjects\Project;
use Illuminate\Support\Facades\File;

/**
 * @return array<string, string|null>
 */
function cleanAuthEnvironment(string $home): array
{
    return [
        'VET_GITHUB_TOKEN' => null,
        'GITHUB_TOKEN' => null,
        'GH_TOKEN' => null,
        'COMPOSER_AUTH' => null,
        'COMPOSER_AUTH_FILE' => null,
        'COMPOSER_HOME' => null,
        'XDG_CONFIG_HOME' => null,
        'HOME' => $home,
    ];
}

/**
 * @return array<string, string>
 */
function basic(string $username, string $password): array
{
    return ['Authorization' => 'Basic '.base64_encode($username.':'.$password)];
}

it('reads the auth file of the project after the auth file of the home directory', function (): void {
    $directory = sys_get_temp_dir().'/vet-auth-'.bin2hex(random_bytes(6));

    File::ensureDirectoryExists($directory.'/home/.composer');
    File::ensureDirectoryExists($directory.'/project');
    file_put_contents($directory.'/home/.composer/auth.json', Json::encode(['http-basic' => [
        'repo.acme.test' => ['username' => 'global', 'password' => 'pass'],
        'satis.acme.test' => ['username' => 'satis', 'password' => 'pass'],
    ]]));
    file_put_contents($directory.'/project/auth.json', Json::encode(['http-basic' => [
        'repo.acme.test' => ['username' => 'project', 'password' => 'pass'],
    ]]));

    try {
        $credentials = withEnvironment(cleanAuthEnvironment($directory.'/home'), static fn (): App\ValueObjects\Credentials => DiscoverCredentials::handle(Project::at($directory.'/project')));
    } finally {
        File::deleteDirectory($directory);
    }

    expect($credentials->headersFor('https://repo.acme.test/widget.zip'))->toBe(basic('project', 'pass'))
        ->and($credentials->headersFor('https://satis.acme.test/widget.zip'))->toBe(basic('satis', 'pass'));
});

it('reads the auth file that the environment names after the auth file of the project', function (): void {
    $directory = sys_get_temp_dir().'/vet-auth-'.bin2hex(random_bytes(6));

    File::ensureDirectoryExists($directory.'/project');
    file_put_contents($directory.'/project/auth.json', Json::encode(['bearer' => ['repo.acme.test' => 'project-token']]));
    file_put_contents($directory.'/named.json', Json::encode(['bearer' => ['repo.acme.test' => 'named-token']]));

    try {
        $credentials = withEnvironment(
            [...cleanAuthEnvironment($directory), 'COMPOSER_AUTH_FILE' => $directory.'/named.json'],
            static fn (): App\ValueObjects\Credentials => DiscoverCredentials::handle(Project::at($directory.'/project')),
        );
    } finally {
        File::deleteDirectory($directory);
    }

    expect($credentials->headersFor('https://repo.acme.test/widget.zip'))->toBe(['Authorization' => 'Bearer named-token']);
});

it('reads the composer auth variable after each auth file', function (): void {
    $directory = sys_get_temp_dir().'/vet-auth-'.bin2hex(random_bytes(6));

    File::ensureDirectoryExists($directory.'/project');
    file_put_contents($directory.'/project/auth.json', Json::encode(['bearer' => ['repo.acme.test' => 'project-token']]));

    $variable = (string) json_encode(['http-basic' => ['repo.acme.test' => ['username' => 'env', 'password' => 'pass']]]);

    try {
        $credentials = withEnvironment(
            [...cleanAuthEnvironment($directory), 'COMPOSER_AUTH' => $variable],
            static fn (): App\ValueObjects\Credentials => DiscoverCredentials::handle(Project::at($directory.'/project')),
        );
    } finally {
        File::deleteDirectory($directory);
    }

    expect($credentials->headersFor('https://repo.acme.test/widget.zip'))->toBe(basic('env', 'pass'));
});

it('skips a composer auth variable that holds no json object', function (): void {
    $directory = sys_get_temp_dir().'/vet-auth-'.bin2hex(random_bytes(6));

    File::ensureDirectoryExists($directory.'/project');

    try {
        $credentials = withEnvironment(
            [...cleanAuthEnvironment($directory), 'COMPOSER_AUTH' => 'not json'],
            static fn (): App\ValueObjects\Credentials => DiscoverCredentials::handle(Project::at($directory.'/project')),
        );
    } finally {
        File::deleteDirectory($directory);
    }

    expect($credentials->headersFor('https://repo.acme.test/widget.zip'))->toBe([]);
});

it('lets the github token variable win over the github credential of each auth file', function (): void {
    $directory = sys_get_temp_dir().'/vet-auth-'.bin2hex(random_bytes(6));

    File::ensureDirectoryExists($directory.'/project');
    file_put_contents($directory.'/project/auth.json', Json::encode([
        'github-oauth' => ['github.com' => 'file-token'],
        'http-basic' => ['repo.acme.test' => ['username' => 'user', 'password' => 'pass']],
    ]));

    try {
        $credentials = withEnvironment(
            [...cleanAuthEnvironment($directory), 'GITHUB_TOKEN' => 'variable-token'],
            static fn (): App\ValueObjects\Credentials => DiscoverCredentials::handle(Project::at($directory.'/project')),
        );
    } finally {
        File::deleteDirectory($directory);
    }

    expect($credentials->headersFor('https://api.github.com/repos/acme/widget/zipball/abc'))->toBe(['Authorization' => 'Bearer variable-token'])
        ->and($credentials->headersFor('https://repo.acme.test/widget.zip'))->toBe(basic('user', 'pass'));
});
