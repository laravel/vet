<?php

declare(strict_types=1);

namespace App\Enums;

enum ComposerChangeType: string
{
    case Install = 'install';

    case Upgrade = 'upgrade';

    case Downgrade = 'downgrade';

    case Remove = 'remove';

    public static function fromVerb(string $verb): self
    {
        return match (mb_strtolower($verb)) {
            'installing' => self::Install,
            'upgrading' => self::Upgrade,
            'downgrading' => self::Downgrade,
            default => self::Remove,
        };
    }
}
