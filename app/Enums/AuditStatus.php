<?php

declare(strict_types=1);

namespace App\Enums;

enum AuditStatus: string
{
    case Covered = 'covered';

    case Ungranted = 'ungranted';

    case Changed = 'changed';

    case Unknown = 'unknown';

    public function fails(): bool
    {
        return $this !== self::Covered;
    }

    public function weight(): int
    {
        return match ($this) {
            self::Unknown => 0,
            self::Changed => 1,
            self::Ungranted => 2,
            self::Covered => 3,
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Unknown, self::Changed => 'red',
            self::Ungranted, self::Covered => 'yellow',
        };
    }
}
