<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Tests\Fixture;
use Tests\PendingUpdate;

it('records the review of one package, and turns the gate green', function (): void {
    $fixture = Fixture::open('stale-project');

    try {
        trust('acme/widget', ['--path' => $fixture->rootPath])
            ->expectsOutputToContain('~ src/Widget.php')
            ->expectsOutputToContain('Recorded [acme/widget] [2.0.0]')
            ->assertExitCode(0)
            ->run();

        $trustFile = $fixture->read('vet.json');

        $audited = vet(['--path' => $fixture->rootPath]);
        $auditOutput = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($trustFile)->toContain('"version": "2.0.0"')
        ->and($audited)->toBe(0)
        ->and($auditOutput)->toContain('All [1] packages are covered.');
});

it('audits one package, and records nothing', function (): void {
    $fixture = Fixture::open('stale-project');

    try {
        command('vet', ['packages' => ['acme/widget'], '--path' => $fixture->rootPath])
            ->expectsOutputToContain('~ src/Widget.php')
            ->expectsOutputToContain('Record these bytes with [vet].')
            ->assertExitCode(1)
            ->run();

        $trustFile = $fixture->read('vet.json');
    } finally {
        $fixture->remove();
    }

    expect($trustFile)->toContain('"version": "1.0.0"');
});

it('refuses a note for a package that it does not cover', function (): void {
    $fixture = Fixture::open('stale-project');

    try {
        $status = vet(['packages' => ['acme/widget'], '--notes' => 'Read it.', '--path' => $fixture->rootPath]);
        $output = Artisan::output();
        $trustFile = $fixture->read('vet.json');
    } finally {
        $fixture->remove();
    }

    expect($status)->toBe(1)
        ->and($output)->toContain('[acme/widget] is not covered, so vet holds no entry for the note. Run [vet] to record it first.')
        ->and($trustFile)->toContain('"version": "1.0.0"');
});

it('baselines every installed package of a project that holds no trust file', function (): void {
    $fixture = Fixture::open('stale-project');

    unlink($fixture->path('vet.json'));

    try {
        $trusted = vet(['--fresh' => true, '--path' => $fixture->rootPath]);
        $trustOutput = Artisan::output();

        $audited = vet(['--path' => $fixture->rootPath]);
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
        $status = vet(['--fresh' => true, '--from' => '1.0.0', '--path' => $fixture->rootPath]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($status)->toBe(1)
        ->and($output)->toContain('The [--from] and [--to] options need one package.');
});

it('audits without a question when nobody can answer one', function (): void {
    $fixture = Fixture::open('stale-project');

    try {
        $status = vet(['--path' => $fixture->rootPath]);
        $output = Artisan::output();
        $trustFile = $fixture->read('vet.json');
    } finally {
        $fixture->remove();
    }

    expect($status)->toBe(1)
        ->and($output)->toContain('Run [vet] in a terminal to record the ones that you trust.')
        ->and($trustFile)->toContain('"version": "1.0.0"');
});

it('refuses the bytes that composer would write, and records the installed ones', function (): void {
    $project = PendingUpdate::create();
    $project->lockAt(PendingUpdate::TARGET_VERSION);

    try {
        $status = vet(['--fresh' => true, '--path' => $project->rootPath]);
        $output = Artisan::output();

        $trustFile = $project->trustFile();
    } finally {
        $project->remove();
    }

    expect($status)->toBe(1)
        ->and($output)
        ->toContain('to read first (1)')
        ->toContain('composer would write [1] package(s) that vendor/ does not hold. Run [vet] in a terminal to read them, or run [composer install] first.')
        ->and($trustFile)->toContain('"version": "1.0.0"');
});

it('records the package that you pick, and the delta that you read', function (): void {
    $fixture = Fixture::open('stale-project');

    try {
        command('vet', ['--path' => $fixture->rootPath])
            ->expectsOutputToContain('to review (1, worst first)')
            ->expectsQuestion('How do you want to read these packages?', 'pick')
            ->expectsQuestion('Which packages do you trust?', ['acme/widget'])
            ->expectsOutputToContain('~ src/Widget.php')
            ->expectsOutputToContain('Recorded [acme/widget] [2.0.0]')
            ->assertExitCode(0)
            ->run();

        $trustFile = $fixture->read('vet.json');
    } finally {
        $fixture->remove();
    }

    expect($trustFile)->toContain('"version": "2.0.0"');
});

it('fails when you pick nothing', function (): void {
    $fixture = Fixture::open('stale-project');

    try {
        command('vet', ['--path' => $fixture->rootPath])
            ->expectsQuestion('How do you want to read these packages?', 'pick')
            ->expectsQuestion('Which packages do you trust?', [])
            ->expectsOutputToContain('Recorded nothing.')
            ->assertExitCode(1)
            ->run();
    } finally {
        $fixture->remove();
    }
});

it('fails when you record one package of two, because the other stays uncovered', function (): void {
    $fixture = Fixture::open('partly-audited');

    try {
        command('vet', ['--path' => $fixture->rootPath])
            ->expectsQuestion('How do you want to read these packages?', 'pick')
            ->expectsQuestion('Which packages do you trust?', ['acme/widget'])
            ->expectsOutputToContain('Recorded [acme/widget] [2.0.0]')
            ->assertExitCode(1)
            ->run();

        $trustFile = $fixture->read('vet.json');
    } finally {
        $fixture->remove();
    }

    expect($trustFile)
        ->toContain('"version": "2.0.0"')
        ->and(str_contains($trustFile, 'acme/lint'))->toBeFalse();
});

it('records the note that you give', function (): void {
    $fixture = Fixture::open('stale-project');

    try {
        command('vet', ['--notes' => 'I read every line.', '--path' => $fixture->rootPath])
            ->expectsQuestion('How do you want to read these packages?', 'pick')
            ->expectsQuestion('Which packages do you trust?', ['acme/widget'])
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

it('hands every delta to the agent when you ask for it, then lets you pick', function (): void {
    $fixture = Fixture::open('stale-project');
    $fixture->agent('cat > /dev/null'."\n".'echo \'{"verdict":"clear","summary":"the delta renames one method","findings":[]}\'');

    try {
        command('vet', ['--path' => $fixture->rootPath])
            ->expectsQuestion('How do you want to read these packages?', 'agent')
            ->expectsOutputToContain('Reading [1] delta(s) with [agent].')
            ->expectsOutputToContain('agent  partial  [1] file(s) did not reach the agent. the delta renames one method')
            ->expectsQuestion('Which packages do you trust?', ['acme/widget'])
            ->assertExitCode(0)
            ->run();

        $trustFile = $fixture->read('vet.json');
    } finally {
        $fixture->remove();
    }

    expect($trustFile)->toContain('"version": "2.0.0"');
});

it('asks no question about the agent when the flag names it', function (): void {
    $fixture = Fixture::open('stale-project');
    $fixture->agent('cat > /dev/null'."\n".'echo \'{"verdict":"clear","summary":"the delta renames one method","findings":[]}\'');

    try {
        command('vet', ['--path' => $fixture->rootPath, '--agent' => true])
            ->expectsOutputToContain('agent  partial  [1] file(s) did not reach the agent. the delta renames one method')
            ->expectsQuestion('Which packages do you trust?', [])
            ->assertExitCode(1)
            ->run();
    } finally {
        $fixture->remove();
    }
});

it('rejects --fresh when the user also names a package', function (): void {
    $fixture = Fixture::open('stale-project');

    try {
        $status = vet(['packages' => ['acme/widget'], '--fresh' => true, '--path' => $fixture->rootPath]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($status)->toBe(1)
        ->and($output)->toContain('The [--fresh] option takes no package. Run [vet --fresh] or [vet <package>].');
});

it('shows the selector at once when the project holds no trust file', function (): void {
    $fixture = Fixture::open('no-trust-file');

    try {
        command('vet', ['--path' => $fixture->rootPath])
            ->expectsOutputToContain('No trust file yet. Pick the packages that you trust today, and vet writes them to [vet.json].')
            ->doesntExpectOutputToContain('to review')
            ->expectsQuestion('Which packages do you trust?', ['acme/widget', 'acme/lint'])
            ->expectsOutputToContain('Recorded [2] package(s).')
            ->assertExitCode(0)
            ->run();

        $trustFile = $fixture->read('vet.json');
    } finally {
        $fixture->remove();
    }

    expect($trustFile)
        ->toContain('"acme/widget"')
        ->toContain('"acme/lint"');
});
