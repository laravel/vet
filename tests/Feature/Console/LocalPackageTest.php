<?php

declare(strict_types=1);

use App\Support\Json;
use Illuminate\Support\Facades\Artisan;
use Tests\Fixtures\Fixture;

it('covers a project that installs a package from a local path repository, and reads no bytes of it', function (): void {
    $fixture = Fixture::open('local-package-project');

    try {
        $status = vet(['--path' => $fixture->rootPath]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($status)->toBe(0)
        ->and($output)->toContain('All [2] packages are trusted.')
        ->and(str_contains($output, 'acme/toolkit'))->toBeFalse();
});

it('records no entry of a local package in the baseline', function (): void {
    $fixture = Fixture::open('local-package-project');

    unlink($fixture->path('vet.json'));

    try {
        $status = vet(['--init' => true, '--path' => $fixture->rootPath]);
        $output = Artisan::output();
        $trustFile = $fixture->read('vet.json');
    } finally {
        $fixture->remove();
    }

    expect($status)->toBe(0)
        ->and($output)->toContain('Trusted [2] packages')
        ->and(str_contains($output, 'acme/toolkit'))->toBeFalse()
        ->and(str_contains($trustFile, 'acme/toolkit'))->toBeFalse()
        ->and($trustFile)->toContain('acme/widget');
});

it('fails when the user names a local package', function (): void {
    $fixture = Fixture::open('local-package-project');

    try {
        $status = vet(['packages' => ['acme/toolkit'], '--path' => $fixture->rootPath]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($status)->toBe(1)
        ->and($output)
        ->toContain('acme/toolkit')
        ->toContain('comes from a local path repository');
});

it('reads no bytes of a local package that composer would install', function (): void {
    $fixture = Fixture::open('local-package-project');

    file_put_contents($fixture->path('plan.json'), Json::encode([
        'operations' => [
            [
                'package' => 'acme/toolkit',
                'change' => 'upgrade',
                'from' => 'dev-main',
                'to' => 'dev-main',
                'dist_url' => '../packages/toolkit',
                'dist_reference' => 'ffff6666ffff6666ffff6666ffff6666ffff6666',
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
        ->and($output)->toContain('All [2] packages are trusted.')
        ->and(str_contains($output, 'acme/toolkit'))->toBeFalse();
});
