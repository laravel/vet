<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Tests\Fixture;
use Tests\PendingUpdate;

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
        $trusted = Artisan::call('trust', ['--all' => true, '--path' => $fixture->rootPath]);
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

it('rejects --from when the user trusts every package', function (): void {
    $fixture = Fixture::open('stale-project');

    try {
        $status = Artisan::call('trust', ['--all' => true, '--from' => '1.0.0', '--path' => $fixture->rootPath]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($status)->toBe(1)
        ->and($output)->toContain('The [--from] option needs one package.');
});

it('asks for a package name when nobody can answer a question', function (): void {
    $fixture = Fixture::open('stale-project');

    try {
        $status = Artisan::call('trust', ['--path' => $fixture->rootPath, '--no-interaction' => true]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($status)->toBe(1)
        ->and($output)->toContain('Run [vet trust <package>] or [vet trust --all].');
});

it('refuses the bytes that composer would write, and records the installed ones', function (): void {
    $project = PendingUpdate::create();
    $project->lockAt(PendingUpdate::TARGET_VERSION);

    try {
        $status = Artisan::call('trust', ['--all' => true, '--path' => $project->rootPath]);
        $output = Artisan::output();

        $trustFile = $project->trustFile();
    } finally {
        $project->remove();
    }

    expect($status)->toBe(1)
        ->and($output)
        ->toContain('to read first (1)')
        ->toContain('composer would write [1] package(s) that vendor/ does not hold. Read them with [vet trust], or run [composer install] first.')
        ->and($trustFile)->toContain('"version": "1.0.0"');
});

it('records the package that you pick, and the delta that you read', function (): void {
    $fixture = Fixture::open('stale-project');

    try {
        command('trust', ['--path' => $fixture->rootPath])
            ->expectsQuestion('Which packages do you trust?', ['acme/widget'])
            ->expectsOutputToContain('~ src/Widget.php')
            ->expectsQuestion('Do you trust [acme/widget] [2.0.0]?', 'yes')
            ->expectsOutputToContain('Recorded [acme/widget] [2.0.0]')
            ->assertExitCode(0)
            ->run();

        $trustFile = $fixture->read('vet.json');
    } finally {
        $fixture->remove();
    }

    expect($trustFile)->toContain('"version": "2.0.0"');
});

it('records nothing when you answer no', function (): void {
    $fixture = Fixture::open('stale-project');

    try {
        command('trust', ['--path' => $fixture->rootPath])
            ->expectsQuestion('Which packages do you trust?', ['acme/widget'])
            ->expectsQuestion('Do you trust [acme/widget] [2.0.0]?', 'no')
            ->expectsOutputToContain('Recorded nothing.')
            ->assertExitCode(0)
            ->run();

        $trustFile = $fixture->read('vet.json');
    } finally {
        $fixture->remove();
    }

    expect($trustFile)->toContain('"version": "1.0.0"');
});

it('records nothing when you pick nothing', function (): void {
    $fixture = Fixture::open('stale-project');

    try {
        command('trust', ['--path' => $fixture->rootPath])
            ->expectsQuestion('Which packages do you trust?', [])
            ->expectsOutputToContain('Recorded nothing.')
            ->assertExitCode(0)
            ->run();
    } finally {
        $fixture->remove();
    }
});

it('records the note that you write', function (): void {
    $fixture = Fixture::open('stale-project');

    try {
        command('trust', ['--path' => $fixture->rootPath])
            ->expectsQuestion('Which packages do you trust?', ['acme/widget'])
            ->expectsQuestion('Do you trust [acme/widget] [2.0.0]?', 'notes')
            ->expectsQuestion('The note that vet records', 'I read every line.')
            ->assertExitCode(0)
            ->run();

        $trustFile = $fixture->read('vet.json');
    } finally {
        $fixture->remove();
    }

    expect($trustFile)
        ->toContain('"version": "2.0.0"')
        ->toContain('"notes": "I read every line."');
});

it('rejects --all when the user also names a package', function (): void {
    $fixture = Fixture::open('stale-project');

    try {
        $status = Artisan::call('trust', ['packages' => ['acme/widget'], '--all' => true, '--path' => $fixture->rootPath]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($status)->toBe(1)
        ->and($output)->toContain('The [--all] option takes no package.');
});
