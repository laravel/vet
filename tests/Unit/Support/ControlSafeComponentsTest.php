<?php

declare(strict_types=1);

use App\Support\ControlSafeComponents;
use Illuminate\Console\OutputStyle;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

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
