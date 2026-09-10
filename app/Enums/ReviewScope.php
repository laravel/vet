<?php

declare(strict_types=1);

namespace App\Enums;

enum ReviewScope: string
{
    case Delta = 'delta';

    case PublishedDelta = 'published-delta';

    case WholePackage = 'whole-package';

    case NotReadable = 'not-readable';
}
