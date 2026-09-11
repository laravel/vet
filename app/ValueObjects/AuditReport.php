<?php

declare(strict_types=1);

namespace App\ValueObjects;

use App\Enums\AuditStatus;

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
    public function ofStatus(AuditStatus $status): array
    {
        return array_filter(
            $this->packages,
            static fn (PackageAudit $audit): bool => $audit->status === $status,
        );
    }

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

    /**
     * @return array<string, int>
     */
    public function counts(): array
    {
        $counts = [];

        foreach (AuditStatus::cases() as $status) {
            $counts[$status->value] = count($this->ofStatus($status));
        }

        return $counts;
    }

    public function percentage(): float
    {
        return $this->total() === 0 ? 100.0 : round($this->coveredCount() / $this->total() * 100, 1);
    }
}
