<?php

declare(strict_types=1);

use App\Actions\ClassifyPath;
use App\Enums\BucketType;

function classifyFile(string $contents): string
{
    $file = sys_get_temp_dir().'/vet-classify-'.bin2hex(random_bytes(6));

    file_put_contents($file, $contents);

    register_shutdown_function(static function () use ($file): void {
        @unlink($file);
    });

    return $file;
}

it('reads a file that an autoload rule points at as runtime source', function (): void {
    expect((new ClassifyPath(['src']))->handle('src/Widget.php'))->toBe(BucketType::RuntimeSource);
});

it('reads a Dockerfile as runtime source', function (): void {
    $classifier = new ClassifyPath(['src']);

    expect($classifier->handle('Dockerfile'))->toBe(BucketType::RuntimeSource)
        ->and($classifier->handle('runtimes/8.4/Dockerfile'))->toBe(BucketType::RuntimeSource)
        ->and($classifier->handle('runtimes/8.4/php.dockerfile'))->toBe(BucketType::RuntimeSource)
        ->and($classifier->handle('docker-compose.yml'))->toBe(BucketType::RuntimeSource);
});

it('reads a shell script as runtime source', function (): void {
    expect((new ClassifyPath(['src']))->handle('runtimes/8.4/start-container.sh'))
        ->toBe(BucketType::RuntimeSource);
});

it('reads a file that starts with a shebang as runtime source', function (): void {
    $file = classifyFile("#!/usr/bin/env bash\nset -e\n");

    expect((new ClassifyPath(['src']))->handle('runtimes/8.4/start-container', $file))
        ->toBe(BucketType::RuntimeSource);
});

it('reads a document as inert', function (): void {
    $classifier = new ClassifyPath(['src']);

    expect($classifier->handle('README.md', classifyFile("# Widget\n")))->toBe(BucketType::Inert)
        ->and($classifier->handle('tests/WidgetTest.php'))->toBe(BucketType::Inert);
});

it('reads composer.json as the install manifest', function (): void {
    expect((new ClassifyPath(['src']))->handle('composer.json'))->toBe(BucketType::InstallManifest);
});

it('reads a compiled binary as opaque, even inside an autoload root', function (): void {
    $classifier = new ClassifyPath(['src']);

    expect($classifier->handle('src/ext/widget.so'))->toBe(BucketType::Opaque)
        ->and($classifier->handle('src/Widget.PHAR'))->toBe(BucketType::Opaque);
});

it('reads a file that holds a null byte as opaque', function (): void {
    expect((new ClassifyPath(['src']))->handle('src/Widget.php', classifyFile("<?php\n\0\0payload")))
        ->toBe(BucketType::Opaque);
});

it('reads an image that holds a null byte as inert', function (): void {
    expect((new ClassifyPath(['src']))->handle('docs/logo.png', classifyFile("\x89PNG\r\n\x1a\n\0\0\0\rIHDR")))
        ->toBe(BucketType::Inert);
});

it('reads a large script on one line as opaque', function (): void {
    expect((new ClassifyPath(['src']))->handle('dist/widget.min.js', classifyFile(str_repeat('var a=1;', 3000))))
        ->toBe(BucketType::Opaque);
});

it('reads a large script of short lines and a small script on one line as inert', function (): void {
    $classifier = new ClassifyPath(['src']);

    expect($classifier->handle('dist/widget.js', classifyFile(str_repeat("var widget = 1;\n", 2000))))->toBe(BucketType::Inert)
        ->and($classifier->handle('dist/widget.min.js', classifyFile(str_repeat('var a=1;', 100))))->toBe(BucketType::Inert);
});

it('reads each php file as runtime source when an autoload rule maps the package root', function (): void {
    $classifier = new ClassifyPath([], true);

    expect($classifier->handle('Widget.php'))->toBe(BucketType::RuntimeSource)
        ->and($classifier->handle('tests/WidgetTest.php'))->toBe(BucketType::RuntimeSource)
        ->and($classifier->handle('README.md'))->toBe(BucketType::Inert);
});

it('reads a file that it cannot open as inert', function (): void {
    $script = classifyFile("#!/bin/sh\n");
    $bundle = classifyFile(str_repeat('var a=1;', 3000));

    chmod($script, 0o000);
    chmod($bundle, 0o000);

    try {
        $classifier = new ClassifyPath(['src']);

        expect($classifier->handle('runtimes/start-container', $script))->toBe(BucketType::Inert)
            ->and($classifier->handle('dist/widget.min.js', $bundle))->toBe(BucketType::Inert);
    } finally {
        chmod($script, 0o644);
        chmod($bundle, 0o644);
    }
});
