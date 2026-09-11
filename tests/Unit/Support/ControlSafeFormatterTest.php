<?php

declare(strict_types=1);

use App\Support\ControlSafeFormatter;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;

it('replaces an escape sequence in a message, and keeps the styles of vet', function (): void {
    $formatter = new ControlSafeFormatter(new OutputFormatter(true));

    expect($formatter->format("<info>src/\x1b]8;;https://evil.test\x07Widget.php</info>"))
        ->toBe("\x1b[32msrc/?]8;;https://evil.test?Widget.php\x1b[39m");
});

it('replaces an escape sequence in a message that it wraps', function (): void {
    $formatter = new ControlSafeFormatter(new OutputFormatter(false));

    expect($formatter->formatAndWrap("builds/ev\x1b[2Kil.so", 80))->toBe('builds/ev?[2Kil.so');
});

it('formats no text when it receives no message', function (): void {
    expect((new ControlSafeFormatter(new OutputFormatter(false)))->format(null))->toBe('');
});

it('keeps the decoration and the styles of the formatter that it wraps', function (): void {
    $inner = new OutputFormatter(true);
    $formatter = new ControlSafeFormatter($inner);
    $style = new OutputFormatterStyle('red');

    $formatter->setDecorated(false);
    $formatter->setStyle('vet', $style);

    expect($formatter->isDecorated())->toBeFalse()
        ->and($inner->isDecorated())->toBeFalse()
        ->and($formatter->hasStyle('vet'))->toBeTrue()
        ->and($formatter->getStyle('vet'))->toBe($style);
});
