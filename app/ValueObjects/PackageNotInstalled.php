<?php

declare(strict_types=1);

namespace App\ValueObjects;

final readonly class PackageNotInstalled implements LockDiscrepancy
{
    public function __construct(
        public string $package,
        public string $locked,
    ) {}

    public function message(): string
    {
        return sprintf('[%s] is in composer.lock at [%s] but is not installed', $this->package, $this->locked);
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'type' => 'not-installed',
            'package' => $this->package,
            'locked' => $this->locked,
        ];
    }
}
