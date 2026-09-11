<?php

declare(strict_types=1);

namespace App\ValueObjects;

use App\Enums\ReviewScope;

final readonly class PackageReview
{
    private function __construct(
        public int $files,
        public ReviewScope $scope,
        public ?Delta $delta,
    ) {}

    public static function ofDelta(Delta $delta): self
    {
        return new self(
            count($delta->changes()),
            $delta->comparesPublishedToInstalled() ? ReviewScope::PublishedDelta : ReviewScope::Delta,
            $delta,
        );
    }

    public static function ofWholePackage(int $files): self
    {
        return new self($files, ReviewScope::WholePackage, null);
    }

    public static function unreadable(): self
    {
        return new self(0, ReviewScope::NotReadable, null);
    }

    public function label(): string
    {
        return match ($this->scope) {
            ReviewScope::NotReadable => 'not readable',
            ReviewScope::WholePackage => sprintf('whole package, %s', $this->fileCount()),
            ReviewScope::Delta, ReviewScope::PublishedDelta => sprintf('%s changed', $this->fileCount()),
        };
    }

    private function fileCount(): string
    {
        return sprintf('%d %s', $this->files, $this->files === 1 ? 'file' : 'files');
    }
}
