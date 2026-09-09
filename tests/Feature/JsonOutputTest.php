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
        Artisan::call('audit', ['--path' => $fixture->rootPath, '--json' => true]);
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
        Artisan::call('audit', ['package' => 'acme/opaque', '--path' => $fixture->rootPath, '--json' => true]);
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

it('writes the preview plan as json with the exact path of every file', function (): void {
    $fixture = Fixture::open('pending-update');

    $archives = glob($fixture->cachePath.'/archives/acme-widget/2.0.0-*', GLOB_ONLYDIR) ?: [];

    expect($archives)->not->toBeEmpty();

    plantEvilFile($archives[0]);

    try {
        Artisan::call('preview', ['--path' => $fixture->rootPath, '--json' => true]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    $plan = json_decode($output, true);

    expect($plan)->toBeArray();

    /** @var array{packages: array<int, array{delta: mixed}>} $plan */
    expect(changedPaths($plan['packages'][0]['delta']))->toContain(evilPath())
        ->and(str_contains($output, 'src//Evil.php'))->toBeFalse();
});
