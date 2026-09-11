<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\Fixtures\Fixture;

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
        $trusted = vet(['--init' => true, '--path' => $fixture->rootPath]);
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
        $status = vet(['--init' => true, '--from' => '1.0.0', '--path' => $fixture->rootPath]);
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

it('records the package that you pick, and the delta that you read', function (): void {
    $fixture = Fixture::open('stale-project');

    try {
        command('vet', ['--path' => $fixture->rootPath])
            ->expectsOutputToContain('to review (1, worst first)')
            ->expectsQuestion('How do you want to review these packages?', 'manual')
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
            ->expectsQuestion('How do you want to review these packages?', 'manual')
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
            ->expectsQuestion('How do you want to review these packages?', 'manual')
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
            ->expectsQuestion('How do you want to review these packages?', 'manual')
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

it('rejects --init when the user also names a package', function (): void {
    $fixture = Fixture::open('stale-project');

    try {
        $status = vet(['packages' => ['acme/widget'], '--init' => true, '--path' => $fixture->rootPath]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($status)->toBe(1)
        ->and($output)->toContain('The [--init] option takes no package. Run [vet --init] or [vet <package>].');
});

it('asks for vet --init and audits nothing when the project holds no trust file', function (): void {
    $fixture = Fixture::open('no-trust-file');

    try {
        command('vet', ['--path' => $fixture->rootPath, '--agent' => true])
            ->expectsOutputToContain('No trust file yet. Run [vet --init] to record every package that vendor/ holds today in [vet.json].')
            ->doesntExpectOutputToContain('to review')
            ->assertExitCode(1)
            ->run();

        $created = is_file($fixture->path('vet.json'));
    } finally {
        $fixture->remove();
    }

    expect($created)->toBeFalse();
});

it('records the baseline with --fresh, the alias of --init', function (): void {
    $fixture = Fixture::open('no-trust-file');

    try {
        $status = vet(['--fresh' => true, '--path' => $fixture->rootPath]);
        $trustFile = $fixture->read('vet.json');
    } finally {
        $fixture->remove();
    }

    expect($status)->toBe(0)
        ->and($trustFile)
        ->toContain('"acme/widget"')
        ->toContain('"acme/lint"');
});

it('trusts nothing new when the trust file covers every installed package', function (): void {
    $fixture = Fixture::open('audited-project');

    try {
        $status = vet(['--init' => true, '--path' => $fixture->rootPath]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($status)->toBe(0)
        ->and($output)->toContain('All [2] packages are already covered.');
});

it('refuses an option that needs one package when the user names two', function (string $option, string|bool $value, string $message): void {
    $fixture = Fixture::open('audited-project');

    try {
        $status = vet(['packages' => ['acme/widget', 'acme/lint'], '--path' => $fixture->rootPath, $option => $value]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($status)->toBe(1)
        ->and($output)->toContain($message);
})->with([
    '--from' => ['--from', '1.0.0', 'The [--from] and [--to] options need one package. Run [vet <package> --from=<version>].'],
    '--json' => ['--json', true, 'The [--json] option needs one package. Run [vet <package> --json].'],
]);

it('asks no question when the trust file covers every package', function (): void {
    $fixture = Fixture::open('audited-project');

    try {
        command('vet', ['--path' => $fixture->rootPath])
            ->expectsOutputToContain('All [2] packages are covered.')
            ->assertExitCode(0)
            ->run();
    } finally {
        $fixture->remove();
    }
});

it('names the trust file that it cannot write after you pick a package', function (): void {
    $fixture = Fixture::open('stale-project');

    chmod($fixture->path('vet.json'), 0o444);

    try {
        trust('acme/widget', ['--path' => $fixture->rootPath])
            ->expectsOutputToContain('Could not write the vet file')
            ->assertExitCode(1)
            ->run();
    } finally {
        chmod($fixture->path('vet.json'), 0o644);
        $fixture->remove();
    }
});

it('names the package directory that vendor holds no when you trust every package', function (): void {
    $fixture = Fixture::open('audited-project');

    File::deleteDirectory($fixture->path('vendor/acme/lint'));

    try {
        $status = vet(['--init' => true, '--path' => $fixture->rootPath]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($status)->toBe(1)
        ->and($output)
        ->toContain('vendor/acme/lint')
        ->toContain('does not exist');
});

it('names the trust file that it cannot write when you trust every package', function (): void {
    $fixture = Fixture::open('stale-project');

    chmod($fixture->path('vet.json'), 0o444);

    try {
        $status = vet(['--init' => true, '--path' => $fixture->rootPath]);
        $output = Artisan::output();
    } finally {
        chmod($fixture->path('vet.json'), 0o644);
        $fixture->remove();
    }

    expect($status)->toBe(1)
        ->and($output)->toContain('Could not write the vet file');
});

it('names the trust file that it cannot write the note of a covered package to', function (): void {
    $fixture = Fixture::open('audited-project');

    chmod($fixture->path('vet.json'), 0o444);

    try {
        $status = vet(['packages' => ['acme/widget'], '--notes' => 'Read it.', '--path' => $fixture->rootPath]);
        $output = Artisan::output();
    } finally {
        chmod($fixture->path('vet.json'), 0o644);
        $fixture->remove();
    }

    expect($status)->toBe(1)
        ->and($output)->toContain('Could not write the vet file');
});

it('records the note of two covered packages', function (): void {
    $fixture = Fixture::open('audited-project');

    try {
        $status = vet(['packages' => ['acme/widget', 'acme/lint'], '--notes' => 'Read with the team.', '--path' => $fixture->rootPath]);
        $output = Artisan::output();
        $trustFile = $fixture->read('vet.json');
    } finally {
        $fixture->remove();
    }

    expect($status)->toBe(0)
        ->and($output)->toContain('Recorded [2] package(s).')
        ->and(substr_count($trustFile, '"notes": "Read with the team."'))->toBe(2);
});
