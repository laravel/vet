<?php

declare(strict_types=1);

use App\Actions\DiskCacheArtifact;
use App\Exceptions\FailureException;

function cacheRoot(): string
{
    $root = sys_get_temp_dir().'/vet-cache-'.bin2hex(random_bytes(6));

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
