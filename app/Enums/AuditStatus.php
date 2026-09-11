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
}
