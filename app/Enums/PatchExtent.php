<?php

declare(strict_types=1);

namespace App\Enums;

enum PatchExtent
{
    case Full;
    case Abridged;

    private const int ABRIDGED_LINES = 40;

    public function lines(): int
    {
        return match ($this) {
            self::Full => PHP_INT_MAX,
            self::Abridged => self::ABRIDGED_LINES,
        };
    }
}
