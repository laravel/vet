<?php

declare(strict_types=1);

namespace App\ValueObjects;

use App\Enums\UnreadReason;

final readonly class UnreadFile
{
    public function __construct(
        public string $path,
        public UnreadReason $reason,
        public int $bytes,
    ) {}
}
