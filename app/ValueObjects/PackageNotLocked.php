<?php

declare(strict_types=1);

namespace App\ValueObjects;

final readonly class PackageNotLocked implements LockDiscrepancy
{
    public function __construct(
        public string $package,
        public string $installed,
    ) {}

    public function message(): string
    {
        return sprintf('[%s] is installed at [%s] but is not in composer.lock', $this->package, $this->installed);
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'type' => 'not-locked',
            'package' => $this->package,
            'installed' => $this->installed,
        ];
    }
}
