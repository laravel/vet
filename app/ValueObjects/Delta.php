<?php

declare(strict_types=1);

namespace App\ValueObjects;

use App\Enums\BucketType;
use App\Enums\InstallSourceType;

final readonly class Delta
{
    /**
     * @param  array<int, Change>  $changes
     * @param  array<int, string>  $notes  caveats about what was actually compared
     */
    public function __construct(
        public string $package,
        public string $from,
        public string $to,
        public TreeHash $fromHash,
        public TreeHash $toHash,
        public InstallSourceType $source,
        private array $changes,
        public ?ManifestChange $manifestChange,
        public bool $firstInstall,
        public bool $toIsLocalInstall = false,
        public array $notes = [],
    ) {}

    /**
     * @param  array<int, string>  $notes
     */
    public function withResolution(bool $toIsLocalInstall, array $notes): self
    {
        return new self(
            $this->package,
            $this->from,
            $this->to,
            $this->fromHash,
            $this->toHash,
            $this->source,
            $this->changes,
            $this->manifestChange,
            $this->firstInstall,
            $toIsLocalInstall,
            $notes,
        );
    }

    public function comparesPublishedToInstalled(): bool
    {
        return $this->toIsLocalInstall && $this->from === $this->to;
    }

    /**
     * @return array<int, Change>
     */
    public function changes(): array
    {
        $changes = $this->changes;

        usort($changes, static fn (Change $a, Change $b): int => [$a->bucket->weight(), $a->path] <=> [$b->bucket->weight(), $b->path]);

        return $changes;
    }

    /**
     * @return array<int, Change>
     */
    public function inBucket(BucketType $bucket): array
    {
        return array_values(array_filter(
            $this->changes(),
            static fn (Change $change): bool => $change->bucket === $bucket,
        ));
    }

    /**
     * @return array<string, int>
     */
    public function counts(): array
    {
        $counts = [];

        foreach (BucketType::inReviewOrder() as $bucket) {
            $counts[$bucket->value] = count($this->inBucket($bucket));
        }

        return $counts;
    }

    public function isEmpty(): bool
    {
        return $this->changes === [];
    }

    public function isInertOnly(): bool
    {
        foreach ($this->changes as $change) {
            if ($change->bucket !== BucketType::Inert) {
                return false;
            }
        }

        return $this->changes !== [];
    }

    public function needsNoReview(): bool
    {
        if ($this->changes === []) {
            return false;
        }

        foreach ($this->changes as $change) {
            if ($change->bucket === BucketType::Opaque || $change->bucket === BucketType::RuntimeSource) {
                return false;
            }
        }

        return ! $this->manifestChange instanceof ManifestChange || ! $this->manifestChange->touchesExecution();
    }

    /**
     * @return array<int, string>
     */
    public function reviewBlockers(): array
    {
        $blockers = [];

        $opaque = $this->inBucket(BucketType::Opaque);

        if ($opaque !== []) {
            $blockers[] = sprintf(
                '[%d] opaque %s cannot be read: [%s].',
                count($opaque),
                count($opaque) === 1 ? 'artifact' : 'artifacts',
                implode(', ', array_slice(array_map(static fn (Change $c): string => $c->path, $opaque), 0, 3)),
            );
        }

        if ($this->manifestChange instanceof ManifestChange && $this->manifestChange->touchesExecution()) {
            $blockers[] = sprintf(
                'composer.json changes what runs or what is loaded [%s].',
                implode(', ', $this->manifestChange->changedKeys()),
            );
        }

        return $blockers;
    }
}
