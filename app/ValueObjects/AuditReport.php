<?php

declare(strict_types=1);

namespace App\ValueObjects;

final readonly class AuditReport
{
    /**
     * @param  array<string, PackageAudit>  $packages
     */
    public function __construct(
        private array $packages,
    ) {}

    /**
     * @return array<string, PackageAudit>
     */
    public function failing(): array
    {
        return array_filter($this->packages, static fn (PackageAudit $audit): bool => $audit->fails());
    }

    public function total(): int
    {
        return count($this->packages);
    }

    public function coveredCount(): int
    {
        return count($this->packages) - count($this->failing());
    }
}
