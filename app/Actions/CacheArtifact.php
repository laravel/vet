<?php

declare(strict_types=1);

namespace App\Actions;

interface CacheArtifact
{
    public function forPackage(string $section, string $package, string ...$segments): string;

    public function has(string $path): bool;

    public function fresh(string $path, int $seconds): ?string;

    public function put(string $path, string $contents): void;
}
