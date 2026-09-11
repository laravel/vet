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
        private InstalledRepository $installed,
    ) {}

    public static function forProject(Project $project): self
    {
        return new self(
            FetchPackageMetadata::default(),
            FetchArchive::default(),
            new BuildDelta,
            InstalledRepository::fromProject($project),
        );
    }

    public function resolve(string $package, string $from, string $to): Delta
    {
        $toMetadata = $this->packagist->version($package, $to);
        $fromMetadata = $this->packagist->version($package, $from);

        $this->assertDifferent($package, $fromMetadata->version, $toMetadata->version);

        return $this->between($package, $fromMetadata, $toMetadata, $this->fetcher->handle($toMetadata), InstallSourceType::Dist, false, []);
    }

    public function resolveInstalled(string $package, string $from): Delta
    {
        if (! $this->installed->has($package)) {
            return $this->resolve($package, $from, $this->newest($package));
        }

        $installed = $this->installed->get($package);
        $toMetadata = $this->packagist->version($package, $installed->version);

        if ($installed->installPath === null || ! is_dir($installed->installPath) || $installed->version !== $toMetadata->version) {
            return $this->resolve($package, $from, $installed->version);
        }

        $fromMetadata = $this->packagist->version($package, $from);

        if ($installed->installSource === InstallSourceType::Source) {
            $this->assertDifferent($package, $fromMetadata->version, $toMetadata->version);

            return $this->between($package, $fromMetadata, $toMetadata, $this->fetcher->handle($toMetadata), InstallSourceType::Source, false, [sprintf(
                '[%s] is installed from source; comparing dist archives instead. An audit of this delta does not cover your source install.',
                $installed->name,
            )]);
        }

        return $this->between($package, $fromMetadata, $toMetadata, $installed->installPath, InstallSourceType::Dist, true, []);
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

    public function incoming(Package $target, Package $installed): Delta
    {
        if ($installed->installPath === null) {
            throw new FailureException(sprintf('The package [%s] has no recorded install path.', $installed->name));
        }

        if (! is_dir($installed->installPath)) {
            throw new FailureException(sprintf('The install path [%s] of [%s] is not a directory.', $installed->installPath, $installed->name));
        }

        return $this->builder->handle(
            package: $target->name,
            fromVersion: $installed->version,
            fromDirectory: $installed->installPath,
            fromMetadata: $installed,
            toVersion: $target->version,
            toDirectory: $this->fetcher->handle($target),
            toMetadata: $target,
            source: $installed->installSource ?? InstallSourceType::Dist,
            toIsLocalInstall: false,
            notes: [],
        );
    }

    private function newest(string $package): string
    {
        return (string) array_key_first($this->packagist->versions($package));
    }

    /**
     * @param  array<int, string>  $notes
     */
    private function between(string $package, Package $from, Package $to, string $toDirectory, InstallSourceType $source, bool $toIsLocalInstall, array $notes): Delta
    {
        return $this->builder->handle(
            package: $package,
            fromVersion: $from->version,
            fromDirectory: $this->fetcher->handle($from),
            fromMetadata: $from,
            toVersion: $to->version,
            toDirectory: $toDirectory,
            toMetadata: $to,
            source: $source,
            toIsLocalInstall: $toIsLocalInstall,
            notes: $notes,
        );
    }

    private function assertDifferent(string $package, string $from, string $to): void
    {
        if ($from === $to) {
            throw new FailureException(sprintf('[%s] [%s] and [%s] are the same version.', $package, $from, $to));
        }
    }
}
