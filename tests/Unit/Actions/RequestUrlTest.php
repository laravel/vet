<?php

declare(strict_types=1);

use App\Actions\RequestUrl;
use App\Exceptions\FetchFailedException;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use Tests\Fixtures\FakeHttp;

it('follows a redirect chain to the final response', function (): void {
    $http = new FakeHttp([
        FakeHttp::redirect('https://example.test/hop/1'),
        FakeHttp::redirect('https://example.test/end'),
        FakeHttp::body('done'),
    ]);

    $body = (new RequestUrl('vet-test', [], $http->client))->get('https://example.test/hop/2');

    expect($body)->toBe('done')
        ->and($http->urls())->toBe([
            'https://example.test/hop/2',
            'https://example.test/hop/1',
            'https://example.test/end',
        ])
        ->and($http->header('https://example.test/hop/2', 'User-Agent'))->toBe('vet-test');
});

it('resolves a relative location against the current url', function (): void {
    $http = new FakeHttp([
        FakeHttp::redirect('deeper/end'),
        FakeHttp::redirect('/root'),
        FakeHttp::redirect('//example.test/scheme-relative'),
        FakeHttp::body('done'),
    ]);

    $body = (new RequestUrl('vet-test', [], $http->client))->get('https://example.test/dir/start');

    expect($body)->toBe('done')
        ->and($http->urls())->toBe([
            'https://example.test/dir/start',
            'https://example.test/dir/deeper/end',
            'https://example.test/root',
            'https://example.test/scheme-relative',
        ]);
});

it('follows five redirects and refuses the sixth', function (): void {
    $chain = [];

    for ($hop = 5; $hop >= 1; $hop--) {
        $chain[] = FakeHttp::redirect($hop === 1 ? 'https://example.test/end' : 'https://example.test/hop/'.($hop - 1));
    }

    $chain[] = FakeHttp::body('done');

    $loop = array_fill(0, 6, FakeHttp::redirect('https://example.test/loop'));

    $http = new FakeHttp([...$chain, ...$loop]);
    $request = new RequestUrl('vet-test', [], $http->client);

    expect($request->get('https://example.test/hop/5'))->toBe('done')
        ->and(fn (): string => $request->get('https://example.test/loop'))
        ->toThrow(FetchFailedException::class, 'sent more than [5] redirects')
        ->and($http->urls())->toHaveCount(12);
});

it('sends the github token to github hosts and drops it on a redirect to a foreign host', function (): void {
    $http = new FakeHttp([
        FakeHttp::redirect('https://codeload.github.com/acme/widget/legacy.zip/abc'),
        FakeHttp::redirect('https://cdn.example.test/widget.zip'),
        FakeHttp::body('zip'),
    ]);

    $body = (new RequestUrl('vet-test', RequestUrl::bearer('secret-token'), $http->client))->get('https://api.github.com/repos/acme/widget/zipball/abc');

    expect($body)->toBe('zip')
        ->and($http->header('https://api.github.com/repos/acme/widget/zipball/abc', 'Authorization'))->toBe('Bearer secret-token')
        ->and($http->header('https://codeload.github.com/acme/widget/legacy.zip/abc', 'Authorization'))->toBe('Bearer secret-token')
        ->and($http->header('https://cdn.example.test/widget.zip', 'Authorization'))->toBeNull();
});

it('reads the github token of the auth file in the xdg config home', function (): void {
    $variables = ['VET_GITHUB_TOKEN', 'GITHUB_TOKEN', 'GH_TOKEN', 'COMPOSER_AUTH_FILE', 'COMPOSER_HOME', 'HOME', 'XDG_CONFIG_HOME'];
    $saved = [];

    $directory = sys_get_temp_dir().'/vet-xdg-'.bin2hex(random_bytes(6));
    mkdir($directory.'/home', 0o777, true);
    mkdir($directory.'/config/composer', 0o777, true);
    file_put_contents($directory.'/config/composer/auth.json', (string) json_encode(['github-oauth' => ['github.com' => 'xdg-token']]));

    foreach ($variables as $variable) {
        $saved[$variable] = getenv($variable);
        putenv($variable);
    }

    putenv('HOME='.$directory.'/home');
    putenv('XDG_CONFIG_HOME='.$directory.'/config');

    $http = new FakeHttp([FakeHttp::body('zip')]);
    app()->instance(ClientInterface::class, $http->client);

    try {
        RequestUrl::default()->get('https://github.com/acme/widget.zip');
    } finally {
        foreach ($saved as $variable => $value) {
            putenv($value === false ? $variable : $variable.'='.$value);
        }

        unlink($directory.'/config/composer/auth.json');
        rmdir($directory.'/config/composer');
        rmdir($directory.'/config');
        rmdir($directory.'/home');
        rmdir($directory);
    }

    expect($http->header('https://github.com/acme/widget.zip', 'Authorization'))->toBe('Bearer xdg-token');
});

it('sends no token to a host that only ends with the letters of github.com', function (): void {
    $http = new FakeHttp([FakeHttp::body('zip')]);

    (new RequestUrl('vet-test', RequestUrl::bearer('secret-token'), $http->client))->get('https://evilgithub.com/widget.zip');

    expect($http->header('https://evilgithub.com/widget.zip', 'Authorization'))->toBeNull();
});

it('refuses a url that is not https before it sends a request', function (string $url): void {
    $http = new FakeHttp([]);

    expect(fn (): string => (new RequestUrl('vet-test', RequestUrl::bearer('secret-token'), $http->client))->get($url))
        ->toThrow(FetchFailedException::class, sprintf('Request to [%s] refused: vet reads https URLs only.', $url))
        ->and($http->requests)->toBe([]);
})->with([
    'http://github.com/acme/widget.zip',
    'ftp://example.test/widget.zip',
    'file:///etc/passwd',
    'example.test/widget.zip',
]);

it('refuses a redirect to a url that is not https', function (): void {
    $http = new FakeHttp([FakeHttp::redirect('http://github.com/acme/widget.zip')]);

    expect(fn (): string => (new RequestUrl('vet-test', RequestUrl::bearer('secret-token'), $http->client))->get('https://github.com/acme/widget.zip'))
        ->toThrow(FetchFailedException::class, 'Request to [http://github.com/acme/widget.zip] refused')
        ->and($http->urls())->toBe(['https://github.com/acme/widget.zip']);
});

it('reports the status of a failed response', function (): void {
    $http = new FakeHttp([new Response(404, [], 'missing')]);

    expect(fn (): string => (new RequestUrl('vet-test', [], $http->client))->get('https://example.test/missing'))
        ->toThrow(FetchFailedException::class, 'Request to [https://example.test/missing] failed with HTTP [404].');
});

it('refuses an empty body', function (): void {
    $http = new FakeHttp([FakeHttp::body("  \n")]);

    expect(fn (): string => (new RequestUrl('vet-test', [], $http->client))->get('https://example.test/empty'))
        ->toThrow(FetchFailedException::class, 'returned an empty body');
});
