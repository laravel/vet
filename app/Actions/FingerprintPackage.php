<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\InstallSourceType;
use App\Exceptions\FailureException;
use App\ValueObjects\Fingerprint;
use App\ValueObjects\IgnoredFiles;
use App\ValueObjects\Manifest;
use App\ValueObjects\Package;

final readonly class FingerprintPackage
{
    public function __construct(
        private FetchArchive $fetcher,
        private IgnoredFiles $ignored,
    ) {}

    public function ofPackage(Package $package): Fingerprint
    {
        if ($package->installPath === null) {
            throw new FailureException(sprintf('The package [%s] has no recorded install path.', $package->name));
        }

        $manifest = Manifest::ofDirectoryIgnoring($package->installPath, $this->ignored->of($package->name));

        return new Fingerprint(
            package: $package->name,
            version: $package->version,
            source: $package->installSource ?? InstallSourceType::Dist,
            hash: $manifest->hash(),
            path: $package->installPath,
            files: $manifest->count(),
            bytes: $manifest->bytes(),
        );
    }

    public function ofIncoming(Package $target): Fingerprint
    {
        $directory = $this->fetcher->handle($target);
        $manifest = Manifest::ofDirectoryIgnoring($directory, $this->ignored->of($target->name));

        return new Fingerprint(
            package: $target->name,
            version: $target->version,
            source: InstallSourceType::Dist,
            hash: $manifest->hash(),
            path: $directory,
            files: $manifest->count(),
            bytes: $manifest->bytes(),
        );
    }
}
