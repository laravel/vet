<?php

declare(strict_types=1);

namespace App\Enums;

enum UnreadReason: string
{
    case TooBig = 'too-big';

    case OverBudget = 'over-budget';

    case NotText = 'not-text';

    case NotReadable = 'not-readable';

    public function label(): string
    {
        return match ($this) {
            self::TooBig => 'too big',
            self::OverBudget => 'over the budget',
            self::NotText => 'not text',
            self::NotReadable => 'not readable',
        };
    }
}
