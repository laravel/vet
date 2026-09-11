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
        3,
    );

    expect($diff)
        ->toContain('@@ -1,100 +1,100 @@')
        ->toContain('-old 1')
        ->toContain('+new 1')
        ->and(str_contains($diff, 'file rewritten'))->toBeFalse();
});

it('writes every line of a file that changed more than it reads line by line', function (): void {
    $diff = BuildUnifiedDiff::handle(
        joined(numbered('old', 300)),
        joined(numbered('new', 300)),
        'a/f',
        'b/f',
        3,
    );

    expect($diff)
        ->toContain('-old 1')
        ->toContain('-old 300')
        ->toContain('+new 1')
        ->toContain('+new 300')
        ->and(str_contains($diff, 'file rewritten'))->toBeFalse();
});

it('writes every line of a file that the delta adds', function (): void {
    $diff = BuildUnifiedDiff::handle('', joined(numbered('new', 757)), 'a/f', 'b/f', 3);

    expect($diff)
        ->toContain('@@ -0,0 +1,757 @@')
        ->toContain('+new 1')
        ->toContain('+new 757')
        ->and(str_contains($diff, 'file rewritten'))->toBeFalse();
});

it('writes every line of a file that the delta removes', function (): void {
    $diff = BuildUnifiedDiff::handle(joined(numbered('old', 757)), '', 'a/f', 'b/f', 3);

    expect($diff)
        ->toContain('@@ -1,757 +0,0 @@')
        ->toContain('-old 1')
        ->toContain('-old 757')
        ->and(str_contains($diff, 'file rewritten'))->toBeFalse();
});

it('counts the lines of the region that changed, and not the whole file', function (): void {
    $head = numbered('head', 100);
    $tail = numbered('tail', 100);

    $diff = BuildUnifiedDiff::handle(
        joined([...$head, ...numbered('old', 15_000), ...$tail]),
        joined([...$head, ...numbered('new', 15_000), ...$tail]),
        'a/f',
        'b/f',
        3,
    );

    expect($diff)->toContain('- 15000 line(s) replaced by 15000 line(s)');
});

it('reads one changed line of a large file, and names its place', function (): void {
    $lines = numbered('line', 20_000);
    $before = joined($lines);

    $lines[9_999] = 'TAMPERED';

    $diff = BuildUnifiedDiff::handle($before, joined($lines), 'a/f', 'b/f', 3);

    expect($diff)
        ->toContain('@@ -9997,7 +9997,7 @@')
        ->toContain('-line 10000')
        ->toContain('+TAMPERED')
        ->and(substr_count($diff, "\n"))->toBe(11);
});

it('writes a hunk of no context at the line that it follows', function (): void {
    $inserted = BuildUnifiedDiff::handle("a\nb\n", "a\nx\nb\n", 'a/f', 'b/f', 0);
    $replaced = BuildUnifiedDiff::handle("a\nc\nb\n", "a\nd\nb\n", 'a/f', 'b/f', 0);
    $removed = BuildUnifiedDiff::handle("a\nx\nb\n", "a\nb\n", 'a/f', 'b/f', 0);

    expect($inserted)->toBe("--- a/f\n+++ b/f\n@@ -1,0 +2,1 @@\n+x\n")
        ->and($replaced)->toBe("--- a/f\n+++ b/f\n@@ -2,1 +2,1 @@\n-c\n+d\n")
        ->and($removed)->toBe("--- a/f\n+++ b/f\n@@ -2,1 +1,0 @@\n-x\n");
});

it('writes hunks in the order of the lines', function (): void {
    $diff = BuildUnifiedDiff::handle("a\na\nb\nd\n", "a\na\nb\nd\nd\nb\nd\n", 'a/f', 'b/f', 0);

    expect($diff)->toBe("--- a/f\n+++ b/f\n@@ -4,0 +5,3 @@\n+d\n+b\n+d\n");
});

it('writes nothing for two contents that hold the same lines', function (): void {
    expect(BuildUnifiedDiff::handle("a\nb", "a\nb\n", 'a/f', 'b/f', 3))->toBe('');
});

it('writes one hunk for each change, and skips the lines between two changes that stand apart', function (): void {
    $before = numbered('line', 20);
    $after = $before;

    $after[1] = 'FIRST';
    $after[17] = 'SECOND';

    $diff = BuildUnifiedDiff::handle(joined($before), joined($after), 'a/f', 'b/f', 3);

    expect($diff)->toBe(
        "--- a/f\n+++ b/f\n"
        ."@@ -1,5 +1,5 @@\n line 1\n-line 2\n+FIRST\n line 3\n line 4\n line 5\n"
        ."@@ -15,6 +15,6 @@\n line 15\n line 16\n line 17\n-line 18\n+SECOND\n line 19\n line 20\n",
    );
});

it('holds its memory when a file is rewritten', function (): void {
    $before = memory_get_usage();

    BuildUnifiedDiff::handle(
        joined(numbered('old', 5_000)),
        joined(numbered('new', 5_000)),
        'a/f',
        'b/f',
        3,
    );

    expect(memory_get_peak_usage() - $before)->toBeLessThan(32 * 1024 * 1024);
});
