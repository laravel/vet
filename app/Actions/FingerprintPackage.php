<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\InstallSourceType;
use App\Exceptions\FailureException;
use App\ValueObjects\Fingerprint;
use App\ValueObjects\Manifest;
use App\ValueObjects\Package;

final readonly class FingerprintPackage
{
    public function __construct(
        private FetchArchive $fetcher,
    ) {}

    public function ofPackage(Package $package): Fingerprint
    {
        if ($package->installPath === null) {
            throw new FailureException(sprintf('The package [%s] has no recorded install path.', $package->name));
        }

        $manifest = Manifest::ofDirectory($package->installPath);

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

    public function ofIncoming(Package $target, bool $useCache = true): Fingerprint
    {
        $directory = $this->fetcher->handle($target, $useCache);
        $manifest = Manifest::ofDirectory($directory);

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
