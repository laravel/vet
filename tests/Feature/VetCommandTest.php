<?php

declare(strict_types=1);

use App\Actions\CacheArtifact;
use App\Actions\ColdCacheArtifact;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
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

it('refuses the bytes that composer would write, and records the installed ones', function (): void {
    $project = PendingUpdate::create();
    $project->lockAt(PendingUpdate::TARGET_VERSION);

    try {
        $status = vet(['--init' => true, '--path' => $project->rootPath]);
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

it('hands every delta to the agent when you ask for it, then lets you pick', function (): void {
    $fixture = Fixture::open('stale-project');
    $fixture->agent('cat > /dev/null'."\n".'echo \'{"verdict":"clear","summary":"the delta renames one method","findings":[]}\'');

    try {
        command('vet', ['--path' => $fixture->rootPath])
            ->expectsQuestion('How do you want to review these packages?', 'agent')
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

it('refuses an option that needs one package when the user names two', function (array $options, string $message): void {
    $fixture = Fixture::open('audited-project');

    try {
        $status = vet(['packages' => ['acme/widget', 'acme/lint'], '--path' => $fixture->rootPath, ...$options]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($status)->toBe(1)
        ->and($output)->toContain($message);
})->with([
    '--from' => [['--from' => '1.0.0'], 'The [--from] and [--to] options need one package. Run [vet <package> --from=<version>].'],
    '--json' => [['--json' => true], 'The [--json] option needs one package. Run [vet <package> --json].'],
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

it('offers no package to pick when vet cannot read the bytes of any package', function (): void {
    $project = PendingUpdate::create();
    $project->lockWithoutDist(PendingUpdate::TARGET_VERSION);

    try {
        command('vet', ['--path' => $project->rootPath])
            ->expectsOutputToContain('bytes not readable')
            ->expectsQuestion('How do you want to review these packages?', 'manual')
            ->assertExitCode(1)
            ->run();
    } finally {
        $project->remove();
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

it('names the agent that it cannot run, and still lets you pick', function (): void {
    $fixture = Fixture::open('stale-project');

    putenv('VET_AGENT_BINARY=agent-that-is-not-installed');

    try {
        command('vet', ['--path' => $fixture->rootPath])
            ->expectsQuestion('How do you want to review these packages?', 'agent')
            ->expectsOutputToContain('Could not run [agent-that-is-not-installed].')
            ->expectsQuestion('Which packages do you trust?', ['acme/widget'])
            ->expectsOutputToContain('Recorded [acme/widget] [2.0.0]')
            ->assertExitCode(0)
            ->run();
    } finally {
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

it('records the note of the covered bytes that composer would write', function (): void {
    $project = PendingUpdate::create();
    $project->lockAt(PendingUpdate::TARGET_VERSION);

    try {
        trust(PendingUpdate::PACKAGE, ['--path' => $project->rootPath])->run();

        $status = vet(['packages' => [PendingUpdate::PACKAGE], '--notes' => 'Read the installer.', '--path' => $project->rootPath]);
        $output = Artisan::output();
        $trustFile = $project->trustFile();
    } finally {
        $project->remove();
    }

    expect($status)->toBe(0)
        ->and($output)
        ->toContain('Recorded [acme/widget] [2.0.0]')
        ->toContain('Run [composer install] to write those bytes to vendor/.')
        ->and($trustFile)->toContain('"notes": "Read the installer."');
});

it('names the incoming bytes of one package that it cannot read', function (): void {
    $project = PendingUpdate::create();
    $project->lockWithoutDist(PendingUpdate::TARGET_VERSION);

    try {
        $status = vet(['packages' => [PendingUpdate::PACKAGE], '--path' => $project->rootPath]);
        $output = Artisan::output();
    } finally {
        $project->remove();
    }

    expect($status)->toBe(1)
        ->and($output)->toContain('has no dist URL');
});

it('shows no delta of the incoming bytes when the installed tree holds no file', function (): void {
    $project = PendingUpdate::create();
    $project->lockAt(PendingUpdate::TARGET_VERSION);

    $installed = dirname($project->installedFile(), 2);

    File::deleteDirectory($installed);
    mkdir($installed);

    try {
        $status = vet(['packages' => [PendingUpdate::PACKAGE], '--path' => $project->rootPath]);
        $output = Artisan::output();
    } finally {
        $project->remove();
    }

    expect($status)->toBe(1)
        ->and($output)
        ->toContain('composer would write these bytes to vendor/')
        ->toContain('Record these bytes with [vet].')
        ->and(str_contains($output, 'src/Widget.php'))->toBeFalse();
});

it('warns that it cannot build the delta from the trusted version of one package', function (): void {
    $fixture = Fixture::open('no-dist');

    try {
        $status = vet(['packages' => ['acme/widget'], '--path' => $fixture->rootPath]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($status)->toBe(1)
        ->and($output)
        ->toContain('Could not build the delta from the granted [1.0.0]')
        ->toContain('has no dist URL');
});

it('names the agent that it cannot run for one package', function (): void {
    $fixture = Fixture::open('stale-project');

    putenv('VET_AGENT_BINARY=agent-that-is-not-installed');

    try {
        $status = vet(['packages' => ['acme/widget'], '--agent' => true, '--path' => $fixture->rootPath]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($status)->toBe(1)
        ->and($output)->toContain('Could not run [agent-that-is-not-installed].');
});

it('sends nothing to the agent when the delta of one package holds no change', function (): void {
    $fixture = Fixture::open('delta-shapes');
    $fixture->agent("cat > /dev/null\nexit 1");

    try {
        vet(['packages' => ['acme/moved'], '--from' => '2.0.0', '--agent' => true, '--path' => $fixture->rootPath]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($output)
        ->toContain('agent  not sent  This delta holds no change, so vet sent nothing.')
        ->toContain('No files differ between [2.0.0] and [2.0.0].');
});

it('refuses the cache when the user gives --no-cache', function (): void {
    $fixture = Fixture::open('audited-project');

    try {
        $status = vet(['--no-cache' => true, '--path' => $fixture->rootPath]);
    } finally {
        $fixture->remove();
    }

    expect($status)->toBe(0)
        ->and(app(CacheArtifact::class))->toBeInstanceOf(ColdCacheArtifact::class);
});
