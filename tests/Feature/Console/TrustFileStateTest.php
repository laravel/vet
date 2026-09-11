<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Tests\Fixtures\Fixture;

it('tells the user what to do with a trust file of an older schema', function (): void {
    $fixture = Fixture::open('legacy-trust-file');

    try {
        $status = vet(['--path' => $fixture->rootPath]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($status)->toBe(1)
        ->and($output)
        ->toContain('declares schema [2]')
        ->toContain('Delete the file and run [vet --init] again.');
});

it('tells the user to record the trust file again when it holds a truncated hash', function (): void {
    $fixture = Fixture::open('truncated-trust-file');

    try {
        $status = vet(['--path' => $fixture->rootPath]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($status)->toBe(1)
        ->and($output)
        ->toContain('declares schema [3]')
        ->toContain('recorded a truncated tree hash')
        ->toContain('Delete the file and run [vet --init] again.')
        ->and(str_contains($output, 'Malformed tree hash digest'))->toBeFalse();
});

it('names the entry that holds no hash', function (): void {
    $fixture = Fixture::open('broken-trust-file');

    try {
        $status = vet(['--path' => $fixture->rootPath]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($status)->toBe(1)
        ->and($output)->toContain('The entry for [acme/widget] needs a [version] and a [hash].');
});

it('ignores an entry of a package that the project does not install', function (): void {
    $fixture = Fixture::open('orphan-trust-file');

    try {
        $status = vet(['--path' => $fixture->rootPath]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($status)->toBe(0)
        ->and($output)->toContain('All [1] packages are trusted.');
});

it('writes the dev package of a baseline in require-dev', function (): void {
    $fixture = Fixture::open('no-trust-file');

    try {
        $status = vet(['--init' => true, '--path' => $fixture->rootPath]);

        /** @var array{require: array<string, mixed>, require-dev: array<string, mixed>} $trustFile */
        $trustFile = json_decode($fixture->read('vet.json'), true);
    } finally {
        $fixture->remove();
    }

    expect($status)->toBe(0)
        ->and($trustFile['require'])->toHaveKey('acme/widget')
        ->and($trustFile['require-dev'])->toHaveKey('acme/lint');
});

it('names the schema that a trust file of a later build declares', function (): void {
    $fixture = Fixture::open('future-trust-file');

    try {
        $status = vet(['--path' => $fixture->rootPath]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($status)->toBe(1)
        ->and($output)
        ->toContain('declares schema [99]')
        ->toContain('this build of vet reads schema [4]')
        ->and(str_contains($output, 'Delete the file and run [vet --init] again.'))->toBeFalse();
});

it('names the tree hash algorithm that this build does not read', function (): void {
    $fixture = Fixture::open('unknown-hash-algorithm');

    try {
        $status = vet(['--path' => $fixture->rootPath]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($status)->toBe(1)
        ->and($output)
        ->toContain('Unknown tree hash algorithm [tree-v1]')
        ->toContain('this build of vet understands [tree-v2]');
});

it('names the trust file that holds no valid json', function (): void {
    $fixture = Fixture::open('invalid-trust-file');

    try {
        $status = vet(['--path' => $fixture->rootPath]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($status)->toBe(1)
        ->and($output)
        ->toContain('vet.json')
        ->toContain('does not contain valid JSON');
});

it('moves the entry of a dev package into require-dev when the user records it again', function (): void {
    $fixture = Fixture::open('dev-section-drift');

    try {
        trust('acme/lint', ['--path' => $fixture->rootPath])->run();

        /** @var array{require: array<string, mixed>, require-dev: array<string, mixed>} $trustFile */
        $trustFile = json_decode($fixture->read('vet.json'), true);
    } finally {
        $fixture->remove();
    }

    expect($trustFile['require'])->toBe([])
        ->and($trustFile['require-dev'])->toHaveKey('acme/lint');
});

it('reports a package that the trust file already covers', function (): void {
    $fixture = Fixture::open('partly-audited');

    try {
        trust('acme/widget', ['--path' => $fixture->rootPath])->run();

        $status = vet(['packages' => ['acme/widget'], '--path' => $fixture->rootPath]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($status)->toBe(0)
        ->and($output)->toContain('[acme/widget] [2.0.0] is already trusted at');
});
