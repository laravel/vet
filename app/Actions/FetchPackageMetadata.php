<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\FailureException;
use App\Support\Json;
use App\ValueObjects\Package;

final class FetchPackageMetadata
{
    private const string ENDPOINT = 'https://repo.packagist.org/p2/%s.json';

    private const int TTL = 3600;

    /**
     * @var array<string, array<string, Package>>
     */
    private array $memoized = [];

    public function __construct(
        private readonly RequestUrl $http,
        private readonly CacheArtifact $cache,
    ) {}

    public static function default(): self
    {
        return new self(RequestUrl::default(), CacheArtifact::default());
    }

    public static function isStable(string $version): bool
    {
        return preg_match('/(-|\.)(dev|alpha|beta|rc|pl)([.\-]?\d+)?$/i', $version) !== 1
            && ! str_ends_with($version, '-dev');
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

        $path = $this->cache->path('metadata', str_replace('/', '-', $package).'.json');
        $body = $this->cache->fresh($path, self::TTL);

        if ($body === null) {
            $body = $this->http->get(sprintf(self::ENDPOINT, $package));
            $this->cache->put($path, $body);
        }

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

        return $this->memoized[$package] = $versions;
    }

    public function version(string $package, string $version): Package
    {
        $versions = $this->versions($package);

        foreach ([$version, 'v'.$version, ltrim($version, 'v')] as $candidate) {
            if (isset($versions[$candidate])) {
                return $versions[$candidate];
            }
        }

        throw new FailureException(sprintf(
            'Packagist has no version [%s] of [%s]. Known versions include: [%s].',
            $version,
            $package,
            implode(', ', array_slice(array_keys($versions), 0, 8)),
        ));
    }

    public function previousVersion(string $package, string $version): ?string
    {
        $versions = array_keys($this->versions($package));
        $wantStable = self::isStable($version);

        $index = false;

        foreach ([$version, 'v'.$version, ltrim($version, 'v')] as $candidate) {
            $found = array_search($candidate, $versions, true);

            if ($found !== false) {
                $index = $found;

                break;
            }
        }

        if ($index === false) {
            return null;
        }

        $counter = count($versions);

        for ($i = $index + 1; $i < $counter; $i++) {
            if (! $wantStable || self::isStable($versions[$i])) {
                return $versions[$i];
            }
        }

        return null;
    }

    private function assertValidName(string $package): void
    {
        if (preg_match('#^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*$#i', $package) !== 1) {
            throw new FailureException(sprintf('[%s] is not a valid package name; expected "vendor/name".', $package));
        }
    }
}
