<?php

declare(strict_types=1);

use App\Actions\CacheArtifact;
use App\Actions\ColdCacheArtifact;
use App\Composer\Gate;
use Illuminate\Support\Facades\Artisan;
use Tests\Fixtures\Fixture;
use Tests\Fixtures\StaleProject;

it('covers every package of an audited project, and reaches no network', function (): void {
    $fixture = Fixture::open('audited-project');

    try {
        $status = vet(['--path' => $fixture->rootPath]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($status)->toBe(0)
        ->and($output)->toContain('All [2] packages are covered.');
});

it('reports one package of an audited project without a delta', function (): void {
    $fixture = Fixture::open('audited-project');

    try {
        $status = vet(['packages' => ['acme/widget'], '--path' => $fixture->rootPath]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($status)->toBe(0)
        ->and($output)
        ->toContain('acme/widget')
        ->toContain('tree-v2:9353593981e757356e4365ed9b510a3a2483c2a4ec7cdb3dfd0b9c1f1e7a9e99')
        ->toContain('5 files')
        ->toContain('vendor/acme/widget')
        ->and(str_contains($output, 'delta'))->toBeFalse();
});

it('renders the four buckets of a stale project, worst first', function (): void {
    $fixture = Fixture::open('stale-project');

    try {
        $status = vet(['--path' => $fixture->rootPath]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($status)->toBe(1)
        ->and($output)
        ->toContain('acme/widget 2.0.0')
        ->toContain('4 files (delta from [1.0.0])')
        ->toContain('install-time manifest (1)')
        ->toContain('~ composer.json  scripts')
        ->toContain('opaque artifact (1)')
        ->toContain('~ bin/widget.phar')
        ->toContain('runtime source (1)')
        ->toContain('~ src/Widget.php')
        ->toContain('inert (1)')
        ->toContain('~ tests/WidgetTest.php')
        ->toContain("+        return 'gadget';")
        ->and(mb_strpos($output, 'install-time manifest'))->toBeLessThan((int) mb_strpos($output, 'runtime source'));
});

it('renders the source of each change of a stale project with -v', function (): void {
    $fixture = Fixture::open('stale-project');

    try {
        $status = vet(['--path' => $fixture->rootPath, '-v' => true]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($status)->toBe(1)
        ->and($output)
        ->toContain("-        return 'widget';")
        ->toContain("+        return 'gadget';")
        ->toContain('post-install-cmd')
        ->and(str_contains($output, 'OPAQUE BYTES'))->toBeFalse();
});

it('asks for a baseline when the project holds no trust file', function (): void {
    $fixture = Fixture::open('no-trust-file');

    try {
        $status = vet(['--path' => $fixture->rootPath]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($status)->toBe(1)
        ->and($output)
        ->toContain('No trust file yet. Run [vet --init] to record every package that vendor/ holds today in [vet.json].')
        ->and(str_contains($output, 'acme/widget'))->toBeFalse()
        ->and(str_contains($output, 'are not covered'))->toBeFalse();
});

it('reports a changed package before an ungranted one', function (): void {
    $fixture = Fixture::open('partly-audited');

    try {
        $status = vet(['--path' => $fixture->rootPath]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($status)->toBe(1)
        ->and($output)
        ->toContain('4 files (delta from [1.0.0])')
        ->toContain('[1.0.0] was trusted, [2.0.0] is installed')
        ->toContain('acme/lint 1.0.0 (dev)')
        ->toContain('no entry; this tree is')
        ->and(mb_strpos($output, 'acme/widget'))->toBeLessThan((int) mb_strpos($output, 'acme/lint'));
});

it('names a tree that disagrees with composer.lock', function (): void {
    $fixture = Fixture::open('audited-project');

    file_put_contents($fixture->path('vendor/composer/installed.json'), str_replace(
        '"version": "1.0.0"',
        '"version": "1.1.0"',
        $fixture->read('vendor/composer/installed.json'),
    ));

    $plan = composerPlanFile([[
        'package' => 'acme/unrelated',
        'change' => 'install',
        'from' => null,
        'to' => '1.0.0',
    ]]);

    try {
        $status = vet(['--path' => $fixture->rootPath, '--plan' => $plan]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($status)->toBe(1)
        ->and($output)->toContain('is installed at [1.1.0] but composer.lock says [1.0.0]');
});

it('orders each package by the count of files that its review costs', function (): void {
    $fixture = Fixture::open('delta-shapes');

    try {
        $status = vet(['--path' => $fixture->rootPath]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    $rows = array_values(array_filter(
        explode("\n", $output),
        static fn (string $line): bool => str_contains($line, 'files'),
    ));

    expect($status)->toBe(1)
        ->and(array_map(static fn (string $row): string => explode(' ', trim($row))[0], $rows))
        ->toBe(['acme/inert-only', 'acme/moved', 'acme/media', 'acme/opaque', 'acme/manifest-only']);
});

it('invites the audit command when composer runs the audit of the installed tree', function (): void {
    $fixture = Fixture::open('wide-delta');

    putenv(Gate::ENVIRONMENT.'=1');

    try {
        $status = vet(['--path' => $fixture->rootPath]);
        $output = Artisan::output();
    } finally {
        putenv(Gate::ENVIRONMENT);
        $fixture->remove();
    }

    expect($status)->toBe(1)
        ->and($output)
        ->toContain('… and 18 more, with [vet -v]')
        ->toContain('with [vet -v]')
        ->and(str_contains($output, 'composer update -v'))->toBeFalse();
});

it('renders the buckets and the changed paths of a stale package', function (): void {
    $project = StaleProject::create();

    try {
        $status = vet(['--path' => $project->rootPath]);
        $output = Artisan::output();
    } finally {
        $project->remove();
    }

    expect($status)->toBe(1)
        ->and($output)
        ->toContain('1 files (delta from [1.0.0])')
        ->toContain('runtime source (1)')
        ->toContain('~ src/Widget.php')
        ->toContain("+        return 'gadget';")
        ->toContain('Read every change with [vet -v]');
});

it('renders the source of each change with -v', function (): void {
    $project = StaleProject::create();

    try {
        $status = vet(['--path' => $project->rootPath, '-v' => true]);
        $output = Artisan::output();
    } finally {
        $project->remove();
    }

    expect($status)->toBe(1)
        ->and($output)
        ->toContain('runtime source (1)')
        ->toContain('~ src/Widget.php')
        ->toContain('│ runtime source (1)')
        ->toContain("│     +        return 'gadget';")
        ->toContain("-        return 'widget';")
        ->toContain("+        return 'gadget';")
        ->toContain('[1] package(s) are not covered. Run [vet] in a terminal to record the ones that you trust.');
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
