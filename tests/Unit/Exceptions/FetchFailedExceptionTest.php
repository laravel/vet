<?php

declare(strict_types=1);

use App\Exceptions\FetchFailedException;

it('tells the user to set a token when github refuses a request that carries none', function (): void {
    expect(FetchFailedException::status('https://api.github.com/repos/acme/widget', 403, '', false)->getMessage())
        ->toBe('Request to [https://api.github.com/repos/acme/widget] failed with HTTP [403]. GitHub rate-limits unauthenticated requests to 60/hour; set [GITHUB_TOKEN] to raise it.');
});

it('names the auth file when a host refuses a request that carries no credential', function (int $status): void {
    expect(FetchFailedException::status('https://repo.example.test/widget.zip', $status, '', false)->getMessage())
        ->toBe(sprintf('Request to [https://repo.example.test/widget.zip] failed with HTTP [%d]. Vet sent no credential, because the [auth.json] that composer reads holds none for this host.', $status));
})->with([401, 403]);

it('says that the server refused the credential that composer holds', function (string $url): void {
    expect(FetchFailedException::status($url, 401, '', true)->getMessage())
        ->toBe(sprintf('Request to [%s] failed with HTTP [401]. The server refused the credential that composer holds for this host in [auth.json].', $url));
})->with([
    'https://repo.example.test/widget.zip',
    'https://api.github.com/repos/acme/widget',
]);

it('tells the user that the package may not exist when the server finds nothing', function (): void {
    expect(FetchFailedException::status('https://repo.packagist.org/p2/acme/widget.json', 404, '', false)->getMessage())
        ->toBe('Request to [https://repo.packagist.org/p2/acme/widget.json] failed with HTTP [404]. The package or version may not exist.');
});

it('quotes the first 200 characters of the response', function (): void {
    expect(FetchFailedException::status('https://example.test/widget.zip', 500, '  '.str_repeat('a', 250)."\n", false)->getMessage())
        ->toBe('Request to [https://example.test/widget.zip] failed with HTTP [500]. Response: '.str_repeat('a', 200));
});
