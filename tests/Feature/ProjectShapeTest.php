<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Tests\Fixture;

it('reads the vendor directory that composer.json configures', function (): void {
    $fixture = Fixture::open('custom-vendor-dir');

    try {
        $status = Artisan::call('audit', ['--path' => $fixture->rootPath]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($status)->toBe(0)
        ->and($output)->toContain('All [1] packages are covered.');
});

it('says that a tree came from --prefer-source rather than report a change of bytes alone', function (): void {
    $fixture = Fixture::open('source-install');

    try {
        $status = Artisan::call('audit', ['--path' => $fixture->rootPath]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($status)->toBe(1)
        ->and($output)
        ->toContain('is still installed but its bytes changed')
        ->toContain('this tree came from [--prefer-source]');
});

it('reports the whole package when the dist of the granted version cannot be fetched', function (): void {
    $fixture = Fixture::open('no-dist');

    try {
        $status = Artisan::call('audit', ['--path' => $fixture->rootPath]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($status)->toBe(1)
        ->and($output)
        ->toContain('5 files (whole package)')
        ->and(str_contains($output, 'delta from'))->toBeFalse();
});

it('fails when the user asks for a delta of a version that holds no dist', function (): void {
    $fixture = Fixture::open('no-dist');

    try {
        $status = Artisan::call('audit', [
            'package' => 'acme/widget',
            '--from' => '1.0.0',
            '--path' => $fixture->rootPath,
        ]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($status)->toBe(1)
        ->and($output)->toContain('has no dist URL, so its bytes cannot be fetched');
});

it('reports the package that provides a name that a monorepo replaces', function (): void {
    $fixture = Fixture::open('monorepo-replace');

    try {
        $status = Artisan::call('audit', ['package' => 'acme/widget', '--path' => $fixture->rootPath]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($status)->toBe(0)
        ->and($output)
        ->toContain('acme/framework')
        ->toContain('provides')
        ->toContain('acme/widget')
        ->toContain('vendor/acme/framework')
        ->and(str_contains($output, 'Record these bytes'))->toBeFalse();
});

it('names a package of composer.lock that is not installed, and one that the lock file does not hold', function (): void {
    $fixture = Fixture::open('lock-drift');

    try {
        $status = Artisan::call('audit', ['--path' => $fixture->rootPath]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($status)->toBe(1)
        ->and($output)
        ->toContain('[acme/ghost] is in composer.lock at [1.0.0] but is not installed')
        ->toContain('[acme/extra] is installed at [1.0.0] but is not in composer.lock')
        ->toContain('The installed tree does not match composer.lock.');
});

it('names a tree whose version disagrees with composer.lock', function (): void {
    $fixture = Fixture::open('version-drift');

    try {
        $status = Artisan::call('audit', ['--path' => $fixture->rootPath]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($status)->toBe(1)
        ->and($output)->toContain('[acme/widget] is installed at [1.0.0] but composer.lock says [1.1.0]');
});

it('asks for composer install when the project installs no package', function (): void {
    $fixture = Fixture::open('empty-vendor');

    try {
        $status = Artisan::call('audit', ['--path' => $fixture->rootPath]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($status)->toBe(1)
        ->and($output)
        ->toContain('expected a non-empty "packages" array')
        ->toContain('Run [composer install] first.');
});

it('names the lock file that the project holds no', function (): void {
    $fixture = Fixture::open('no-lock');

    try {
        $status = Artisan::call('audit', ['--path' => $fixture->rootPath]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($status)->toBe(1)
        ->and($output)->toContain('Could not read [the lock file]');
});

it('names the installed package list that the project holds no', function (): void {
    $fixture = Fixture::open('no-installed-json');

    try {
        $status = Artisan::call('audit', ['--path' => $fixture->rootPath]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($status)->toBe(1)
        ->and($output)->toContain('Could not read [the installed package list]');
});

it('fails on a directory that holds no composer.json', function (): void {
    $directory = sys_get_temp_dir().'/vet-'.bin2hex(random_bytes(6));

    mkdir($directory, 0o777, true);

    try {
        $status = Artisan::call('audit', ['--path' => $directory]);
        $output = Artisan::output();
    } finally {
        rmdir($directory);
    }

    expect($status)->toBe(1)
        ->and($output)->toContain('the project manifest');
});

it('refuses to hash a package directory that holds no file', function (): void {
    $fixture = Fixture::open('audited-project');

    unlink($fixture->path('vendor/acme/lint/composer.json'));
    unlink($fixture->path('vendor/acme/lint/src/Linter.php'));
    rmdir($fixture->path('vendor/acme/lint/src'));

    try {
        $status = Artisan::call('audit', ['--path' => $fixture->rootPath]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($status)->toBe(1)
        ->and($output)->toContain('contains no files; refusing to hash an empty tree');
});

it('names the package directory that the installed package list points at and vendor holds no', function (): void {
    $fixture = Fixture::open('audited-project');

    unlink($fixture->path('vendor/acme/lint/composer.json'));
    unlink($fixture->path('vendor/acme/lint/src/Linter.php'));
    rmdir($fixture->path('vendor/acme/lint/src'));
    rmdir($fixture->path('vendor/acme/lint'));

    try {
        $status = Artisan::call('audit', ['--path' => $fixture->rootPath]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($status)->toBe(1)
        ->and($output)
        ->toContain('vendor/acme/lint')
        ->toContain('does not exist');
});

it('names the installed package that records no install path', function (): void {
    $fixture = Fixture::open('audited-project');

    file_put_contents($fixture->path('vendor/composer/installed.json'), str_replace(
        '"install-path": "../acme/lint",',
        '',
        $fixture->read('vendor/composer/installed.json'),
    ));

    try {
        $status = Artisan::call('audit', ['--path' => $fixture->rootPath]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($status)->toBe(1)
        ->and($output)->toContain('The package [acme/lint] has no recorded install path.');
});

it('names the package that the user asks for and the project does not install', function (): void {
    $fixture = Fixture::open('audited-project');

    try {
        $status = Artisan::call('audit', ['package' => 'acme/ghost', '--path' => $fixture->rootPath]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($status)->toBe(1)
        ->and($output)->toContain('The package [acme/ghost] is not present in the installed package list.');
});

it('reads a package that ships a symlink to a file that the tree holds no', function (): void {
    $fixture = Fixture::open('tampered-project');

    unlink($fixture->path('vendor/acme/widget/README.md'));
    symlink('../../../gone/CHANGELOG.md', $fixture->path('vendor/acme/widget/README.md'));

    try {
        $status = Artisan::call('audit', ['--path' => $fixture->rootPath, '-v' => true]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($status)->toBe(1)
        ->and($output)
        ->toContain('2 files to review (delta from the published [1.0.0])')
        ->toContain('~ README.md')
        ->toContain('~ src/Widget.php');
});
