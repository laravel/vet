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

    public function resolve(string $package, ?string $from = null, ?string $to = null): Delta
    {
        $installed = $this->installed instanceof InstalledRepository && $this->installed->has($package)
            ? $this->installed->get($package)
            : null;

        $newest = (string) array_key_first($this->packagist->versions($package));

        $toMetadata = $this->packagist->version($package, $to ?? $installed->version ?? $newest);
        $toVersion = $toMetadata->version;

        $fromVersion = $from ?? $this->packagist->previousVersion($package, $toVersion);

        if ($fromVersion === null) {
            throw new FailureException(sprintf(
                '[%s@%s] has no earlier release to compare against. Pass an explicit version: [vet %s --from=<version>].',
                $package,
                $toVersion,
                $package,
            ));
        }

        $fromMetadata = $this->packagist->version($package, $fromVersion);
        $fromVersion = $fromMetadata->version;

        $notes = [];
        [$toDirectory, $toIsLocal, $source] = $this->toTree($installed, $toMetadata, $to, $notes);

        if ($fromVersion === $toVersion && ! $toIsLocal) {
            throw new FailureException(sprintf('[%s] [%s] and [%s] are the same version.', $package, $fromVersion, $toVersion));
        }

        $delta = $this->builder->handle(
            package: $package,
            fromVersion: $fromVersion,
            fromDirectory: $this->fetcher->handle($fromMetadata),
            fromMetadata: $fromMetadata,
            toVersion: $toVersion,
            toDirectory: $toDirectory,
            toMetadata: $toMetadata,
            source: $source,
        );

        return $delta->withResolution($toIsLocal, $notes);
    }

    public function fromNothing(Package $target): Delta
    {
        $directory = $target->installPath !== null && is_dir($target->installPath)
            ? $target->installPath
            : $this->fetcher->handle($target);

        return $this->builder->firstInstall(
            $target,
            $directory,
            $target->installSource ?? InstallSourceType::Dist,
        );
    }

    public function incoming(Package $target, ?Package $installed): ?Delta
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
            toDirectory: $this->fetcher->handle($target),
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
        array &$notes,
    ): array {
        $usable = $explicitTo === null
            && $installed instanceof Package
            && $installed->version === $toMetadata->version
            && $installed->installPath !== null
            && is_dir($installed->installPath);

        if (! $usable) {
            return [$this->fetcher->handle($toMetadata), false, InstallSourceType::Dist];
        }

        /** @var Package $installed */
        $source = $installed->installSource ?? InstallSourceType::Dist;

        if ($source === InstallSourceType::Source) {
            $notes[] = sprintf(
                '[%s] is installed from source; comparing dist archives instead. An audit of this delta does not cover your source install.',
                $installed->name,
            );

            return [$this->fetcher->handle($toMetadata), false, InstallSourceType::Source];
        }

        /** @var string $path */
        $path = $installed->installPath;

        return [$path, true, InstallSourceType::Dist];
    }
}
