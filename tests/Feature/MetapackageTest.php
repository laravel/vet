<?php

declare(strict_types=1);

use App\Support\Json;
use Illuminate\Support\Facades\Artisan;
use Tests\Fixture;

it('covers a project that installs a metapackage, and reads no bytes of it', function (): void {
    $fixture = Fixture::open('metapackage-project');

    try {
        $status = Artisan::call('audit', ['--path' => $fixture->rootPath]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($status)->toBe(0)
        ->and($output)->toContain('All [2] packages are covered.')
        ->and(str_contains($output, 'acme/advisories'))->toBeFalse();
});

it('records no entry of a metapackage in the baseline', function (): void {
    $fixture = Fixture::open('metapackage-project');

    unlink($fixture->path('vet.json'));

    try {
        $status = Artisan::call('trust', ['--path' => $fixture->rootPath]);
        $output = Artisan::output();
        $trustFile = $fixture->read('vet.json');
    } finally {
        $fixture->remove();
    }

    expect($status)->toBe(0)
        ->and($output)->toContain('Trusted [2] package(s)')
        ->and(str_contains($output, 'acme/advisories'))->toBeFalse()
        ->and(str_contains($trustFile, 'acme/advisories'))->toBeFalse()
        ->and($trustFile)->toContain('acme/widget');
});

it('fails when the user names a metapackage', function (): void {
    $fixture = Fixture::open('metapackage-project');

    try {
        $status = Artisan::call('audit', ['package' => 'acme/advisories', '--path' => $fixture->rootPath]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($status)->toBe(1)
        ->and($output)
        ->toContain('acme/advisories')
        ->toContain('is a metapackage')
        ->toContain('Composer writes no file into vendor/ for it');
});

it('reads no bytes of a metapackage that composer would install', function (): void {
    $fixture = Fixture::open('metapackage-project');

    file_put_contents($fixture->path('plan.json'), Json::encode([
        'operations' => [
            [
                'package' => 'acme/advisories',
                'change' => 'upgrade',
                'from' => '1.0.0',
                'to' => '2.0.0',
                'dist_url' => 'https://packages.test/acme/advisories/2.0.0.zip',
                'dist_reference' => 'eeee5555eeee5555eeee5555eeee5555eeee5555',
            ],
        ],
    ]));

    try {
        $status = Artisan::call('audit', [
            '--path' => $fixture->rootPath,
            '--plan' => $fixture->path('plan.json'),
        ]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($status)->toBe(0)
        ->and($output)->toContain('All [2] packages are covered.')
        ->and(str_contains($output, 'acme/advisories'))->toBeFalse();
});

it('shows no metapackage in the preview of the next update', function (): void {
    $fixture = Fixture::open('metapackage-project');

    $fixture->composer(<<<'OUTPUT'
        Lock file operations: 0 installs, 1 update, 0 removals
          - Upgrading acme/advisories (1.0.0 => 2.0.0)
        OUTPUT);

    try {
        $status = Artisan::call('preview', ['--path' => $fixture->rootPath]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($status)->toBe(0)
        ->and($output)->toContain('The next [composer update] changes nothing in vendor/.')
        ->and(str_contains($output, 'acme/advisories'))->toBeFalse();
});
