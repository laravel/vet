<?php

declare(strict_types=1);

namespace App\ValueObjects;

use App\Exceptions\InvalidJsonException;
use App\Support\Json;

final readonly class LockFile
{
    /**
     * @param  array<string, Package>  $packages
     */
    private function __construct(
        private array $packages,
    ) {}

    public static function fromProject(Project $project): self
    {
        $path = $project->lockPath();
        $document = Json::readFile($path, 'the lock file');

        $packages = [];

        foreach (['packages' => false, 'packages-dev' => true] as $key => $dev) {
            foreach (Json::array($document, $key) as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                /** @var array<string, mixed> $entry */
                $name = Json::string($entry, 'name');

                if ($name === null) {
                    continue;
                }

                $packages[$name] = Package::fromLockEntry($entry, $dev);
            }
        }

        if ($packages === []) {
            throw InvalidJsonException::structure($path, 'the lock file lists no packages.');
        }

        ksort($packages, SORT_STRING);

        return new self($packages);
    }

    /**
     * @return array<string, Package>
     */
    public function packages(): array
    {
        return $this->packages;
    }

    /**
     * @return array<string, Package>
     */
    public function packagesInstalledBy(InstalledRepository $installed): array
    {
        if ($installed->installsDev()) {
            return $this->packages;
        }

        return array_filter($this->packages, static fn (Package $package): bool => ! $package->dev);
    }
}
