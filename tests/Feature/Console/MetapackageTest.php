<?php

declare(strict_types=1);

use App\Support\Json;
use Illuminate\Support\Facades\Artisan;
use Tests\Fixtures\Fixture;

it('covers a project that installs a metapackage, and reads no bytes of it', function (): void {
    $fixture = Fixture::open('metapackage-project');

    try {
        $status = vet(['--path' => $fixture->rootPath]);
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
        $status = vet(['--init' => true, '--path' => $fixture->rootPath]);
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
        $status = vet(['packages' => ['acme/advisories'], '--path' => $fixture->rootPath]);
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
        $status = vet([
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
