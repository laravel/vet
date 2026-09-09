<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\InstallSourceType;
use App\Exceptions\FailureException;
use App\ValueObjects\Delta;
use App\ValueObjects\InstalledRepository;
use App\ValueObjects\Package;
use App\ValueObjects\Project;

final readonly class ResolveDelta
{
    public function __construct(
        private FetchPackageMetadata $packagist,
        private FetchArchive $fetcher,
        private BuildDelta $builder,
        private ?InstalledRepository $installed = null,
    ) {}

    public static function forProject(?Project $project): self
    {
        $installed = null;

        if ($project instanceof Project && is_file($project->installedJsonPath())) {
            $installed = InstalledRepository::fromProject($project);
        }

        return new self(
            FetchPackageMetadata::default(),
            FetchArchive::default(),
            new BuildDelta,
            $installed,
        );
    }

    public function resolve(string $package, ?string $from = null, ?string $to = null, bool $useCache = true): Delta
    {
        $installed = $this->installed instanceof InstalledRepository && $this->installed->has($package)
            ? $this->installed->get($package)
            : null;

        $versions = $this->packagist->versions($package);

        $toVersion = $to ?? $installed->version ?? array_key_first($versions);

        if (! is_string($toVersion)) {
            throw new FailureException(sprintf('Could not determine which version of [%s] to compare to.', $package));
        }

        $toMetadata = $this->packagist->version($package, $toVersion);
        $toVersion = $toMetadata->version;

        $fromVersion = $from ?? $this->packagist->previousVersion($package, $toVersion);

        if ($fromVersion === null) {
            throw new FailureException(sprintf(
                '[%s@%s] has no earlier release to compare against. Pass an explicit version: `vet audit %s <from>`.',
                $package,
                $toVersion,
                $package,
            ));
        }

        $fromMetadata = $this->packagist->version($package, $fromVersion);
        $fromVersion = $fromMetadata->version;

        $notes = [];
        [$toDirectory, $toIsLocal, $source] = $this->toTree($installed, $toMetadata, $to, $useCache, $notes);

        if ($fromVersion === $toVersion && ! $toIsLocal) {
            throw new FailureException(sprintf('[%s] [%s] and [%s] are the same version.', $package, $fromVersion, $toVersion));
        }

        $delta = $this->builder->handle(
            package: $package,
            fromVersion: $fromVersion,
            fromDirectory: $this->fetcher->handle($fromMetadata, $useCache),
            fromMetadata: $fromMetadata,
            toVersion: $toVersion,
            toDirectory: $toDirectory,
            toMetadata: $toMetadata,
            source: $source,
        );

        return $delta->withResolution($toIsLocal, $notes);
    }

    public function incoming(Package $target, ?Package $installed, bool $useCache = true): ?Delta
    {
        if (! $installed instanceof Package || $installed->installPath === null || ! is_dir($installed->installPath)) {
            return null;
        }

        $delta = $this->builder->handle(
            package: $target->name,
            fromVersion: $installed->version,
            fromDirectory: $installed->installPath,
            fromMetadata: $installed,
            toVersion: $target->version,
            toDirectory: $this->fetcher->handle($target, $useCache),
            toMetadata: $target,
            source: $installed->installSource ?? InstallSourceType::Dist,
        );

        return $delta->withResolution(false, []);
    }

    /**
     * @param  array<int, string>  $notes
     * @return array{0: string, 1: bool, 2: InstallSourceType}
     */
    private function toTree(
        ?Package $installed,
        Package $toMetadata,
        ?string $explicitTo,
        bool $useCache,
        array &$notes,
    ): array {
        $usable = $explicitTo === null
            && $installed instanceof Package
            && $installed->version === $toMetadata->version
            && $installed->installPath !== null
            && is_dir($installed->installPath);

        if (! $usable) {
            return [$this->fetcher->handle($toMetadata, $useCache), false, InstallSourceType::Dist];
        }

        /** @var Package $installed */
        $source = $installed->installSource ?? InstallSourceType::Dist;

        if ($source === InstallSourceType::Source) {
            $notes[] = sprintf(
                '[%s] is installed from source; comparing dist archives instead. An audit of this delta does not cover your source install.',
                $installed->name,
            );

            return [$this->fetcher->handle($toMetadata, $useCache), false, InstallSourceType::Source];
        }

        /** @var string $path */
        $path = $installed->installPath;

        return [$path, true, InstallSourceType::Dist];
    }
}
