<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ChangeStatus;
use App\Enums\InstallSourceType;
use App\Support\Json;
use App\ValueObjects\Change;
use App\ValueObjects\Delta;
use App\ValueObjects\Manifest;
use App\ValueObjects\ManifestChange;
use App\ValueObjects\Package;
use App\ValueObjects\TreeHash;

final class BuildDelta
{
    public function handle(
        string $package,
        string $fromVersion,
        string $fromDirectory,
        Package $fromMetadata,
        string $toVersion,
        string $toDirectory,
        Package $toMetadata,
        InstallSourceType $source,
    ): Delta {
        $before = Manifest::ofDirectory($fromDirectory);
        $after = Manifest::ofDirectory($toDirectory);

        $classifier = ClassifyPath::forPackages($fromMetadata, $toMetadata);

        $oldEntries = $before->entries();
        $newEntries = $after->entries();

        $changes = [];

        foreach ($newEntries as $path => $hash) {
            $oldHash = $oldEntries[$path] ?? null;

            if ($oldHash === $hash) {
                continue;
            }

            $newFile = $toDirectory.'/'.$path;
            $oldFile = $oldHash === null ? null : $fromDirectory.'/'.$path;

            $changes[] = new Change(
                path: $path,
                status: $oldHash === null ? ChangeStatus::Added : ChangeStatus::Modified,
                bucket: $classifier->handle($path, $newFile),
                oldHash: $oldHash,
                newHash: $hash,
                oldFile: $oldFile,
                newFile: $newFile,
            );
        }

        foreach ($oldEntries as $path => $hash) {
            if (isset($newEntries[$path])) {
                continue;
            }

            $oldFile = $fromDirectory.'/'.$path;

            $changes[] = new Change(
                path: $path,
                status: ChangeStatus::Removed,
                bucket: $classifier->handle($path, $oldFile),
                oldHash: $hash,
                newHash: null,
                oldFile: $oldFile,
                newFile: null,
            );
        }

        return new Delta(
            package: $package,
            from: $fromVersion,
            to: $toVersion,
            fromHash: $before->hash(),
            toHash: $after->hash(),
            source: $source,
            changes: $changes,
            manifestChange: $this->manifestChange($fromDirectory, $toDirectory),
            firstInstall: false,
        );
    }

    public function firstInstall(Package $target, string $directory, InstallSourceType $source): Delta
    {
        $manifest = Manifest::ofDirectory($directory);
        $classifier = ClassifyPath::forPackages($target);

        $changes = [];

        foreach ($manifest->entries() as $path => $hash) {
            $file = $directory.'/'.$path;

            $changes[] = new Change(
                path: $path,
                status: ChangeStatus::Added,
                bucket: $classifier->handle($path, $file),
                oldHash: null,
                newHash: $hash,
                oldFile: null,
                newFile: $file,
            );
        }

        return new Delta(
            package: $target->name,
            from: '',
            to: $target->version,
            fromHash: TreeHash::fromManifest(''),
            toHash: $manifest->hash(),
            source: $source,
            changes: $changes,
            manifestChange: null,
            firstInstall: true,
        );
    }

    private function manifestChange(string $fromDirectory, string $toDirectory): ?ManifestChange
    {
        $oldPath = $fromDirectory.'/composer.json';
        $newPath = $toDirectory.'/composer.json';

        if (! is_file($oldPath) || ! is_file($newPath)) {
            return null;
        }

        $change = ManifestChange::between(
            Json::readFile($oldPath, 'the previous package manifest'),
            Json::readFile($newPath, 'the package manifest'),
        );

        return $change->isEmpty() ? null : $change;
    }
}
