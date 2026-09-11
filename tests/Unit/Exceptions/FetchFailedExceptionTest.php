<?php

declare(strict_types=1);

use App\Exceptions\FetchFailedException;

it('tells the user to set a token when github refuses the request', function (): void {
    expect(FetchFailedException::status('https://api.github.com/repos/acme/widget', 403, '')->getMessage())
        ->toBe('Request to [https://api.github.com/repos/acme/widget] failed with HTTP [403]. GitHub rate-limits unauthenticated requests to 60/hour; set GITHUB_TOKEN to raise it.');
});

it('gives no token hint when a different host refuses the request', function (): void {
    expect(FetchFailedException::status('https://example.test/widget.zip', 403, '')->getMessage())
        ->toBe('Request to [https://example.test/widget.zip] failed with HTTP [403].');
});

it('tells the user that the package may not exist when the server finds nothing', function (): void {
    expect(FetchFailedException::status('https://repo.packagist.org/p2/acme/widget.json', 404, '')->getMessage())
        ->toBe('Request to [https://repo.packagist.org/p2/acme/widget.json] failed with HTTP [404]. The package or version may not exist.');
});

it('quotes the first 200 characters of the response', function (): void {
    expect(FetchFailedException::status('https://example.test/widget.zip', 500, '  '.str_repeat('a', 250)."\n")->getMessage())
        ->toBe('Request to [https://example.test/widget.zip] failed with HTTP [500]. Response: '.str_repeat('a', 200));
});
