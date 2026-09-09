<?php

declare(strict_types=1);

use App\Support\ControlSafe;

it('replaces an escape sequence', function (): void {
    expect(ControlSafe::text("builds/ev\x1b[2Kil.so"))->toBe('builds/ev?[2Kil.so');
});

it('replaces a character that reverses the order of a line', function (): void {
    expect(ControlSafe::text("gpj.\u{202E}php"))->toBe('gpj.?php')
        ->and(ControlSafe::text("src/\u{2066}Widget.php"))->toBe('src/?Widget.php');
});

it('replaces a character that holds no width', function (): void {
    expect(ControlSafe::text("Wid\u{200B}get.php"))->toBe('Wid?get.php')
        ->and(ControlSafe::text("Widget\u{00AD}.php"))->toBe('Widget?.php');
});

it('replaces a control character above the ascii range', function (): void {
    expect(ControlSafe::text("read\u{009B}2Kme.md"))->toBe('read?2Kme.md');
});

it('keeps a tab, a newline and a character of a language', function (): void {
    expect(ControlSafe::text("a\tb\nc"))->toBe("a\tb\nc")
        ->and(ControlSafe::text('src/日本語.php'))->toBe('src/日本語.php');
});

it('replaces an escape sequence of a path that holds no readable encoding', function (): void {
    expect(ControlSafe::text("bad \xC3\x28 \x1b[2K"))->toBe("bad \xC3\x28 ?[2K");
});
