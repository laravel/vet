<?php

declare(strict_types=1);

namespace App\Enums;

enum AgentVerdict: string
{
    case Clear = 'clear';

    case Risk = 'risk';

    case Partial = 'partial';

    case NoVerdict = 'no-verdict';

    public static function read(string $word): self
    {
        return match (mb_strtolower(trim($word))) {
            'clear' => self::Clear,
            'risk' => self::Risk,
            default => self::NoVerdict,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Clear => 'clear',
            self::Risk => 'RISK',
            self::Partial => 'partial',
            self::NoVerdict => 'no verdict',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Clear => 'green',
            self::Risk => 'red',
            self::Partial, self::NoVerdict => 'yellow',
        };
    }
}
