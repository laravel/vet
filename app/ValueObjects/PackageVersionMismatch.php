<?php

declare(strict_types=1);

namespace App\ValueObjects;

final readonly class PackageVersionMismatch implements LockDiscrepancy
{
    public function __construct(
        public string $package,
        public string $installed,
        public string $locked,
    ) {}

    public function message(): string
    {
        return sprintf(
            '[%s] is installed at [%s] but composer.lock says [%s]',
            $this->package,
            $this->installed,
            $this->locked,
        );
    }
}
