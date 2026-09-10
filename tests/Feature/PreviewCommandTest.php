<?php

declare(strict_types=1);

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Artisan;
use Tests\Fixture;
use Tests\Fixtures\FakeHttp;

function refuseMetadata(): void
{
    app()->instance(ClientInterface::class, (new FakeHttp([new Response(404, [], 'Not Found')]))->client);
}

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

    expect($status)->toBe(1)
        ->and($untouched)->toBe($installed)
        ->and($output)
        ->toContain('acme/widget 1.0.0 → 2.0.0')
        ->toContain('4 files')
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

    expect($status)->toBe(1)
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

        /** @var array{total: int, unaudited: array<int, array<string, mixed>>} $plan */
        $plan = json_decode(Artisan::output(), true);
    } finally {
        $fixture->remove();
    }

    expect($plan['total'])->toBe(1)
        ->and($plan['unaudited'][0])->toMatchArray([
            'package' => 'acme/widget',
            'version' => '2.0.0',
            'state' => 'pending',
            'from' => '1.0.0',
            'files_to_review' => 4,
        ])
        ->and($plan['unaudited'][0]['delta'])->toMatchArray([
            'counts' => [
                'install-manifest' => 1,
                'opaque' => 1,
                'runtime-source' => 1,
                'inert' => 1,
            ],
        ]);
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

    refuseMetadata();

    try {
        $status = Artisan::call('preview', ['--path' => $fixture->rootPath]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($status)->toBe(1)
        ->and($output)->toContain('has no version [9.9.9]');
});

it('previews an upgrade, a downgrade and a package that arrives', function (): void {
    $fixture = Fixture::open('plan-shapes');

    try {
        $status = Artisan::call('preview', ['--path' => $fixture->rootPath]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($status)->toBe(1)
        ->and($output)
        ->toContain('to review (3, worst first)')
        ->toContain('acme/widget 2.0.0 → 1.0.0')
        ->toContain('4 files')
        ->toContain('acme/lint 1.0.0 → 2.0.0')
        ->toContain('1 files')
        ->toContain('acme/gadget 3.0.0')
        ->toContain('whole package')
        ->and(str_contains($output, 'acme/legacy'))->toBeFalse()
        ->and(mb_strpos($output, 'acme/widget'))->toBeLessThan((int) mb_strpos($output, 'acme/gadget'));
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

    refuseMetadata();

    try {
        $status = Artisan::call('preview', ['--path' => $fixture->rootPath]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($status)->toBe(1)
        ->and($output)->toContain('has no version [dev-main]');
});

it('names an entry whose version matches the plan and whose bytes do not', function (): void {
    $fixture = Fixture::open('pending-update');

    file_put_contents($fixture->path('vet.json'), str_replace(
        '"version": "1.0.0"',
        '"version": "2.0.0"',
        $fixture->read('vet.json'),
    ));

    try {
        $status = Artisan::call('preview', ['--path' => $fixture->rootPath]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($status)->toBe(1)
        ->and($output)
        ->toContain('acme/widget 1.0.0 → 2.0.0')
        ->toContain('composer would install [2.0.0] again, and its bytes changed')
        ->toContain('~ src/Widget.php');
});

it('previews every other package when it cannot read one of them', function (): void {
    $fixture = Fixture::open('pending-update');

    $fixture->composer(<<<'PLAN'
        Package operations: 0 installs, 2 updates, 0 removals
          - Upgrading acme/widget (1.0.0 => 2.0.0)
          - Upgrading acme/private (1.0.0 => 1.1.0)
        PLAN);

    refuseMetadata();

    try {
        $status = Artisan::call('preview', ['--path' => $fixture->rootPath]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($status)->toBe(1)
        ->and($output)
        ->toContain('~ src/Widget.php')
        ->toContain('bytes not readable')
        ->toContain('composer would install [1.1.0] and vet cannot read those bytes');
});
