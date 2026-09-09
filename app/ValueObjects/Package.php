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
     * @param  array<string, mixed>  $autoload
     * @param  array<int, string>  $bin
     */
    public function __construct(
        public string $name,
        public string $version,
        public string $type,
        public bool $dev,
        public ?string $distUrl,
        public ?string $distReference,
        public array $replace,
        public array $provide,
        public array $autoload,
        public array $bin,
        public ?InstallSourceType $installSource = null,
        public ?string $installPath = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data  a package entry from composer.lock
     */
    public static function fromLockEntry(array $data, bool $dev): self
    {
        $dist = Json::array($data, 'dist');

        return new self(
            name: Json::string($data, 'name') ?? '',
            version: Json::string($data, 'version') ?? '',
            type: Json::string($data, 'type') ?? 'library',
            dev: $dev,
            distUrl: Json::string($dist, 'url'),
            distReference: Json::string($dist, 'reference'),
            replace: self::constraints($data, 'replace'),
            provide: self::constraints($data, 'provide'),
            autoload: Json::array($data, 'autoload'),
            bin: self::strings($data, 'bin'),
        );
    }

    /**
     * @param  array<string, mixed>  $data  a package entry from installed.json
     * @param  string  $vendorComposerPath  the directory `install-path` is relative to
     */
    public static function fromInstalledEntry(array $data, bool $dev, string $vendorComposerPath): self
    {
        $base = self::fromLockEntry($data, $dev);

        $installPath = Json::string($data, 'install-path');

        return new self(
            name: $base->name,
            version: $base->version,
            type: $base->type,
            dev: $base->dev,
            distUrl: $base->distUrl,
            distReference: $base->distReference,
            replace: $base->replace,
            provide: $base->provide,
            autoload: $base->autoload,
            bin: $base->bin,
            installSource: InstallSourceType::fromComposer(Json::string($data, 'installation-source')),
            installPath: $installPath === null
                ? null
                : Path::normalize(Path::join($vendorComposerPath, $installPath)),
        );
    }

    public function withDist(?string $url, ?string $reference): self
    {
        if (($url === null || $url === '') && ($reference === null || $reference === '')) {
            return $this;
        }

        return new self(
            name: $this->name,
            version: $this->version,
            type: $this->type,
            dev: $this->dev,
            distUrl: $url === null || $url === '' ? $this->distUrl : $url,
            distReference: $reference === null || $reference === '' ? $this->distReference : $reference,
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

        foreach ($this->autoloadPaths() as $path) {
            $roots[] = trim($path, '/.');
        }

        foreach ($this->bin as $path) {
            $roots[] = trim($path, '/');
        }

        return array_values(array_unique(array_filter($roots, static fn (string $root): bool => $root !== '')));
    }

    public function autoloadsPackageRoot(): bool
    {
        foreach ($this->autoloadPaths() as $path) {
            if (trim($path, '/.') === '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private static function constraints(array $data, string $key): array
    {
        $result = [];

        foreach (Json::array($data, $key) as $name => $constraint) {
            if (is_string($name) && is_string($constraint)) {
                $result[$name] = $constraint;
            }
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    private static function strings(array $data, string $key): array
    {
        return array_values(array_filter(Json::array($data, $key), is_string(...)));
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
