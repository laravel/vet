<?php

declare(strict_types=1);

namespace App\Actions;

final readonly class ColdCacheArtifact implements CacheArtifact
{
    public function __construct(
        private CacheArtifact $writes,
    ) {}

    public function forPackage(string $section, string $package, string ...$segments): string
    {
        return $this->writes->forPackage($section, $package, ...$segments);
    }

    public function has(string $path): bool
    {
        return false;
    }

    public function fresh(string $path, int $seconds): ?string
    {
        return null;
    }

    public function put(string $path, string $contents): void
    {
        $this->writes->put($path, $contents);
    }
}
