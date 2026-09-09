<?php

declare(strict_types=1);

use App\Actions\BuildUnifiedDiff;

/**
 * @param  array<int, string>  $lines
 */
function joined(array $lines): string
{
    return implode("\n", $lines)."\n";
}

/**
 * @return array<int, string>
 */
function numbered(string $prefix, int $count): array
{
    return array_map(static fn (int $line): string => $prefix.' '.$line, range(1, $count));
}

it('reads a file that changed within its budget', function (): void {
    $diff = BuildUnifiedDiff::handle(
        joined(numbered('old', 100)),
        joined(numbered('new', 100)),
        'a/f',
        'b/f',
    );

    expect($diff)
        ->toContain('@@ -1,100 +1,100 @@')
        ->toContain('-old 1')
        ->toContain('+new 1')
        ->and(str_contains($diff, 'file rewritten'))->toBeFalse();
});

it('summarises a file that changed more than it reads', function (): void {
    $diff = BuildUnifiedDiff::handle(
        joined(numbered('old', 300)),
        joined(numbered('new', 300)),
        'a/f',
        'b/f',
    );

    expect($diff)
        ->toContain('@@ file rewritten @@')
        ->toContain('- 300 line(s) replaced by 300 line(s)')
        ->and(str_contains($diff, '-old 1'))->toBeFalse();
});

it('counts the lines of the region that changed, and not the whole file', function (): void {
    $head = numbered('head', 100);
    $tail = numbered('tail', 100);

    $diff = BuildUnifiedDiff::handle(
        joined([...$head, ...numbered('old', 500), ...$tail]),
        joined([...$head, ...numbered('new', 500), ...$tail]),
        'a/f',
        'b/f',
    );

    expect($diff)->toContain('- 500 line(s) replaced by 500 line(s)');
});

it('reads one changed line of a large file, and names its place', function (): void {
    $lines = numbered('line', 20_000);
    $before = joined($lines);

    $lines[9_999] = 'TAMPERED';

    $diff = BuildUnifiedDiff::handle($before, joined($lines), 'a/f', 'b/f');

    expect($diff)
        ->toContain('@@ -9997,7 +9997,7 @@')
        ->toContain('-line 10000')
        ->toContain('+TAMPERED')
        ->and(substr_count($diff, "\n"))->toBe(11);
});

it('holds its memory when a file is rewritten', function (): void {
    $before = memory_get_usage();

    BuildUnifiedDiff::handle(
        joined(numbered('old', 5_000)),
        joined(numbered('new', 5_000)),
        'a/f',
        'b/f',
    );

    expect(memory_get_peak_usage() - $before)->toBeLessThan(32 * 1024 * 1024);
});
