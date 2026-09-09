<?php

declare(strict_types=1);

use App\Support\GithubHost;

it('matches github.com and its subdomains', function (string $url): void {
    expect(GithubHost::matches($url))->toBeTrue();
})->with([
    'https://github.com/laravel/vet',
    'https://api.github.com/repos/laravel/vet',
    'https://codeload.github.com/laravel/vet/legacy.zip/refs/tags/v1.0.0',
    'https://objects.githubusercontent.com.github.com/file.zip',
    'https://API.GitHub.COM/repos/laravel/vet',
    'https://github.com./laravel/vet',
]);

it('does not match a host that only ends with the same letters', function (string $url): void {
    expect(GithubHost::matches($url))->toBeFalse();
})->with([
    'https://evilgithub.com/laravel/vet',
    'https://github.com.evil.test/laravel/vet',
    'https://notgithub.com/laravel/vet',
    'https://xgithub.com/laravel/vet',
    'https://github.completely.evil/laravel/vet',
    'https://objects.githubusercontent.com/file.zip',
]);

it('does not match a url that is not https', function (string $url): void {
    expect(GithubHost::matches($url))->toBeFalse();
})->with([
    'http://github.com/laravel/vet',
    'http://api.github.com/repos/laravel/vet',
    'ftp://github.com/laravel/vet',
    'HTTP://github.com/laravel/vet',
]);

it('does not match a url that carries no host', function (string $url): void {
    expect(GithubHost::matches($url))->toBeFalse();
})->with([
    '/laravel/vet/archive.zip',
    'github.com/laravel/vet',
    '',
    'not a url at all',
]);
