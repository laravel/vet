<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Tests\Fixture;

it('puts each change of a project in its bucket', function (): void {
    $fixture = Fixture::open('delta-shapes');

    try {
        $status = Artisan::call('audit', ['--path' => $fixture->rootPath]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($status)->toBe(1)
        ->and($output)
        ->toContain('to review (5, worst first)')
        ->toContain('~ .github/workflows/tests.yml')
        ->toContain('~ docs/readme.md')
        ->toContain('opaque artifact (2)')
        ->toContain('~ builds/native.so')
        ->toContain('~ builds/tool.phar')
        ->toContain('~ src/logo.png')
        ->toContain('~ resources/font.woff2')
        ->toContain('install-time manifest (1)')
        ->toContain('~ composer.json  require');
});

it('says that nothing outside tests, docs and CI changed', function (): void {
    $fixture = Fixture::open('delta-shapes');

    try {
        $status = Artisan::call('audit', ['package' => 'acme/inert-only', '--path' => $fixture->rootPath]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($status)->toBe(1)
        ->and($output)->toContain('Nothing outside tests, docs and CI changed.');
});

it('renders the key of a manifest that changed, and its source', function (): void {
    $fixture = Fixture::open('delta-shapes');

    try {
        Artisan::call('audit', ['package' => 'acme/manifest-only', '--path' => $fixture->rootPath]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($output)
        ->toContain('~ composer.json  require')
        ->toContain('require: (absent) →')
        ->toContain('+    "require": {')
        ->and(str_contains($output, 'with -v'))->toBeFalse();
});

it('prints no source of an opaque artifact, and warns that it cannot be read', function (): void {
    $fixture = Fixture::open('delta-shapes');

    try {
        Artisan::call('audit', ['package' => 'acme/opaque', '--path' => $fixture->rootPath, '-v' => true]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($output)
        ->toContain('opaque artifact (2)  cannot be reviewed — trust and provenance only')
        ->toContain('[2] opaque artifacts cannot be read: [builds/native.so, builds/tool.phar].')
        ->and(str_contains($output, '@@'))->toBeFalse();
});

it('treats a media file as a readable change rather than an opaque artifact', function (): void {
    $fixture = Fixture::open('delta-shapes');

    try {
        Artisan::call('audit', ['package' => 'acme/media', '--path' => $fixture->rootPath]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($output)
        ->toContain('runtime source (1)')
        ->toContain('~ src/logo.png')
        ->toContain('inert (1)')
        ->toContain('~ resources/font.woff2')
        ->and(str_contains($output, 'opaque'))->toBeFalse();
});

it('prints no control character of a file that a package changed', function (): void {
    $fixture = Fixture::open('delta-shapes');

    file_put_contents(
        $fixture->path('vendor/acme/moved/src/Kept.php'),
        "<?php\n\n// \x1b[2K\x1b[1A hidden\nfinal class Kept {}\n",
    );

    try {
        Artisan::call('audit', ['package' => 'acme/moved', '--path' => $fixture->rootPath, '-v' => true]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($output)
        ->toContain('+// ?[2K?[1A hidden')
        ->and(str_contains($output, "\x1b"))->toBeFalse();
});

it('prints no source of a file that holds no readable source', function (): void {
    $fixture = Fixture::open('delta-shapes');

    try {
        Artisan::call('audit', ['package' => 'acme/media', '--path' => $fixture->rootPath, '-v' => true]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($output)
        ->toContain('runtime source (1)')
        ->toContain('~ src/logo.png')
        ->toContain('inert (1)')
        ->toContain('~ resources/font.woff2')
        ->toContain('this file holds no readable source, so its bytes are not shown')
        ->and(str_contains($output, '@@'))->toBeFalse();
});

it('marks a file that arrived, a file that left and a file that changed', function (): void {
    $fixture = Fixture::open('delta-shapes');

    try {
        Artisan::call('audit', ['package' => 'acme/moved', '--path' => $fixture->rootPath]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($output)
        ->toContain('~ src/Kept.php')
        ->toContain('+ src/New.php')
        ->toContain('- src/Old.php');
});

it('stops at five paths and invites the source of the rest', function (): void {
    $fixture = Fixture::open('wide-delta');

    try {
        $status = Artisan::call('audit', ['--path' => $fixture->rootPath]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($status)->toBe(1)
        ->and($output)
        ->toContain('23 files to review (delta from [1.0.0])')
        ->toContain('runtime source (23)')
        ->toContain('+ src/Rule01.php')
        ->toContain('+ src/Rule05.php')
        ->toContain('… and 18 more, with -v')
        ->and(str_contains($output, 'src/Rule06.php'))->toBeFalse();
});

it('shows every path when the user asks for the source', function (): void {
    $fixture = Fixture::open('wide-delta');

    try {
        Artisan::call('audit', ['--path' => $fixture->rootPath, '-v' => true]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($output)
        ->toContain('+ src/Rule23.php')
        ->and(str_contains($output, '… and 18 more'))->toBeFalse();
});

it('reports a change of a line ending as a change', function (): void {
    $fixture = Fixture::open('line-endings');

    try {
        $status = Artisan::call('audit', ['--path' => $fixture->rootPath]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($status)->toBe(1)
        ->and($output)
        ->toContain('2 files to review (delta from [1.0.0])')
        ->toContain('runtime source (1)')
        ->toContain('~ src/Widget.php');
});

it('reads every file of a package that autoloads its own root as runtime source', function (): void {
    $fixture = Fixture::open('root-autoload');

    try {
        $status = Artisan::call('audit', ['--path' => $fixture->rootPath]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($status)->toBe(1)
        ->and($output)
        ->toContain('runtime source (2)')
        ->toContain('~ Widget.php')
        ->toContain('~ tests/WidgetTest.php')
        ->toContain('inert (1)')
        ->toContain('~ docs/usage.md');
});
