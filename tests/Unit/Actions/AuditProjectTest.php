<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Tests\StaleProject;

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
        ->toContain("-        return 'widget';")
        ->toContain("+        return 'gadget';")
        ->toContain('[1] package(s) are not covered. Run [vet] in a terminal to record the ones that you trust.');
});
