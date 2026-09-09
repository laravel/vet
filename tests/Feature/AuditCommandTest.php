<?php

declare(strict_types=1);

use App\Composer\Gate;
use Illuminate\Support\Facades\Artisan;
use Tests\Fixture;

it('covers every package of an audited project, and reaches no network', function (): void {
    $fixture = Fixture::open('audited-project');

    try {
        $status = Artisan::call('audit', ['--path' => $fixture->rootPath]);
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
        $status = Artisan::call('audit', ['package' => 'acme/widget', '--path' => $fixture->rootPath]);
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
        $status = Artisan::call('audit', ['--path' => $fixture->rootPath]);
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
        $status = Artisan::call('audit', ['--path' => $fixture->rootPath, '-v' => true]);
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
        $status = Artisan::call('audit', ['--path' => $fixture->rootPath]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($status)->toBe(1)
        ->and($output)
        ->toContain('No trust file yet')
        ->toContain('vet.json')
        ->toContain('acme/widget 1.0.0')
        ->toContain('acme/lint 1.0.0 (dev)')
        ->toContain('5 files (whole package)')
        ->toContain('no entry; this tree is')
        ->toContain('[2] package(s) are not covered');
});

it('reports a changed package before an ungranted one', function (): void {
    $fixture = Fixture::open('partly-audited');

    try {
        $status = Artisan::call('audit', ['--path' => $fixture->rootPath]);
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

    try {
        $status = Artisan::call('audit', ['--path' => $fixture->rootPath]);
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
        $status = Artisan::call('audit', ['--path' => $fixture->rootPath]);
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

it('asks for the verbose flag of composer when composer runs the audit', function (): void {
    $fixture = Fixture::open('wide-delta');

    putenv(Gate::ENVIRONMENT.'=1');

    try {
        $status = Artisan::call('audit', ['--path' => $fixture->rootPath]);
        $output = Artisan::output();
    } finally {
        putenv(Gate::ENVIRONMENT);
        $fixture->remove();
    }

    expect($status)->toBe(1)
        ->and($output)
        ->toContain('… and 18 more, with composer update -v')
        ->toContain('with [composer update -v]')
        ->and(str_contains($output, 'vet audit -v'))->toBeFalse();
});
