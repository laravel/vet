<?php

declare(strict_types=1);

namespace App\ValueObjects;

use App\Enums\AuditStatus;
use App\Enums\InstallSourceType;
use App\Enums\PackageStatus;

final readonly class PackageAudit
{
    public function __construct(
        public string $package,
        public string $version,
        public ?TreeHash $hash,
        public bool $dev,
        public AuditStatus $status,
        public int $files,
        public int $bytes,
        public ?Grant $grant = null,
        public InstallSourceType $source = InstallSourceType::Dist,
        public PackageStatus $state = PackageStatus::Installed,
        public ?string $from = null,
        public ?string $cause = null,
        public ?string $path = null,
    ) {}

    public function fails(): bool
    {
        return $this->status->fails();
    }

    public function pending(): bool
    {
        return $this->state === PackageStatus::Pending;
    }

    public function versions(): string
    {
        return $this->from === null || $this->from === $this->version
            ? $this->version
            : sprintf('%s → %s', $this->from, $this->version);
    }

    public function reason(): string
    {
        return match ($this->status) {
            AuditStatus::Covered => sprintf('trusted at [%s]', $this->shortHash()),
            AuditStatus::Ungranted => $this->ungrantedReason(),
            AuditStatus::Changed => $this->changedReason(),
            AuditStatus::Unknown => $this->cause ?? 'these bytes cannot be read before they are installed',
        };
    }

    private function ungrantedReason(): string
    {
        return $this->pending()
            ? sprintf('composer would install these bytes; no entry in the trust file [%s]', $this->shortHash())
            : sprintf('no entry; this tree is [%s]', $this->shortHash());
    }

    private function changedReason(): string
    {
        $granted = $this->grant instanceof Grant ? $this->grant->version : 'nothing';

        if ($this->pending()) {
            return $this->grant instanceof Grant && $this->grant->version === $this->version
                ? sprintf(
                    'composer would install [%s] again, and its bytes changed ([%s], not [%s])',
                    $this->version,
                    $this->shortHash(),
                    $this->grant->hash->short(),
                )
                : sprintf('composer would install these bytes; you trust [%s]', $granted);
        }

        if (! $this->grant instanceof Grant || $this->grant->version !== $this->version) {
            return sprintf('[%s] was trusted, [%s] is installed', $granted, $this->version);
        }

        $reason = sprintf(
            '[%s] is still installed but its bytes changed ([%s], not [%s])',
            $this->version,
            $this->shortHash(),
            $this->grant->hash->short(),
        );

        if ($this->source === InstallSourceType::Source) {
            $reason .= '; this tree came from --prefer-source';
        }

        return $reason;
    }

    private function shortHash(): string
    {
        return $this->hash instanceof TreeHash ? $this->hash->short() : 'no hash';
    }
}
