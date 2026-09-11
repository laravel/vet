<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\FailureException;
use App\ValueObjects\Package;
use Illuminate\Support\Facades\File;

final readonly class FetchArchive
{
    private const string CHECKSUM_ALGORITHM = 'sha1';

    public function __construct(
        private RequestUrl $http,
        private CacheArtifact $cache,
    ) {}

    public static function default(): self
    {
        return new self(RequestUrl::default(), app(CacheArtifact::class));
    }

    public function handle(Package $package): string
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

        if ($this->cache->has($marker) && is_dir($directory)) {
            return $directory;
        }

        @unlink($marker);
        File::deleteDirectory($directory);

        $archive = $this->cache->forPackage('downloads', $package->name, $release.'.zip');

        if (! $this->cache->has($archive)) {
            $this->http->download($package->distUrl, $archive);
        }

        $this->assertMatchesChecksum($package, $archive);

        $staging = $directory.'.'.bin2hex(random_bytes(6)).'.tmp';

        try {
            ExtractZip::handle($archive, $staging);

            if (! rename($staging, $directory)) {
                throw new FailureException(sprintf('Could not move the extracted archive into [%s].', $directory));
            }

            file_put_contents($marker, $package->version."\n");
        } finally {
            File::deleteDirectory($staging);
        }

        return $directory;
    }

    private function assertMatchesChecksum(Package $package, string $archive): void
    {
        if ($package->distShasum === null || $package->distShasum === '') {
            return;
        }

        $digest = hash_file(self::CHECKSUM_ALGORITHM, $archive);

        if ($digest !== false && hash_equals(mb_strtolower($package->distShasum), $digest)) {
            return;
        }

        @unlink($archive);

        throw new FailureException(sprintf(
            'The archive that [%s] served for [%s@%s] hashes to [%s], and composer.lock records [%s]. Those are not the bytes that composer installs.',
            (string) $package->distUrl,
            $package->name,
            $package->version,
            $digest === false ? 'nothing' : $digest,
            $package->distShasum,
        ));
    }
}
