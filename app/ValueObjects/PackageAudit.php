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
        public ?Grant $grant,
        public InstallSourceType $source,
        public PackageStatus $state,
        public ?string $from,
        public ?string $cause,
        public ?string $path,
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
        $from = $this->earlierVersion();

        return $from === null || $from === $this->version
            ? $this->version
            : sprintf('%s → %s', $from, $this->version);
    }

    public function note(): string
    {
        return match ($this->status) {
            AuditStatus::Covered => sprintf('trusted at [%s]', $this->shortHash()),
            AuditStatus::Ungranted => 'never trusted',
            AuditStatus::Changed => $this->changedNote(),
            AuditStatus::Unknown => $this->cause ?? 'not readable before install',
        };
    }

    private function earlierVersion(): ?string
    {
        return $this->from ?? ($this->status === AuditStatus::Changed ? $this->grant?->version : null);
    }

    private function changedNote(): string
    {
        $trusted = $this->grant?->version;

        if ($trusted === $this->version) {
            return $this->sameVersionNote();
        }

        return $trusted === $this->earlierVersion() ? '' : sprintf('you trust %s', $trusted ?? 'nothing');
    }

    private function sameVersionNote(): string
    {
        $note = sprintf(
            'same version, different code (%s → %s)',
            $this->grant?->hash->short() ?? 'no hash',
            $this->shortHash(),
        );

        return $this->source === InstallSourceType::Source ? $note.', installed with [--prefer-source]' : $note;
    }

    private function shortHash(): string
    {
        return $this->hash instanceof TreeHash ? $this->hash->short() : 'no hash';
    }
}
