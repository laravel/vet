<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Tests\Fixture;

it('records the review of one package, and turns the gate green', function (): void {
    $fixture = Fixture::open('stale-project');

    try {
        $trusted = Artisan::call('trust', ['packages' => ['acme/widget'], '--path' => $fixture->rootPath]);
        $trustOutput = Artisan::output();

        $trustFile = $fixture->read('vet.json');

        $audited = Artisan::call('audit', ['--path' => $fixture->rootPath]);
        $auditOutput = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($trusted)->toBe(0)
        ->and($trustOutput)
        ->toContain('acme/widget')
        ->toContain('bytes changed')
        ->toContain('~ src/Widget.php')
        ->toContain('Recorded [acme/widget] [2.0.0]')
        ->and($trustFile)->toContain('"version": "2.0.0"')
        ->and($audited)->toBe(0)
        ->and($auditOutput)->toContain('All [1] packages are covered.');
});

it('baselines every installed package of a project that holds no trust file', function (): void {
    $fixture = Fixture::open('stale-project');

    unlink($fixture->path('vet.json'));

    try {
        $trusted = Artisan::call('trust', ['--path' => $fixture->rootPath]);
        $trustOutput = Artisan::output();

        $audited = Artisan::call('audit', ['--path' => $fixture->rootPath]);
    } finally {
        $fixture->remove();
    }

    expect($trusted)->toBe(0)
        ->and($trustOutput)
        ->toContain('to trust (1)')
        ->toContain('no entry')
        ->toContain('wrote [vet.json]')
        ->and($audited)->toBe(0);
});

it('rejects --from when the user names no package', function (): void {
    $fixture = Fixture::open('stale-project');

    try {
        $status = Artisan::call('trust', ['--from' => '1.0.0', '--path' => $fixture->rootPath]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($status)->toBe(1)
        ->and($output)->toContain('The --from option needs one package.');
});
