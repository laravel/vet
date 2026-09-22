<?php

declare(strict_types=1);

use App\ValueObjects\Credentials;

it('holds no header when it holds no credential', function (): void {
    expect(Credentials::none()->headersFor('https://repo.example.test/widget.zip'))->toBe([]);
});

it('turns each kind of composer credential into the header that composer sends', function (): void {
    $credentials = Credentials::fromDocument([
        'github-oauth' => ['github.com' => 'gh-token'],
        'gitlab-oauth' => ['gitlab.com' => 'gl-oauth', 'gitlab.example.test' => ['token' => 'gl-object-oauth']],
        'gitlab-token' => ['gitlab.acme.test' => 'gl-private', 'deploy.acme.test' => ['username' => 'deploy', 'token' => 'deploy-secret']],
        'forgejo-token' => ['forge.acme.test' => ['username' => 'forge', 'token' => 'forge-secret']],
        'http-basic' => ['repo.acme.test' => ['username' => 'user', 'password' => 'pass']],
        'bearer' => ['nova.acme.test' => 'nova-token'],
        'custom-headers' => ['custom.acme.test' => ['X-Api-Key: api-key', 'X-Team : platform']],
    ]);

    expect($credentials->headersFor('https://github.com/acme/widget.zip'))->toBe(['Authorization' => 'Bearer gh-token'])
        ->and($credentials->headersFor('https://gitlab.com/api/v4/projects/1/repository/archive.zip'))->toBe(['Authorization' => 'Bearer gl-oauth'])
        ->and($credentials->headersFor('https://gitlab.example.test/archive.zip'))->toBe(['Authorization' => 'Bearer gl-object-oauth'])
        ->and($credentials->headersFor('https://gitlab.acme.test/archive.zip'))->toBe(['PRIVATE-TOKEN' => 'gl-private'])
        ->and($credentials->headersFor('https://deploy.acme.test/archive.zip'))->toBe(['Authorization' => 'Basic '.base64_encode('deploy:deploy-secret')])
        ->and($credentials->headersFor('https://forge.acme.test/archive.zip'))->toBe(['Authorization' => 'Basic '.base64_encode('forge:forge-secret')])
        ->and($credentials->headersFor('https://repo.acme.test/dists/widget.zip'))->toBe(['Authorization' => 'Basic '.base64_encode('user:pass')])
        ->and($credentials->headersFor('https://nova.acme.test/dists/widget.zip'))->toBe(['Authorization' => 'Bearer nova-token'])
        ->and($credentials->headersFor('https://custom.acme.test/widget.zip'))->toBe(['X-Api-Key' => 'api-key', 'X-Team' => 'platform']);
});

it('skips a credential whose shape composer does not read', function (): void {
    $credentials = Credentials::fromDocument([
        'github-oauth' => ['github.com' => ['token' => 'object']],
        'gitlab-oauth' => ['gitlab.com' => 7],
        'gitlab-token' => ['gitlab.acme.test' => ['token' => 'no-username'], 'other.acme.test' => 1],
        'forgejo-token' => ['forge.acme.test' => 'string'],
        'http-basic' => ['repo.acme.test' => ['username' => 'user'], 'list.acme.test' => 'user:pass'],
        'bearer' => ['nova.acme.test' => ['nova-token']],
        'custom-headers' => ['custom.acme.test' => ['no colon', ': no name', 5], 'string.acme.test' => 'X-Api-Key: api-key'],
        'bitbucket-oauth' => ['bitbucket.org' => ['consumer-key' => 'key', 'consumer-secret' => 'secret']],
    ]);

    foreach ([
        'https://github.com/acme/widget.zip',
        'https://gitlab.com/archive.zip',
        'https://gitlab.acme.test/archive.zip',
        'https://other.acme.test/archive.zip',
        'https://forge.acme.test/archive.zip',
        'https://repo.acme.test/widget.zip',
        'https://list.acme.test/widget.zip',
        'https://nova.acme.test/widget.zip',
        'https://custom.acme.test/widget.zip',
        'https://string.acme.test/widget.zip',
        'https://bitbucket.org/acme/widget/get/abc.zip',
    ] as $url) {
        expect($credentials->headersFor($url))->toBe([], $url);
    }
});

it('lets the later kind of credential win for one host, in the order that composer loads them', function (): void {
    $credentials = Credentials::fromDocument([
        'bearer' => ['repo.acme.test' => 'bearer-token'],
        'http-basic' => ['repo.acme.test' => ['username' => 'user', 'password' => 'pass']],
    ]);

    expect($credentials->headersFor('https://repo.acme.test/widget.zip'))->toBe(['Authorization' => 'Bearer bearer-token']);
});

it('lets the merged credentials win for one host and keeps the others', function (): void {
    $global = Credentials::fromDocument(['http-basic' => [
        'repo.acme.test' => ['username' => 'global', 'password' => 'pass'],
        'other.acme.test' => ['username' => 'other', 'password' => 'pass'],
    ]]);
    $local = Credentials::bearer('repo.acme.test', 'local-token');

    $merged = $global->merge($local);

    expect($merged->headersFor('https://repo.acme.test/widget.zip'))->toBe(['Authorization' => 'Bearer local-token'])
        ->and($merged->headersFor('https://other.acme.test/widget.zip'))->toBe(['Authorization' => 'Basic '.base64_encode('other:pass')]);
});

it('matches a host with its port, then the host alone', function (): void {
    $credentials = Credentials::fromDocument(['http-basic' => [
        'repo.acme.test:8443' => ['username' => 'port', 'password' => 'pass'],
        'repo.acme.test' => ['username' => 'plain', 'password' => 'pass'],
        'Satis.Acme.Test.' => ['username' => 'satis', 'password' => 'pass'],
    ]]);

    expect($credentials->headersFor('https://repo.acme.test:8443/widget.zip'))->toBe(['Authorization' => 'Basic '.base64_encode('port:pass')])
        ->and($credentials->headersFor('https://repo.acme.test/widget.zip'))->toBe(['Authorization' => 'Basic '.base64_encode('plain:pass')])
        ->and($credentials->headersFor('https://repo.acme.test:9443/widget.zip'))->toBe(['Authorization' => 'Basic '.base64_encode('plain:pass')])
        ->and($credentials->headersFor('https://SATIS.acme.test./widget.zip'))->toBe(['Authorization' => 'Basic '.base64_encode('satis:pass')]);
});

it('sends the github credential to each github host and to no other host', function (): void {
    $credentials = Credentials::bearer('github.com', 'gh-token');

    expect($credentials->headersFor('https://api.github.com/repos/acme/widget/zipball/abc'))->toBe(['Authorization' => 'Bearer gh-token'])
        ->and($credentials->headersFor('https://codeload.github.com/acme/widget/legacy.zip/abc'))->toBe(['Authorization' => 'Bearer gh-token'])
        ->and($credentials->headersFor('https://evilgithub.com/widget.zip'))->toBe([])
        ->and($credentials->headersFor('https://cdn.example.test/widget.zip'))->toBe([]);
});

it('sends no credential over a url that is not https', function (string $url): void {
    $credentials = Credentials::fromDocument(['http-basic' => ['repo.acme.test' => ['username' => 'user', 'password' => 'pass']]]);

    expect($credentials->headersFor($url))->toBe([]);
})->with([
    'http://repo.acme.test/widget.zip',
    'ftp://repo.acme.test/widget.zip',
    'repo.acme.test/widget.zip',
    'https:widget.zip',
]);
