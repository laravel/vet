<?php

declare(strict_types=1);

use App\Support\ControlSafeComponents;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Fixture;

it('prints no control character of a path that a package holds', function (): void {
    $fixture = Fixture::open('delta-shapes');

    file_put_contents($fixture->path("vendor/acme/opaque/builds/ev\x1b[2Kil.so"), "\0binary\n");

    try {
        vet(['packages' => ['acme/opaque'], '--path' => $fixture->rootPath, '-v' => true]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($output)
        ->toContain('builds/ev?[2Kil.so')
        ->and(str_contains($output, "\x1b"))->toBeFalse();
});

it('prints no control character of a version that the lock file holds', function (): void {
    $fixture = Fixture::open('no-trust-file');

    foreach (['vendor/composer/installed.json', 'composer.lock'] as $file) {
        file_put_contents($fixture->path($file), str_replace(
            '"1.0.0"',
            '"1.0.0\u001b[2K"',
            $fixture->read($file),
        ));
    }

    try {
        vet(['--path' => $fixture->rootPath]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($output)
        ->toContain('acme/widget 1.0.0?[2K')
        ->and(str_contains($output, "\x1b"))->toBeFalse();
});

it('replaces a control character before a component renders it', function (): void {
    $buffer = new BufferedOutput;

    $components = new ControlSafeComponents(new OutputStyle(new ArrayInput([]), $buffer));

    $components->twoColumnDetail("acme/widget 1.0.0\x1b[2K", 'ok');

    expect($buffer->fetch())
        ->toContain('acme/widget 1.0.0?[2K');
});

it('replaces a character that reorders the text that a component renders', function (): void {
    $buffer = new BufferedOutput;

    $components = new ControlSafeComponents(new OutputStyle(new ArrayInput([]), $buffer));

    $components->twoColumnDetail("src/safe\u{202e}gnp.js", 'ok');

    $output = $buffer->fetch();

    expect($output)
        ->toContain('src/safe?gnp.js')
        ->and(str_contains($output, "\u{202e}"))->toBeFalse();
});

it('replaces a character that hides itself in the text that a component renders', function (): void {
    $buffer = new BufferedOutput;

    $components = new ControlSafeComponents(new OutputStyle(new ArrayInput([]), $buffer));

    $components->twoColumnDetail("acme/wid\u{200b}get", 'ok');

    $output = $buffer->fetch();

    expect($output)
        ->toContain('acme/wid?get')
        ->and(str_contains($output, "\u{200b}"))->toBeFalse();
});
