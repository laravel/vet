<?php

declare(strict_types=1);

namespace App\ValueObjects;

use App\Enums\InstallSourceType;
use App\Support\Json;
use App\Support\Path;

final readonly class Package
{
    private const string METAPACKAGE = 'metapackage';

    /**
     * @param  array<string, string>  $replace
     * @param  array<string, string>  $provide
     * @param  array<array-key, mixed>  $autoload
     * @param  array<int, string>  $bin
     */
    public function __construct(
        public string $name,
        public string $version,
        public string $type,
        public bool $dev,
        public ?string $distUrl,
        public ?string $distReference,
        public ?string $distShasum,
        public array $replace,
        public array $provide,
        public array $autoload,
        public array $bin,
        public ?InstallSourceType $installSource,
        public ?string $installPath,
    ) {}

    /**
     * @param  array<string, mixed>  $entry
     */
    public static function fromLockEntry(array $entry, bool $dev): self
    {
        $dist = Json::array($entry, 'dist');

        return new self(
            name: Json::string($entry, 'name') ?? '',
            version: Json::string($entry, 'version') ?? '',
            type: Json::string($entry, 'type') ?? 'library',
            dev: $dev,
            distUrl: Json::string($dist, 'url'),
            distReference: Json::string($dist, 'reference'),
            distShasum: Json::string($dist, 'shasum'),
            replace: self::constraints($entry, 'replace'),
            provide: self::constraints($entry, 'provide'),
            autoload: Json::array($entry, 'autoload'),
            bin: self::strings($entry, 'bin'),
            installSource: null,
            installPath: null,
        );
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    public static function fromInstalledEntry(array $entry, bool $dev, string $vendorComposerPath): self
    {
        $package = self::fromLockEntry($entry, $dev);

        $installPath = Json::string($entry, 'install-path');

        return new self(
            name: $package->name,
            version: $package->version,
            type: $package->type,
            dev: $package->dev,
            distUrl: $package->distUrl,
            distReference: $package->distReference,
            distShasum: $package->distShasum,
            replace: $package->replace,
            provide: $package->provide,
            autoload: $package->autoload,
            bin: $package->bin,
            installSource: InstallSourceType::fromComposer(Json::string($entry, 'installation-source')),
            installPath: $installPath === null
                ? null
                : Path::normalize(Path::join($vendorComposerPath, $installPath)),
        );
    }

    public function withDist(?string $url, ?string $reference, ?string $shasum): self
    {
        if ($url === null || $url === '') {
            return $this;
        }

        return new self(
            name: $this->name,
            version: $this->version,
            type: $this->type,
            dev: $this->dev,
            distUrl: $url,
            distReference: $reference === null || $reference === '' ? $this->distReference : $reference,
            distShasum: $shasum === '' ? null : $shasum,
            replace: $this->replace,
            provide: $this->provide,
            autoload: $this->autoload,
            bin: $this->bin,
            installSource: $this->installSource,
            installPath: $this->installPath,
        );
    }

    public function withDev(bool $dev): self
    {
        if ($this->dev === $dev) {
            return $this;
        }

        return new self(
            name: $this->name,
            version: $this->version,
            type: $this->type,
            dev: $dev,
            distUrl: $this->distUrl,
            distReference: $this->distReference,
            distShasum: $this->distShasum,
            replace: $this->replace,
            provide: $this->provide,
            autoload: $this->autoload,
            bin: $this->bin,
            installSource: $this->installSource,
            installPath: $this->installPath,
        );
    }

    public function installsTree(): bool
    {
        return $this->type !== self::METAPACKAGE;
    }

    /**
     * @return array<int, string>
     */
    public function aliases(): array
    {
        return array_values(array_unique([
            ...array_keys($this->replace),
            ...array_keys($this->provide),
        ]));
    }

    /**
     * @return array<int, string>
     */
    public function runtimeRoots(): array
    {
        $roots = [];

        foreach ([...$this->autoloadPaths(), ...$this->bin] as $path) {
            $roots[] = Path::normalize($path);
        }

        return array_values(array_unique(array_filter($roots, static fn (string $root): bool => $root !== '')));
    }

    public function autoloadsPackageRoot(): bool
    {
        return array_any($this->autoloadPaths(), fn (string $path): bool => Path::normalize($path) === '');
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, string>
     */
    private static function constraints(array $entry, string $key): array
    {
        $constraints = [];

        foreach (Json::array($entry, $key) as $name => $constraint) {
            if (is_string($name) && is_string($constraint)) {
                $constraints[$name] = $constraint;
            }
        }

        return $constraints;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<int, string>
     */
    private static function strings(array $entry, string $key): array
    {
        return array_values(array_filter(Json::array($entry, $key), is_string(...)));
    }

    /**
     * @return array<int, string>
     */
    private function autoloadPaths(): array
    {
        $paths = [];

        foreach (['psr-4', 'psr-0'] as $standard) {
            foreach (Json::array($this->autoload, $standard) as $entry) {
                foreach ((array) $entry as $path) {
                    if (is_string($path)) {
                        $paths[] = $path;
                    }
                }
            }
        }

        foreach (['files', 'classmap'] as $standard) {
            foreach (Json::array($this->autoload, $standard) as $path) {
                if (is_string($path)) {
                    $paths[] = $path;
                }
            }
        }

        return $paths;
    }
}
