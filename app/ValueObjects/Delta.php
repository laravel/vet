<?php

declare(strict_types=1);

namespace App\ValueObjects;

use App\Enums\BucketType;
use App\Enums\InstallSourceType;

final readonly class Delta
{
    /**
     * @var array<int, Change>
     */
    private array $changes;

    /**
     * @param  array<int, Change>  $changes
     * @param  array<int, string>  $notes
     */
    public function __construct(
        public string $package,
        public string $from,
        public string $to,
        public TreeHash $fromHash,
        public TreeHash $toHash,
        public InstallSourceType $source,
        array $changes,
        public ?ManifestChange $manifestChange,
        public bool $firstInstall,
        public bool $toIsLocalInstall,
        public array $notes,
    ) {
        usort($changes, static fn (Change $a, Change $b): int => [$a->bucket->weight(), $a->path] <=> [$b->bucket->weight(), $b->path]);

        $this->changes = $changes;
    }

    public function comparesPublishedToInstalled(): bool
    {
        return $this->toIsLocalInstall && $this->from === $this->to;
    }

    public function isDowngrade(): bool
    {
        if ($this->firstInstall || $this->comparesPublishedToInstalled()) {
            return false;
        }

        return version_compare(ltrim($this->to, 'v'), ltrim($this->from, 'v'), '<');
    }

    /**
     * @return array<int, Change>
     */
    public function changes(): array
    {
        return $this->changes;
    }

    /**
     * @return array<int, Change>
     */
    public function inBucket(BucketType $bucket): array
    {
        return array_values(array_filter(
            $this->changes,
            static fn (Change $change): bool => $change->bucket === $bucket,
        ));
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
                implode(', ', array_map(static fn (Change $change): string => $change->path, array_slice($opaque, 0, 3))),
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
