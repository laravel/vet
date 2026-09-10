<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Tests\PendingUpdate;

it('audits the bytes that composer would write, and does not pass them', function (): void {
    $project = PendingUpdate::create();
    $project->lockAt(PendingUpdate::TARGET_VERSION);

    try {
        $status = vet(['--path' => $project->rootPath]);
        $output = Artisan::output();
    } finally {
        $project->remove();
    }

    expect($status)->toBe(1)
        ->and($output)
        ->toContain('acme/widget 1.0.0 → 2.0.0')
        ->toContain('composer would install these bytes; you trust [1.0.0]')
        ->toContain('install-time manifest');
});

it('invites the audit command when it audits a plan', function (): void {
    $project = PendingUpdate::create();
    $project->lockAt(PendingUpdate::TARGET_VERSION);

    try {
        $status = vet([
            '--path' => $project->rootPath,
            '--plan' => $project->planFile(),
        ]);
        $output = Artisan::output();
    } finally {
        $project->remove();
    }

    expect($status)->toBe(1)
        ->and($output)
        ->toContain('Read every change with [vet -v]');
});

it('records the bytes of the next install, while vendor/ holds the old ones', function (): void {
    $project = PendingUpdate::create();
    $project->lockAt(PendingUpdate::TARGET_VERSION);

    try {
        trust(PendingUpdate::PACKAGE, ['--path' => $project->rootPath])
            ->expectsOutputToContain('Run [composer install] to write those bytes to vendor/.')
            ->assertExitCode(0)
            ->run();

        $trustFile = $project->trustFile();
        $targetHash = $project->targetHash();
        $installed = (string) file_get_contents($project->installedFile());

        $audited = vet([
            '--path' => $project->rootPath,
            '--plan' => $project->planFile(),
        ]);
    } finally {
        $project->remove();
    }

    expect($trustFile)
        ->toContain('"version": "2.0.0"')
        ->toContain($targetHash)
        ->and($installed)->toContain("return 'widget';")
        ->and($audited)->toBe(0);
});

it('records the rebuilt bytes of the same version, while vendor/ holds the old ones', function (): void {
    $project = PendingUpdate::create();
    $project->lockAt(PendingUpdate::TRUSTED_VERSION);

    try {
        $audited = vet(['--path' => $project->rootPath]);
        $auditOutput = Artisan::output();

        trust(PendingUpdate::PACKAGE, ['--path' => $project->rootPath])
            ->expectsOutputToContain('Recorded [acme/widget] [1.0.0]')
            ->expectsOutputToContain('Run [composer install] to write those bytes to vendor/.')
            ->assertExitCode(0)
            ->run();

        $trustFile = $project->trustFile();
        $rebuiltHash = $project->rebuiltHash();
    } finally {
        $project->remove();
    }

    expect($audited)->toBe(1)
        ->and($auditOutput)->toContain('composer would install [1.0.0] again, and its bytes changed')
        ->and($trustFile)
        ->toContain('"version": "1.0.0"')
        ->toContain($rebuiltHash);
});

it('shows the delta of the incoming bytes against the installed tree', function (): void {
    $project = PendingUpdate::create();
    $project->lockAt(PendingUpdate::TARGET_VERSION);

    try {
        $status = vet([
            'packages' => [PendingUpdate::PACKAGE],
            '--path' => $project->rootPath,
            '-v' => true,
        ]);
        $output = Artisan::output();
    } finally {
        $project->remove();
    }

    expect($status)->toBe(1)
        ->and($output)
        ->toContain('composer would write these bytes to vendor/')
        ->toContain('Record these bytes with [vet].')
        ->toContain('src/Widget.php')
        ->toContain("return 'gadget';")
        ->toContain('bin/widget.phar');
});

it('names the incoming bytes that it cannot read, and blocks them', function (): void {
    $project = PendingUpdate::create();
    $project->lockWithoutDist('2.0.0');

    try {
        $status = vet(['--path' => $project->rootPath]);
        $output = Artisan::output();

        $trusted = vet(['--fresh' => true, '--path' => $project->rootPath]);
        $trustOutput = Artisan::output();
    } finally {
        $project->remove();
    }

    expect($status)->toBe(1)
        ->and($output)
        ->toContain('bytes not readable')
        ->toContain('vet cannot read those bytes')
        ->toContain('has no dist URL')
        ->and($trusted)->toBe(1)
        ->and($trustOutput)->toContain('composer would write [1] package(s) that vendor/ does not hold');
});

it('audits the tree on disk when composer plans nothing', function (): void {
    $project = PendingUpdate::create();

    try {
        $status = vet(['--path' => $project->rootPath]);
        $output = Artisan::output();
    } finally {
        $project->remove();
    }

    expect($status)->toBe(0)
        ->and($output)->toContain('All [1] packages are covered.');
});
