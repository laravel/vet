<?php

declare(strict_types=1);

namespace App\ValueObjects;

use App\Enums\InstallSourceType;

final readonly class Fingerprint
{
    public function __construct(
        public string $package,
        public string $version,
        public InstallSourceType $source,
        public TreeHash $hash,
        public string $path,
        public int $files,
        public int $bytes,
    ) {}
}
