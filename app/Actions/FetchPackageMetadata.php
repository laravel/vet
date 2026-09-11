<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\FailureException;
use App\Exceptions\VetException;
use App\Support\Json;
use App\ValueObjects\Package;

final class FetchPackageMetadata
{
    private const string ENDPOINT = 'https://repo.packagist.org/p2/%s.json';

    private const string DOCUMENT = 'index.json';

    private const int TTL = 3600;

    /**
     * @var array<string, array<string, Package>>
     */
    private array $memoized = [];

    /**
     * @var array<string, bool>
     */
    private array $downloaded = [];

    public function __construct(
        private readonly RequestUrl $http,
        private readonly CacheArtifact $cache,
    ) {}

    public static function default(): self
    {
        return new self(RequestUrl::default(), app(CacheArtifact::class));
    }

    /**
     * @return array<string, Package>
     */
    public function versions(string $package): array
    {
        if (isset($this->memoized[$package])) {
            return $this->memoized[$package];
        }

        $this->assertValidName($package);

        $body = $this->cache->fresh($this->documentPath($package), self::TTL);

        return $body === null
            ? $this->download($package)
            : $this->memoized[$package] = $this->parse($package, $body);
    }

    public function version(string $package, string $version): Package
    {
        $found = $this->find($this->versions($package), $version);

        if ($found instanceof Package) {
            return $found;
        }

        if (! isset($this->downloaded[$package])) {
            $found = $this->find($this->downloadOrKeep($package), $version);
        }

        if ($found instanceof Package) {
            return $found;
        }

        throw new FailureException(sprintf(
            'Packagist has no version [%s] of [%s]. Known versions include: [%s].',
            $version,
            $package,
            implode(', ', array_slice(array_keys($this->versions($package)), 0, 8)),
        ));
    }

    /**
     * @return array<int, string>
     */
    private function aliasesOf(string $version): array
    {
        return [$version, 'v'.$version, ltrim($version, 'v')];
    }

    /**
     * @param  array<string, Package>  $versions
     */
    private function find(array $versions, string $version): ?Package
    {
        foreach ($this->aliasesOf($version) as $candidate) {
            if (isset($versions[$candidate])) {
                return $versions[$candidate];
            }
        }

        return null;
    }

    /**
     * @return array<string, Package>
     */
    private function downloadOrKeep(string $package): array
    {
        try {
            return $this->download($package);
        } catch (VetException) {
            return $this->memoized[$package] ?? [];
        }
    }

    /**
     * @return array<string, Package>
     */
    private function download(string $package): array
    {
        $body = $this->http->get(sprintf(self::ENDPOINT, $package));

        $this->cache->put($this->documentPath($package), $body);
        $this->downloaded[$package] = true;

        return $this->memoized[$package] = $this->parse($package, $body);
    }

    /**
     * @return array<string, Package>
     */
    private function parse(string $package, string $body): array
    {
        $decoded = json_decode($body, true);

        if (! is_array($decoded)) {
            throw new FailureException(sprintf('Packagist returned unreadable metadata for [%s].', $package));
        }

        /** @var array<string, mixed> $decoded */
        $packages = Json::array($decoded, 'packages');
        $entries = is_array($packages[$package] ?? null) ? $packages[$package] : null;

        if (! is_array($entries) || $entries === []) {
            throw new FailureException(sprintf('Packagist knows no released versions of [%s].', $package));
        }

        if (ExpandMinifiedMetadata::isMinified($decoded)) {
            $entries = ExpandMinifiedMetadata::handle(array_values($entries));
        }

        $versions = [];

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            /** @var array<string, mixed> $entry */
            $version = Json::string($entry, 'version');

            if ($version === null) {
                continue;
            }

            $versions[$version] = Package::fromLockEntry($entry, false);
        }

        if ($versions === []) {
            throw new FailureException(sprintf('Packagist returned no usable version entries for [%s].', $package));
        }

        return $versions;
    }

    private function documentPath(string $package): string
    {
        return $this->cache->forPackage('metadata', $package, self::DOCUMENT);
    }

    private function assertValidName(string $package): void
    {
        if (preg_match('#^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*$#i', $package) !== 1) {
            throw new FailureException(sprintf('[%s] is not a valid package name; expected [vendor/name].', $package));
        }
    }
}
