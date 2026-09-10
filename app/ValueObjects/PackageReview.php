<?php

declare(strict_types=1);

namespace App\ValueObjects;

final readonly class PackageReview
{
    private function __construct(
        public int $files,
        public string $scope,
        public ?Delta $delta,
    ) {}

    public static function ofDelta(Delta $delta): self
    {
        return new self(
            count($delta->changes()),
            $delta->comparesPublishedToInstalled()
                ? sprintf('delta from the published [%s]', $delta->from)
                : sprintf('delta from [%s]', $delta->from),
            $delta,
        );
    }

    public static function ofWholePackage(int $files): self
    {
        return new self($files, 'whole package', null);
    }

    public static function unreadable(): self
    {
        return new self(0, 'not readable', null);
    }
}
