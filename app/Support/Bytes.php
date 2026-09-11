<?php

declare(strict_types=1);

namespace App\Support;

final class Bytes
{
    public static function human(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $amount = (float) $bytes;
        $unit = 0;

        while ($amount >= 1024 && $unit < count($units) - 1) {
            $amount /= 1024;
            $unit++;
        }

        return $unit === 0
            ? sprintf('%d %s', $amount, $units[$unit])
            : sprintf('%.1f %s', $amount, $units[$unit]);
    }
}
