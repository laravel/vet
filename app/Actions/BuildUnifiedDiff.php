<?php

declare(strict_types=1);

namespace App\Actions;

final class BuildUnifiedDiff
{
    public const string REWRITTEN = '@@ file rewritten @@';

    private const int MAX_EDIT_DISTANCE = 400;

    private const int MAX_CHANGED_LINES = 20_000;

    public static function handle(
        ?string $old,
        ?string $new,
        string $oldLabel,
        string $newLabel,
        int $context = 3,
    ): string {
        $oldLines = self::lines($old);
        $newLines = self::lines($new);

        if ($oldLines === $newLines) {
            return '';
        }

        $header = '--- '.$oldLabel."\n".'+++ '.$newLabel."\n";

        $ops = self::diff($oldLines, $newLines, $context);

        if ($ops === null) {
            [$prefix, $suffix] = self::commonEdges($oldLines, $newLines);

            return $header.sprintf(
                self::REWRITTEN."\n- %d line(s) replaced by %d line(s)\n",
                count($oldLines) - $prefix - $suffix,
                count($newLines) - $prefix - $suffix,
            );
        }

        $hunks = self::hunks($ops, $context);

        return $hunks === '' ? '' : $header.$hunks;
    }

    /**
     * @return array<int, string>
     */
    private static function lines(?string $content): array
    {
        if ($content === null || $content === '') {
            return [];
        }

        $lines = explode("\n", $content);

        if (end($lines) === '') {
            array_pop($lines);
        }

        return $lines;
    }

    /**
     * @param  array<int, string>  $a
     * @param  array<int, string>  $b
     * @return array<int, array{0: '='|'-'|'+', 1: int, 2: int, 3: string}>|null
     */
    private static function diff(array $a, array $b, int $context): ?array
    {
        $n = count($a);
        $m = count($b);

        [$prefix, $suffix] = self::commonEdges($a, $b);

        $middleA = array_slice($a, $prefix, $n - $prefix - $suffix);
        $middleB = array_slice($b, $prefix, $m - $prefix - $suffix);

        if (count($middleA) + count($middleB) > self::MAX_CHANGED_LINES) {
            return null;
        }

        $middle = self::myers($middleA, $middleB) ?? self::replacement($middleA, $middleB);

        $ops = [];

        for ($i = max($prefix - $context, 0); $i < $prefix; $i++) {
            $ops[] = ['=', $i, $i, $a[$i]];
        }

        foreach ($middle as $op) {
            $ops[] = [$op[0], $op[1] + $prefix, $op[2] + $prefix, $op[3]];
        }

        $tail = min($suffix, $context);

        for ($i = 0; $i < $tail; $i++) {
            $ops[] = ['=', $n - $suffix + $i, $m - $suffix + $i, $a[$n - $suffix + $i]];
        }

        return $ops;
    }

    /**
     * @param  array<int, string>  $a
     * @param  array<int, string>  $b
     * @return array{0: int, 1: int}
     */
    private static function commonEdges(array $a, array $b): array
    {
        $n = count($a);
        $m = count($b);

        $prefix = 0;

        while ($prefix < $n && $prefix < $m && $a[$prefix] === $b[$prefix]) {
            $prefix++;
        }

        $suffix = 0;

        while ($suffix < $n - $prefix && $suffix < $m - $prefix && $a[$n - 1 - $suffix] === $b[$m - 1 - $suffix]) {
            $suffix++;
        }

        return [$prefix, $suffix];
    }

    /**
     * @param  array<int, string>  $a
     * @param  array<int, string>  $b
     * @return array<int, array{0: '='|'-'|'+', 1: int, 2: int, 3: string}>|null
     */
    private static function myers(array $a, array $b): ?array
    {
        $n = count($a);
        $m = count($b);

        if ($n === 0 || $m === 0) {
            return self::replacement($a, $b);
        }

        $max = min($n + $m, self::MAX_EDIT_DISTANCE);
        $v = [1 => 0];
        $trace = [];

        for ($d = 0; $d <= $max; $d++) {
            $trace[$d] = $v;

            for ($k = -$d; $k <= $d; $k += 2) {
                $down = $k === -$d || ($k !== $d && ($v[$k - 1] ?? 0) < ($v[$k + 1] ?? 0));

                $x = $down ? ($v[$k + 1] ?? 0) : ($v[$k - 1] ?? 0) + 1;
                $y = $x - $k;

                while ($x < $n && $y < $m && $a[$x] === $b[$y]) {
                    $x++;
                    $y++;
                }

                $v[$k] = $x;

                if ($x >= $n && $y >= $m) {
                    return self::backtrack($trace, $a, $b, $d);
                }
            }
        }

        return null;
    }

    /**
     * @param  array<int, string>  $a
     * @param  array<int, string>  $b
     * @return array<int, array{0: '='|'-'|'+', 1: int, 2: int, 3: string}>
     */
    private static function replacement(array $a, array $b): array
    {
        $ops = [];

        foreach ($a as $x => $line) {
            $ops[] = ['-', $x, 0, $line];
        }

        foreach ($b as $y => $line) {
            $ops[] = ['+', count($a), $y, $line];
        }

        return $ops;
    }

    /**
     * @param  array<int, array<int, int>>  $trace
     * @param  array<int, string>  $a
     * @param  array<int, string>  $b
     * @return array<int, array{0: '='|'-'|'+', 1: int, 2: int, 3: string}>
     */
    private static function backtrack(array $trace, array $a, array $b, int $d): array
    {
        $ops = [];
        $x = count($a);
        $y = count($b);

        for ($step = $d; $step > 0; $step--) {
            $v = $trace[$step];
            $k = $x - $y;

            $down = $k === -$step || ($k !== $step && ($v[$k - 1] ?? 0) < ($v[$k + 1] ?? 0));
            $previousK = $down ? $k + 1 : $k - 1;
            $previousX = $v[$previousK] ?? 0;
            $previousY = $previousX - $previousK;

            while ($x > $previousX && $y > $previousY) {
                $x--;
                $y--;
                $ops[] = ['=', $x, $y, $a[$x]];
            }

            if ($down) {
                $y--;
                $ops[] = ['+', $x, $y, $b[$y]];
            } else {
                $x--;
                $ops[] = ['-', $x, $y, $a[$x]];
            }
        }

        return array_reverse($ops);
    }

    /**
     * @param  array<int, array{0: '='|'-'|'+', 1: int, 2: int, 3: string}>  $ops
     */
    private static function hunks(array $ops, int $context): string
    {
        $changed = [];

        foreach ($ops as $index => $op) {
            if ($op[0] !== '=') {
                $changed[] = $index;
            }
        }

        $groups = [];
        $start = $changed[0];
        $end = $changed[0];

        foreach (array_slice($changed, 1) as $index) {
            if ($index - $end <= $context * 2 + 1) {
                $end = $index;

                continue;
            }

            $groups[] = [$start, $end];
            $start = $index;
            $end = $index;
        }

        $groups[] = [$start, $end];

        $output = '';

        foreach ($groups as [$from, $to]) {
            $from = max(0, $from - $context);
            $to = min(count($ops) - 1, $to + $context);

            $oldStart = $ops[$from][1];
            $newStart = $ops[$from][2];
            $oldCount = 0;
            $newCount = 0;
            $body = '';

            for ($i = $from; $i <= $to; $i++) {
                [$op, , , $line] = $ops[$i];

                if ($op !== '+') {
                    $oldCount++;
                }

                if ($op !== '-') {
                    $newCount++;
                }

                $body .= match ($op) {
                    '=' => ' ',
                    '-' => '-',
                    '+' => '+',
                }.$line."\n";
            }

            $output .= sprintf(
                "@@ -%d,%d +%d,%d @@\n",
                $oldCount === 0 ? $oldStart : $oldStart + 1,
                $oldCount,
                $newCount === 0 ? $newStart : $newStart + 1,
                $newCount,
            ).$body;
        }

        return $output;
    }
}
