<?php

declare(strict_types=1);

use App\Actions\RequestUrl;
use App\Exceptions\FetchFailedException;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\File;
use Tests\Fixtures\FakeHttp;
use Tests\Fixtures\Warnings;

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

it('keeps the port of the current url on a redirect to a path', function (): void {
    $http = new FakeHttp([
        FakeHttp::redirect('/root'),
        FakeHttp::redirect('next.zip'),
        FakeHttp::body('done'),
    ]);

    $body = (new RequestUrl('vet-test', [], $http->client))->get('https://example.test:8443/dir/start');

    expect($body)->toBe('done')
        ->and($http->urls())->toBe([
            'https://example.test:8443/dir/start',
            'https://example.test:8443/root',
            'https://example.test:8443/next.zip',
        ]);
});

it('resolves a relative location against a url that holds no path', function (): void {
    $http = new FakeHttp([
        FakeHttp::redirect('next.zip'),
        FakeHttp::body('done'),
    ]);

    $body = (new RequestUrl('vet-test', [], $http->client))->get('https://example.test');

    expect($body)->toBe('done')
        ->and($http->urls())->toBe([
            'https://example.test',
            'https://example.test/next.zip',
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

it('reads the github token in the order that composer reads it', function (): void {
    $directory = sys_get_temp_dir().'/vet-auth-'.bin2hex(random_bytes(6));

    mkdir($directory.'/home/.composer', 0o777, true);
    mkdir($directory.'/composer-home', 0o777, true);
    file_put_contents($directory.'/home/.composer/auth.json', (string) json_encode(['github-oauth' => ['github.com' => 'home-token']]));

    $http = new FakeHttp([FakeHttp::body('zip'), FakeHttp::body('zip'), FakeHttp::body('zip')]);
    app()->instance(ClientInterface::class, $http->client);

    $environment = [
        'VET_GITHUB_TOKEN' => null,
        'GITHUB_TOKEN' => null,
        'GH_TOKEN' => null,
        'COMPOSER_AUTH_FILE' => null,
        'COMPOSER_HOME' => null,
        'XDG_CONFIG_HOME' => null,
        'HOME' => $directory.'/home',
    ];

    try {
        withEnvironment([...$environment, 'GH_TOKEN' => 'gh-token', 'VET_GITHUB_TOKEN' => 'vet-token'], static fn (): string => RequestUrl::default()->get('https://github.com/acme/variable.zip'));
        withEnvironment($environment, static fn (): string => RequestUrl::default()->get('https://github.com/acme/home.zip'));
        withEnvironment([...$environment, 'COMPOSER_HOME' => $directory.'/composer-home'], static fn (): string => RequestUrl::default()->get('https://github.com/acme/composer-home.zip'));
    } finally {
        File::deleteDirectory($directory);
    }

    expect($http->header('https://github.com/acme/variable.zip', 'Authorization'))->toBe('Bearer vet-token')
        ->and($http->header('https://github.com/acme/home.zip', 'Authorization'))->toBe('Bearer home-token')
        ->and($http->header('https://github.com/acme/composer-home.zip', 'Authorization'))->toBeNull();
});

it('names the url that the transport fails to reach', function (): void {
    $http = new FakeHttp([new ConnectException('Could not resolve host [example.test]', new Request('GET', 'https://example.test/widget.zip'))]);

    expect(fn (): string => (new RequestUrl('vet-test', [], $http->client))->get('https://example.test/widget.zip'))
        ->toThrow(FetchFailedException::class, 'Request to [https://example.test/widget.zip] failed: Could not resolve host [example.test]');
});

it('moves a download into place, and writes no file for a failed download', function (): void {
    $directory = sys_get_temp_dir().'/vet-download-'.bin2hex(random_bytes(6));
    $http = new FakeHttp([FakeHttp::body('zip bytes'), new Response(500, [], 'boom')]);
    $request = new RequestUrl('vet-test', [], $http->client);

    try {
        $request->download('https://example.test/widget.zip', $directory.'/widget.zip');

        expect(function () use ($request, $directory): void {
            $request->download('https://example.test/gadget.zip', $directory.'/gadget.zip');
        })->toThrow(FetchFailedException::class, 'failed with HTTP [500]');

        $files = glob($directory.'/*');
        $contents = file_get_contents($directory.'/widget.zip');
    } finally {
        File::deleteDirectory($directory);
    }

    expect($files)->toBe([$directory.'/widget.zip'])
        ->and($contents)->toBe('zip bytes');
});

it('skips an auth file that it cannot read or decode', function (): void {
    $directory = sys_get_temp_dir().'/vet-auth-'.bin2hex(random_bytes(6));

    mkdir($directory.'/composer-home', 0o777, true);
    file_put_contents($directory.'/locked.json', (string) json_encode(['github-oauth' => ['github.com' => 'locked-token']]));
    file_put_contents($directory.'/named.json', (string) json_encode(['github-oauth' => ['github.com' => 'named-token']]));
    file_put_contents($directory.'/composer-home/auth.json', 'not json');
    chmod($directory.'/locked.json', 0o000);

    $http = new FakeHttp([FakeHttp::body('zip'), FakeHttp::body('zip')]);
    app()->instance(ClientInterface::class, $http->client);

    $environment = [
        'VET_GITHUB_TOKEN' => null,
        'GITHUB_TOKEN' => null,
        'GH_TOKEN' => null,
        'XDG_CONFIG_HOME' => null,
        'HOME' => $directory,
        'COMPOSER_HOME' => $directory.'/composer-home',
    ];

    try {
        withEnvironment([...$environment, 'COMPOSER_AUTH_FILE' => $directory.'/locked.json'], static fn (): string => RequestUrl::default()->get('https://github.com/acme/skipped.zip'));
        withEnvironment([...$environment, 'COMPOSER_AUTH_FILE' => $directory.'/named.json'], static fn (): string => RequestUrl::default()->get('https://github.com/acme/named.zip'));
    } finally {
        chmod($directory.'/locked.json', 0o644);
        File::deleteDirectory($directory);
    }

    expect($http->header('https://github.com/acme/skipped.zip', 'Authorization'))->toBeNull()
        ->and($http->header('https://github.com/acme/named.zip', 'Authorization'))->toBe('Bearer named-token');
});

it('refuses a relative redirect from a url that holds no host', function (): void {
    $http = new FakeHttp([FakeHttp::redirect('next.zip')]);

    expect(fn (): string => (new RequestUrl('vet-test', [], $http->client))->get('https:widget.zip'))
        ->toThrow(FetchFailedException::class, 'Request to [next.zip] refused: vet reads https URLs only.');
});

it('names the directory of a download that it cannot create', function (): void {
    $directory = sys_get_temp_dir().'/vet-download-'.bin2hex(random_bytes(6));
    $request = new RequestUrl('vet-test', [], (new FakeHttp([]))->client);

    File::ensureDirectoryExists($directory);
    file_put_contents($directory.'/archives', 'a file where a directory belongs');

    try {
        expect(static function () use ($request, $directory): void {
            Warnings::silenced(static function () use ($request, $directory): void {
                $request->download('https://example.test/widget.zip', $directory.'/archives/widget.zip');
            });
        })->toThrow(FetchFailedException::class, sprintf('Request to [https://example.test/widget.zip] failed: could not create the directory [%s/archives].', $directory));
    } finally {
        File::deleteDirectory($directory);
    }
});

it('names the download that it cannot write', function (): void {
    $directory = sys_get_temp_dir().'/vet-download-'.bin2hex(random_bytes(6));
    $request = new RequestUrl('vet-test', [], (new FakeHttp([FakeHttp::body('zip bytes')]))->client);

    mkdir($directory);
    chmod($directory, 0o555);

    try {
        expect(static function () use ($request, $directory): void {
            Warnings::silenced(static function () use ($request, $directory): void {
                $request->download('https://example.test/widget.zip', $directory.'/widget.zip');
            });
        })->toThrow(FetchFailedException::class, sprintf('Request to [https://example.test/widget.zip] failed: could not write to [%s/widget.zip.', $directory));
    } finally {
        chmod($directory, 0o755);
        File::deleteDirectory($directory);
    }
});

it('names the download that it cannot move into place, and leaves no partial file', function (): void {
    $directory = sys_get_temp_dir().'/vet-download-'.bin2hex(random_bytes(6));
    $request = new RequestUrl('vet-test', [], (new FakeHttp([FakeHttp::body('zip bytes')]))->client);

    mkdir($directory.'/widget.zip/taken', 0o777, true);

    try {
        expect(static function () use ($request, $directory): void {
            Warnings::silenced(static function () use ($request, $directory): void {
                $request->download('https://example.test/widget.zip', $directory.'/widget.zip');
            });
        })->toThrow(FetchFailedException::class, sprintf('Request to [https://example.test/widget.zip] failed: could not move the download into [%s/widget.zip].', $directory));

        $files = glob($directory.'/*');
    } finally {
        File::deleteDirectory($directory);
    }

    expect($files)->toBe([$directory.'/widget.zip']);
});
