<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\FailureException;
use App\ValueObjects\Package;

final readonly class FetchArchive
{
    public function __construct(
        private RequestUrl $http,
        private CacheArtifact $cache,
    ) {}

    public static function default(): self
    {
        return new self(RequestUrl::default(), CacheArtifact::default());
    }

    /**
     * @return string the directory containing the extracted package tree
     */
    public function handle(Package $package, bool $useCache = true): string
    {
        if ($package->distUrl === null || $package->distUrl === '') {
            throw new FailureException(sprintf(
                'The package [%s@%s] has no dist URL, so its bytes cannot be fetched.',
                $package->name,
                $package->version,
            ));
        }

        $key = mb_substr(hash('sha256', $package->distUrl.'|'.($package->distReference ?? '')), 0, 16);
        $release = $package->version.'-'.$key;
        $directory = $this->cache->forPackage('archives', $package->name, $release);

        $marker = $directory.'.complete';

        if ($useCache && is_file($marker) && is_dir($directory)) {
            return $directory;
        }

        @unlink($marker);
        $this->removeDirectory($directory);

        $archive = $this->cache->forPackage('downloads', $package->name, $release.'.zip');

        if (! $useCache || ! is_file($archive)) {
            $this->http->download($package->distUrl, $archive);
        }

        $staging = $directory.'.'.bin2hex(random_bytes(6)).'.tmp';

        try {
            ExtractZip::handle($archive, $staging);

            if (! rename($staging, $directory)) {
                throw new FailureException(sprintf('Could not move the extracted archive into [%s].', $directory));
            }

            file_put_contents($marker, $package->version."\n");
        } finally {
            if (is_dir($staging)) {
                $this->removeDirectory($staging);
            }
        }

        return $directory;
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $names = @scandir($directory);

        if ($names === false) {
            return;
        }

        foreach ($names as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $path = $directory.'/'.$name;

            if (is_dir($path) && ! is_link($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($directory);
    }
}
