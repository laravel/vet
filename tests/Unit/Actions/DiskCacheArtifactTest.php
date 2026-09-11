<?php

declare(strict_types=1);

use App\Actions\DiskCacheArtifact;
use App\Exceptions\FailureException;
use App\Support\Path;
use Illuminate\Support\Facades\File;
use Tests\Fixtures\Warnings;

function cacheRoot(): string
{
    $root = Path::normalize(sys_get_temp_dir().'/vet-cache-'.bin2hex(random_bytes(6)));

    putenv('VET_CACHE_DIR='.$root);

    return $root;
}

afterEach(function (): void {
    putenv('VET_CACHE_DIR');
});

it('holds a version that traverses inside the cache root', function (): void {
    $root = cacheRoot();

    $path = DiskCacheArtifact::default()->forPackage('archives', 'acme/widget', 'dev-../../../../victim/x-abcdef');

    expect($path)->toBe($root.'/archives/acme/widget/dev-..-..-..-..-victim-x-abcdef')
        ->and($path)->toStartWith($root.'/');
});

it('refuses a package name that traverses', function (): void {
    cacheRoot();

    expect(fn (): string => DiskCacheArtifact::default()->forPackage('archives', '../../../../etc', '1.0.0-abcdef'))
        ->toThrow(FailureException::class, 'is not a valid package name');
});

it('names a version that holds no readable character', function (): void {
    $root = cacheRoot();

    expect(DiskCacheArtifact::default()->forPackage('downloads', 'acme/widget', '..'))
        ->toBe($root.'/downloads/acme/widget/-');
});

it('keeps a package name and a version that traverse nowhere', function (): void {
    $root = cacheRoot();

    $path = DiskCacheArtifact::default()->forPackage('archives', 'acme/widget', '2.0.0-rc.1-abcdef');

    expect($path)->toBe($root.'/archives/acme/widget/2.0.0-rc.1-abcdef');
});

it('reads a cache file that is younger than its lifetime', function (): void {
    $root = cacheRoot();
    $cache = DiskCacheArtifact::default();
    $path = $cache->forPackage('metadata', 'acme/widget', 'index.json');

    try {
        $cache->put($path, '{"packages":{}}');

        expect($cache->has($path))->toBeTrue()
            ->and($cache->fresh($path, 60))->toBe('{"packages":{}}');
    } finally {
        File::deleteDirectory($root);
    }
});

it('reads no cache file that is older than its lifetime', function (): void {
    $root = cacheRoot();
    $cache = DiskCacheArtifact::default();
    $path = $cache->forPackage('metadata', 'acme/widget', 'index.json');

    try {
        $cache->put($path, '{"packages":{}}');
        touch($path, time() - 120);

        expect($cache->has($path))->toBeTrue()
            ->and($cache->fresh($path, 60))->toBeNull();
    } finally {
        File::deleteDirectory($root);
    }
});

it('reads no cache file that is empty or missing', function (): void {
    $root = cacheRoot();
    $cache = DiskCacheArtifact::default();
    $empty = $cache->forPackage('metadata', 'acme/widget', 'index.json');
    $missing = $cache->forPackage('metadata', 'acme/gadget', 'index.json');

    try {
        $cache->put($empty, '');

        expect($cache->fresh($empty, 60))->toBeNull()
            ->and($cache->fresh($missing, 60))->toBeNull()
            ->and($cache->has($missing))->toBeFalse();
    } finally {
        File::deleteDirectory($root);
    }
});

it('keeps the cache in the cache home of the user', function (): void {
    $path = static fn (): string => DiskCacheArtifact::default()->forPackage('metadata', 'acme/widget', 'index.json');

    $xdg = withEnvironment(['VET_CACHE_DIR' => null, 'XDG_CACHE_HOME' => '/cache-home', 'HOME' => '/home/user'], $path);
    $home = withEnvironment(['VET_CACHE_DIR' => null, 'XDG_CACHE_HOME' => null, 'HOME' => '/home/user'], $path);

    expect($xdg)->toBe('/cache-home/vet/metadata/acme/widget/index.json')
        ->and($home)->toBe('/home/user/.cache/vet/metadata/acme/widget/index.json');
});

it('keeps the cache in the temporary directory when the user holds no home', function (): void {
    $path = withEnvironment(
        ['VET_CACHE_DIR' => null, 'XDG_CACHE_HOME' => null, 'HOME' => null],
        static fn (): string => DiskCacheArtifact::default()->forPackage('metadata', 'acme/widget', 'index.json'),
    );

    expect($path)->toBe(Path::normalize(sys_get_temp_dir().'/vet/metadata/acme/widget/index.json'));
});

it('names the cache directory that it cannot create', function (): void {
    $root = cacheRoot();
    $cache = DiskCacheArtifact::default();
    $path = $cache->forPackage('metadata', 'acme/widget', 'index.json');

    File::ensureDirectoryExists($root.'/metadata/acme');
    file_put_contents($root.'/metadata/acme/widget', 'a file where a directory belongs');

    try {
        expect(static function () use ($cache, $path): void {
            Warnings::silenced(static function () use ($cache, $path): void {
                $cache->put($path, '{}');
            });
        })->toThrow(FailureException::class, sprintf('Could not create the cache directory [%s].', dirname($path)));
    } finally {
        File::deleteDirectory($root);
    }
});

it('names the cache file that it cannot write', function (): void {
    $root = cacheRoot();
    $cache = DiskCacheArtifact::default();
    $path = $cache->forPackage('metadata', 'acme/widget', 'index.json');

    File::ensureDirectoryExists($path);

    try {
        expect(static function () use ($cache, $path): void {
            $cache->put($path, '{}');
        })->toThrow(FailureException::class, sprintf('Could not write to the cache file [%s].', $path));
    } finally {
        File::deleteDirectory($root);
    }
});
