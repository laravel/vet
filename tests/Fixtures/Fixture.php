<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Support\Json;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

final readonly class Fixture
{
    private function __construct(
        public string $rootPath,
        public string $cachePath,
    ) {}

    public static function open(string $name): self
    {
        $source = __DIR__.'/'.$name;

        if (! is_dir($source.'/project')) {
            throw new RuntimeException(sprintf('The fixture [%s] holds no project directory.', $name));
        }

        $base = sys_get_temp_dir().'/vet-'.bin2hex(random_bytes(6));

        $fixture = new self($base.'/project', $base.'/cache');

        self::copy($source.'/project', $fixture->rootPath);

        putenv('VET_CACHE_DIR='.$fixture->cachePath);

        $fixture->seedMetadata($source.'/packagist');
        $fixture->seedReleases($source.'/releases');

        return $fixture;
    }

    public function path(string $relative): string
    {
        return $this->rootPath.'/'.$relative;
    }

    public function read(string $relative): string
    {
        $contents = file_get_contents($this->path($relative));

        if ($contents === false) {
            throw new RuntimeException(sprintf('The fixture holds no file at [%s].', $relative));
        }

        return $contents;
    }

    public function agent(StubAgent $stub): string
    {
        return $this->agentNamed('agent', $stub);
    }

    public function agentNamed(string $name, StubAgent $stub): string
    {
        $executable = $stub->install(dirname($this->rootPath), $name);

        putenv('VET_AGENT_BINARY='.$executable);

        return $executable;
    }

    public function remove(): void
    {
        putenv('VET_CACHE_DIR');
        putenv('VET_AGENT_BINARY');

        $this->delete(dirname($this->rootPath));
    }

    private static function copy(string $source, string $destination): void
    {
        foreach (self::entries($source) as $entry) {
            $target = $destination.mb_substr($entry->getPathname(), mb_strlen($source));

            if ($entry->isDir()) {
                if (! is_dir($target)) {
                    mkdir($target, 0o777, true);
                }

                continue;
            }

            if (! is_dir(dirname($target))) {
                mkdir(dirname($target), 0o777, true);
            }

            copy($entry->getPathname(), $target);
        }
    }

    /**
     * @return iterable<SplFileInfo>
     */
    private static function entries(string $path, bool $childFirst = false): iterable
    {
        /** @var iterable<SplFileInfo> $entries */
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            $childFirst ? RecursiveIteratorIterator::CHILD_FIRST : RecursiveIteratorIterator::SELF_FIRST,
        );

        return $entries;
    }

    private function delete(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach (self::entries($path, childFirst: true) as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }

        rmdir($path);
    }

    private function seedMetadata(string $source): void
    {
        if (! is_dir($source)) {
            return;
        }

        foreach (glob($source.'/*.json') ?: [] as $document) {
            $package = array_key_first(Json::array(
                Json::readFile($document, 'the metadata of the fixture'),
                'packages',
            ));

            if (! is_string($package)) {
                continue;
            }

            $target = $this->metadataPath($package);

            if (! is_dir(dirname($target))) {
                mkdir(dirname($target), 0o777, true);
            }

            copy($document, $target);
        }
    }

    private function seedReleases(string $source): void
    {
        if (! is_dir($source)) {
            return;
        }

        foreach (glob($source.'/*/*/*', GLOB_ONLYDIR) ?: [] as $release) {
            $version = basename($release);
            $package = basename(dirname($release, 2)).'/'.basename(dirname($release));

            $directory = $this->archivePath($package, $version);

            self::copy($release, $directory);

            file_put_contents($directory.'.complete', $version."\n");
        }
    }

    private function metadataPath(string $package): string
    {
        return $this->cachePath.'/metadata/'.$package.'/index.json';
    }

    private function archivePath(string $package, string $version): string
    {
        $metadata = Json::readFile($this->metadataPath($package), 'the metadata of the fixture');

        $packages = Json::array($metadata, 'packages');
        $releases = is_array($packages[$package] ?? null) ? $packages[$package] : [];

        foreach ($releases as $release) {
            if (! is_array($release) || ($release['version'] ?? null) !== $version) {
                continue;
            }

            $dist = Json::array($release, 'dist');
            $key = mb_substr(hash(
                'sha256',
                (Json::string($dist, 'url') ?? '').'|'.(Json::string($dist, 'reference') ?? ''),
            ), 0, 16);

            return sprintf('%s/archives/%s/%s-%s', $this->cachePath, $package, $version, $key);
        }

        throw new RuntimeException(sprintf('The metadata of the fixture holds no version [%s] of [%s].', $version, $package));
    }
}
