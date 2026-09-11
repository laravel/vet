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
            self::Clear => 'PASS',
            self::Risk => 'FAIL',
            self::Partial, self::NoVerdict => 'WARN',
        };
    }

    public function badge(): string
    {
        return sprintf('<%s;options=bold> %s </>', match ($this) {
            self::Clear => 'fg=white;bg=green',
            self::Risk => 'fg=white;bg=red',
            self::Partial, self::NoVerdict => 'fg=black;bg=yellow',
        }, $this->label());
    }
}
