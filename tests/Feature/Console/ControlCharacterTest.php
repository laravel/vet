<?php

declare(strict_types=1);

use App\Support\ControlSafeFormatter;
use App\Support\PromptOutput;
use Illuminate\Support\Facades\Artisan;
use Laravel\Prompts\Prompt;
use Tests\Fixtures\Fixture;

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
})->skipOnWindows();

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
        vet(['--init' => true, '--path' => $fixture->rootPath]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($output)
        ->toContain('acme/widget 1.0.0?[2K')
        ->and(str_contains($output, "\x1b"))->toBeFalse();
});

it('writes each prompt through an output that keeps the escape sequences of laravel prompts', function (): void {
    $fixture = Fixture::open('audited-project');

    try {
        vet(['--path' => $fixture->rootPath]);
    } finally {
        $fixture->remove();
    }

    $output = new ReflectionProperty(Prompt::class, 'output')->getValue();
    $formatter = $output instanceof PromptOutput ? $output->getFormatter() : null;
    $frame = "\e[?25l\e[1G\e[13A\e[J\e[90m\e[2mpicker\e[22m\e[39m";

    expect($output)->toBeInstanceOf(PromptOutput::class)
        ->and($formatter)->not->toBeInstanceOf(ControlSafeFormatter::class)
        ->and($formatter?->format($frame))->toBe($frame);
});
