<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Tests\Fixtures\Fixture;
use Tests\Fixtures\PendingUpdate;

it('records a pattern in the skip section, and does not compare that package', function (): void {
    $fixture = Fixture::open('stale-project');

    try {
        command('skip', ['packages' => ['acme/*'], '--path' => $fixture->rootPath])
            ->expectsOutputToContain('Skipped [acme/*] as unsafe, and wrote it to [skip] in [vet.json].')
            ->assertExitCode(0)
            ->run();

        command('vet', ['packages' => ['acme/widget'], '--path' => $fixture->rootPath])
            ->expectsOutputToContain('[acme/widget] is skipped, and that choice is unsafe. Vet does not compare it.')
            ->doesntExpectOutputToContain('~ src/Widget.php')
            ->assertExitCode(0)
            ->run();

        $trustFile = $fixture->read('vet.json');
        $status = vet(['--path' => $fixture->rootPath]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($trustFile)->toContain('"skip"')->toContain('acme/*')
        ->and($status)->toBe(0)
        ->and($output)->toContain('unsafe (1)')
        ->and($output)->toContain('skipped as unsafe')
        ->and($output)->toContain('1 unsafe')
        ->and($output)->not->toContain('~ src/Widget.php')
        ->and($output)->not->toContain('to review (');
});

it('records every pattern, and does not compare an incoming package', function (): void {
    $project = PendingUpdate::create();
    $project->lockAt(PendingUpdate::TARGET_VERSION);

    try {
        command('skip', ['packages' => ['laravel/*', 'acme/*'], '--path' => $project->rootPath])
            ->expectsOutputToContain('Skipped [laravel/*], [acme/*] as unsafe, and wrote them to [skip] in [vet.json].')
            ->assertExitCode(0)
            ->run();

        $status = vet([
            '--path' => $project->rootPath,
            '--plan' => $project->planFile(),
        ]);
        $output = Artisan::output();
    } finally {
        $project->remove();
    }

    expect($status)->toBe(0)
        ->and($output)->toContain('skipped as unsafe')
        ->and($output)->not->toContain('files changed');
});

it('records the packages you skip while vetting, and marks that choice as unsafe', function (): void {
    $fixture = Fixture::open('partly-audited');

    try {
        command('vet', ['--path' => $fixture->rootPath])
            ->expectsQuestion('How do you want to review these packages?', 'skip')
            ->expectsOutputToContain('Skipped [acme/widget], [acme/lint] as unsafe, and wrote them to [skip] in [vet.json].')
            ->assertExitCode(0)
            ->run();

        $trustFile = $fixture->read('vet.json');

        $status = vet(['--path' => $fixture->rootPath]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($trustFile)->toContain('acme/lint')->toContain('acme/widget')
        ->and($status)->toBe(0)
        ->and($output)->toContain('unsafe (2)')
        ->and($output)->not->toContain('to review (');
});

it('rejects a skip command that names no package', function (): void {
    command('skip', ['packages' => []])
        ->expectsOutputToContain('The [skip] command needs one package or one pattern, such as [laravel/*].')
        ->assertExitCode(1)
        ->run();
});

it('names the project that holds no composer.json', function (): void {
    $directory = sys_get_temp_dir().'/vet-'.bin2hex(random_bytes(6));

    mkdir($directory, 0o777, true);

    try {
        command('skip', ['packages' => ['acme/widget'], '--path' => $directory])
            ->expectsOutputToContain('the project manifest')
            ->assertExitCode(1)
            ->run();
    } finally {
        rmdir($directory);
    }
});
