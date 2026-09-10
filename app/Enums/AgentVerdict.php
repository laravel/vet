<?php

declare(strict_types=1);

namespace App\Enums;

enum AgentVerdict: string
{
    case Clear = 'clear';

    case Risk = 'risk';

    case Partial = 'partial';

    case Unreadable = 'unreadable';

    public static function read(string $word): self
    {
        return match (mb_strtolower(trim($word))) {
            'clear' => self::Clear,
            'risk' => self::Risk,
            default => self::Unreadable,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Clear => 'clear',
            self::Risk => 'RISK',
            self::Partial => 'partial',
            self::Unreadable => 'no verdict',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Clear => 'green',
            self::Risk => 'red',
            self::Partial, self::Unreadable => 'yellow',
        };
    }
}
