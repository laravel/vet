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
