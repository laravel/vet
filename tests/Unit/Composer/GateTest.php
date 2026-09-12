<?php

declare(strict_types=1);

use App\ValueObjects\ComposerPlan;
use Laravel\Vet\Composer\Gate;

function gateProject(bool $trustFile, bool $binary): Gate
{
    $root = sys_get_temp_dir().'/vet-'.bin2hex(random_bytes(6));
    $binDir = $root.'/vendor/bin';

    mkdir($binDir, 0o777, true);

    if ($trustFile) {
        file_put_contents($root.'/vet.json', '{"schema":3}');
    }

    if ($binary) {
        file_put_contents($binDir.'/vet', "#!/usr/bin/env php\n");
    }

    register_shutdown_function(static function () use ($root): void {
        @unlink($root.'/vet.json');
        @unlink($root.'/vendor/bin/vet');
        @rmdir($root.'/vendor/bin');
        @rmdir($root.'/vendor');
        @rmdir($root);
    });

    return new Gate($root, $binDir, $root.'/vendor');
}

it('runs the audit in color when the project holds a trust file and the binary', function (): void {
    $gate = gateProject(trustFile: true, binary: true);

    expect($gate->command(verbose: false, decorated: true))
        ->toBe([PHP_BINARY, $gate->rootPath.'/vendor/bin/vet', '--ansi'])
        ->and($gate->baselineNotice())->toBeNull();
});

it('passes the verbosity of composer to the audit', function (): void {
    $gate = gateProject(trustFile: true, binary: true);

    expect($gate->command(verbose: true, decorated: true))
        ->toBe([PHP_BINARY, $gate->rootPath.'/vendor/bin/vet', '--ansi', '-v']);
});

it('tells the audit that composer runs it', function (): void {
    expect(gateProject(trustFile: true, binary: true)->environment())
        ->toBe([Gate::ENVIRONMENT => '1']);
});

it('asks for a baseline rather than fail a project that holds no trust file', function (): void {
    $gate = gateProject(trustFile: false, binary: true);

    expect($gate->command(verbose: false, decorated: true))->toBe([])
        ->and($gate->baselineNotice())->toContain('vet --init');
});

it('does nothing when the binary is gone', function (): void {
    $gate = gateProject(trustFile: true, binary: false);

    expect($gate->binary())->toBeNull()
        ->and($gate->command(verbose: false, decorated: true))->toBe([])
        ->and($gate->baselineNotice())->toBeNull();
});

it('reads the binary of the repository of vet itself', function (): void {
    $root = dirname(__DIR__, 3);

    expect(new Gate($root, $root.'/vendor/bin', $root.'/vendor')->binary())->toBe($root.'/vet');
});

it('gives the audit the plan that composer holds', function (): void {
    $gate = gateProject(trustFile: true, binary: true);

    $path = $gate->writePlan([[
        'package' => 'acme/widget',
        'change' => 'upgrade',
        'from' => '1.0.0',
        'to' => '2.0.0',
        'dist_url' => 'https://example.test/widget-2.0.0.zip',
        'dist_reference' => 'bbbb2222',
    ]]);

    expect($path)->toBeString()
        ->and($gate->commandWithPlan(verbose: false, decorated: true, planPath: (string) $path))
        ->toBe([PHP_BINARY, $gate->rootPath.'/vendor/bin/vet', '--ansi', '--plan='.$path]);

    $plan = ComposerPlan::fromFile((string) $path);

    expect($plan->of('acme/widget')?->to)->toBe('2.0.0')
        ->and($plan->of('acme/widget')?->distReference)->toBe('bbbb2222');

    $gate->deletePlan((string) $path);

    expect(is_file((string) $path))->toBeFalse();
});

it('reads whether the project installs a tree today', function (): void {
    $gate = gateProject(trustFile: true, binary: true);

    expect($gate->hasInstalledTree())->toBeFalse()
        ->and($gate->firstInstallNotice())->toContain('the audit runs after this install');

    $installed = $gate->rootPath.'/vendor/composer';

    mkdir($installed, 0o777, true);
    file_put_contents($installed.'/installed.json', '{"packages":[]}');

    try {
        expect($gate->hasInstalledTree())->toBeTrue()
            ->and($gate->firstInstallNotice())->toBeNull();
    } finally {
        unlink($installed.'/installed.json');
        rmdir($installed);
    }
});

it('knows that it runs inside a composer that vet started', function (): void {
    $gate = gateProject(trustFile: true, binary: true);

    putenv(Gate::ENVIRONMENT.'=1');

    try {
        expect($gate->nested())->toBeTrue();
    } finally {
        putenv(Gate::ENVIRONMENT);
    }

    expect($gate->nested())->toBeFalse();
});

it('runs the audit without color when composer writes no color', function (): void {
    $gate = gateProject(trustFile: true, binary: true);

    expect($gate->command(verbose: false, decorated: false))
        ->toBe([PHP_BINARY, $gate->rootPath.'/vendor/bin/vet', '--no-ansi']);
});

it('writes no plan that json cannot hold', function (): void {
    $gate = gateProject(trustFile: true, binary: true);

    expect($gate->writePlan([['package' => "acme/\xB1widget", 'change' => 'install']]))->toBeNull();
});

it('reads the installed tree in the vendor directory that composer configures', function (): void {
    $gate = gateProject(trustFile: true, binary: true);
    $configured = new Gate($gate->rootPath, $gate->rootPath.'/vendor/bin', $gate->rootPath.'/libraries');

    mkdir($gate->rootPath.'/libraries/composer', 0o777, true);
    file_put_contents($gate->rootPath.'/libraries/composer/installed.json', '{"packages":[]}');

    try {
        expect($configured->hasInstalledTree())->toBeTrue()
            ->and($configured->firstInstallNotice())->toBeNull()
            ->and($gate->hasInstalledTree())->toBeFalse();
    } finally {
        unlink($gate->rootPath.'/libraries/composer/installed.json');
        rmdir($gate->rootPath.'/libraries/composer');
        rmdir($gate->rootPath.'/libraries');
    }
});
