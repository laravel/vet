<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Tests\Fixture;

it('shows what the next composer update changes, and leaves the installed tree alone', function (): void {
    $fixture = Fixture::open('pending-update');
    $installed = $fixture->read('vendor/acme/widget/src/Widget.php');

    try {
        $status = Artisan::call('preview', ['--path' => $fixture->rootPath]);
        $output = Artisan::output();
        $untouched = $fixture->read('vendor/acme/widget/src/Widget.php');
    } finally {
        $fixture->remove();
    }

    expect($status)->toBe(0)
        ->and($untouched)->toBe($installed)
        ->and($output)
        ->toContain('acme/widget 1.0.0 → 2.0.0')
        ->toContain('4 files to review')
        ->toContain('you trust [1.0.0]')
        ->toContain('install-time manifest (1)')
        ->toContain('opaque artifact (1)')
        ->toContain('runtime source (1)')
        ->toContain('~ src/Widget.php')
        ->toContain("+        return 'gadget';");
});

it('reads the source of each change with -v, and never the source of an opaque artifact', function (): void {
    $fixture = Fixture::open('pending-update');

    try {
        $status = Artisan::call('preview', ['--path' => $fixture->rootPath, '-v' => true]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($status)->toBe(0)
        ->and($output)
        ->toContain("-        return 'widget';")
        ->toContain("+        return 'gadget';")
        ->toContain('post-install-cmd')
        ->and(str_contains($output, 'OPAQUE BYTES'))->toBeFalse();
});

it('emits the plan as json', function (): void {
    $fixture = Fixture::open('pending-update');

    try {
        Artisan::call('preview', ['--path' => $fixture->rootPath, '--json' => true]);

        /** @var array{operations: int, packages: array<int, array<string, mixed>>} $plan */
        $plan = json_decode(Artisan::output(), true);
    } finally {
        $fixture->remove();
    }

    expect($plan['operations'])->toBe(1)
        ->and($plan['packages'][0])->toMatchArray([
            'package' => 'acme/widget',
            'change' => 'upgrade',
            'from' => '1.0.0',
            'to' => '2.0.0',
            'trusted' => '1.0.0',
            'files_to_review' => 4,
        ])
        ->and($plan['packages'][0]['delta'])->toMatchArray([
            'counts' => [
                'install-manifest' => 1,
                'opaque' => 1,
                'runtime-source' => 1,
                'inert' => 1,
            ],
        ]);
});

it('names a package that arrives and a package that leaves, and builds no delta of either', function (): void {
    $fixture = Fixture::open('pending-update');

    $fixture->composer(<<<'PLAN'
        Package operations: 1 install, 0 updates, 1 removal
          - Removing acme/legacy (0.9.0)
          - Installing acme/gadget (3.0.0)
        PLAN);

    try {
        $status = Artisan::call('preview', ['--path' => $fixture->rootPath]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($status)->toBe(0)
        ->and($output)
        ->toContain('acme/gadget 3.0.0')
        ->toContain('whole package to review (new)')
        ->toContain('acme/legacy 0.9.0')
        ->toContain('nothing to review (removed)');
});

it('says so when the next composer update changes nothing', function (): void {
    $fixture = Fixture::open('pending-update');

    $fixture->composer('Nothing to install, update or remove');

    try {
        $status = Artisan::call('preview', ['--path' => $fixture->rootPath]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($status)->toBe(0)
        ->and($output)->toContain('changes nothing in vendor/');
});

it('fails with the words of composer when the plan fails', function (): void {
    $fixture = Fixture::open('pending-update');

    $fixture->composer('Your requirements could not be resolved.', 2);

    try {
        $status = Artisan::call('preview', ['--path' => $fixture->rootPath]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($status)->toBe(1)
        ->and($output)
        ->toContain('exit code [2]')
        ->toContain('Your requirements could not be resolved.');
});

it('fails rather than report a plan that holds no change when a fetch fails', function (): void {
    $fixture = Fixture::open('pending-update');

    $fixture->composer('  - Upgrading acme/widget (1.0.0 => 9.9.9)');

    try {
        $status = Artisan::call('preview', ['--path' => $fixture->rootPath]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($status)->toBe(1)
        ->and($output)->toContain('has no version [9.9.9]');
});

it('previews an upgrade, a downgrade, a package that arrives and a package that leaves', function (): void {
    $fixture = Fixture::open('plan-shapes');

    try {
        $status = Artisan::call('preview', ['--path' => $fixture->rootPath]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($status)->toBe(0)
        ->and($output)
        ->toContain('to review (4, worst first)')
        ->toContain('acme/widget 2.0.0 → 1.0.0')
        ->toContain('4 files to review')
        ->toContain('acme/lint 1.0.0 → 2.0.0')
        ->toContain('1 files to review')
        ->toContain('acme/gadget 3.0.0')
        ->toContain('whole package to review (new)')
        ->toContain('acme/legacy 0.9.0')
        ->toContain('nothing to review (removed)')
        ->and(mb_strpos($output, 'acme/widget'))->toBeLessThan((int) mb_strpos($output, 'acme/gadget'))
        ->and(mb_strpos($output, 'acme/gadget'))->toBeLessThan((int) mb_strpos($output, 'acme/legacy'));
});

it('names the manifest key that a downgrade takes away', function (): void {
    $fixture = Fixture::open('plan-shapes');

    try {
        Artisan::call('preview', ['--path' => $fixture->rootPath, '-v' => true]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($output)
        ->toContain('~ composer.json  scripts')
        ->toContain('→ (absent)')
        ->toContain('post-install-cmd');
});

it('reads a version of a branch from the plan of composer', function (): void {
    $fixture = Fixture::open('plan-shapes');

    $fixture->composer('  - Upgrading acme/widget (dev-main 1234567 => dev-main 89abcde)');

    try {
        $status = Artisan::call('preview', ['--path' => $fixture->rootPath]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($status)->toBe(1)
        ->and($output)->toContain('has no version [dev-main]');
});
