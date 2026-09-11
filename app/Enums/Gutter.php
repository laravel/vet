<?php

declare(strict_types=1);

namespace App\Enums;

enum Gutter
{
    case None;
    case Package;

    public function line(string $text): string
    {
        return match ($this) {
            self::None => '  '.$text,
            self::Package => '  <fg=gray>│</> '.$text,
        };
    }

    public function blank(): string
    {
        return match ($this) {
            self::None => '',
            self::Package => '  <fg=gray>│</>',
        };
    }
}
