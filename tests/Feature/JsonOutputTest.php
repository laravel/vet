<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Tests\Fixture;

function evilPath(): string
{
    return 'src/<info>/Evil.php';
}

function plantEvilFile(string $directory): void
{
    mkdir($directory.'/src/<info>', 0o777, true);

    file_put_contents($directory.'/'.evilPath(), "<?php\n\nclass Evil {}\n");
}

/**
 * @return array<int, string>
 */
function changedPaths(mixed $delta): array
{
    expect($delta)->toBeArray();

    /** @var array{changes: array<int, array{path: string}>} $delta */
    return array_column($delta['changes'], 'path');
}

it('writes the audit report as json with the exact path of every file', function (): void {
    $fixture = Fixture::open('delta-shapes');

    plantEvilFile($fixture->path('vendor/acme/opaque'));

    try {
        vet(['--path' => $fixture->rootPath, '--json' => true]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    $report = json_decode($output, true);

    expect($report)->toBeArray();

    /** @var array{unaudited: array<int, array{package: string, delta: mixed}>} $report */
    $paths = [];

    foreach ($report['unaudited'] as $package) {
        if ($package['package'] === 'acme/opaque') {
            $paths = changedPaths($package['delta']);
        }
    }

    expect($paths)->toContain(evilPath())
        ->and(str_contains($output, 'src//Evil.php'))->toBeFalse();
});

it('writes the audit of one package as json with the exact path of every file', function (): void {
    $fixture = Fixture::open('delta-shapes');

    plantEvilFile($fixture->path('vendor/acme/opaque'));

    try {
        vet(['packages' => ['acme/opaque'], '--path' => $fixture->rootPath, '--json' => true]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    $audit = json_decode($output, true);

    expect($audit)->toBeArray();

    /** @var array{delta: mixed} $audit */
    expect(changedPaths($audit['delta']))->toContain(evilPath())
        ->and(str_contains($output, 'src//Evil.php'))->toBeFalse();
});

it('writes the status of the package that it audits as json', function (): void {
    $fixture = Fixture::open('delta-shapes');

    try {
        vet(['packages' => ['acme/moved'], '--path' => $fixture->rootPath, '--json' => true]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    /** @var array{status: string, state: string} $audit */
    $audit = json_decode($output, true);

    expect($audit['status'])->toBe('changed')
        ->and($audit['state'])->toBe('installed');
});

it('writes each lock discrepancy as data, and writes no bracket in it', function (): void {
    $fixture = Fixture::open('lock-drift');

    $plan = composerPlanFile([[
        'package' => 'acme/unrelated',
        'change' => 'install',
        'from' => null,
        'to' => '1.0.0',
    ]]);

    try {
        vet(['--path' => $fixture->rootPath, '--json' => true, '--plan' => $plan]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    $report = json_decode($output, true);

    expect($report)->toBeArray();

    /** @var array{lock_discrepancies: array<int, array<string, string>>} $report */
    expect($report['lock_discrepancies'])
        ->toContain(['type' => 'not-installed', 'package' => 'acme/ghost', 'locked' => '1.0.0'])
        ->toContain(['type' => 'not-locked', 'package' => 'acme/extra', 'installed' => '1.0.0']);
});

it('writes the scope of a review as a machine value', function (): void {
    $fixture = Fixture::open('partly-audited');

    try {
        vet(['--path' => $fixture->rootPath, '--json' => true]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    $report = json_decode($output, true);

    expect($report)->toBeArray();

    /** @var array{unaudited: array<int, array{package: string, scope: string}>} $report */
    $scopes = array_column($report['unaudited'], 'scope', 'package');

    expect($scopes)->toBe(['acme/widget' => 'delta', 'acme/lint' => 'whole-package']);
});
