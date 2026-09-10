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

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'type' => 'version-mismatch',
            'package' => $this->package,
            'installed' => $this->installed,
            'locked' => $this->locked,
        ];
    }
}
