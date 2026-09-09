<?php

declare(strict_types=1);

namespace App\ValueObjects;

use App\Exceptions\InvalidJsonException;
use App\Exceptions\PackageNotInstalledException;
use App\Support\Json;

final readonly class InstalledRepository
{
    /**
     * @param  array<string, Package>  $packages  keyed by package name
     */
    private function __construct(
        private array $packages,
    ) {}

    public static function fromProject(Project $project): self
    {
        $path = $project->installedJsonPath();
        $data = Json::readFile($path, 'the installed package list');

        $entries = Json::array($data, 'packages');

        if ($entries === []) {
            throw InvalidJsonException::shape($path, 'expected a non-empty "packages" array. Run `composer install` first.');
        }

        $devNames = array_flip(array_filter(Json::array($data, 'dev-package-names'), is_string(...)));
        $vendorComposerPath = dirname($path);

        $packages = [];

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            /** @var array<string, mixed> $entry */
            $name = Json::string($entry, 'name');

            if ($name === null) {
                continue;
            }

            $packages[$name] = Package::fromInstalledEntry($entry, isset($devNames[$name]), $vendorComposerPath);
        }

        if ($packages === []) {
            throw InvalidJsonException::shape($path, 'no package entries carried a name.');
        }

        ksort($packages, SORT_STRING);

        return new self($packages);
    }

    /**
     * @return array<string, Package>
     */
    public function all(): array
    {
        return $this->packages;
    }

    public function has(string $name): bool
    {
        return isset($this->packages[$name]);
    }

    public function get(string $name): Package
    {
        if (isset($this->packages[$name])) {
            return $this->packages[$name];
        }

        $provider = $this->findProviderOf($name);

        if ($provider instanceof Package) {
            return $provider;
        }

        throw PackageNotInstalledException::named($name);
    }

    public function findProviderOf(string $name): ?Package
    {
        foreach ($this->packages as $package) {
            if (in_array($name, $package->aliases(), true)) {
                return $package;
            }
        }

        return null;
    }
}
