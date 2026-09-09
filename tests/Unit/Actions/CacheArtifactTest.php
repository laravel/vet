<?php

declare(strict_types=1);

use App\Actions\CacheArtifact;

function cacheRoot(): string
{
    $root = sys_get_temp_dir().'/vet-cache-'.bin2hex(random_bytes(6));

    putenv('VET_CACHE_DIR='.$root);

    return $root;
}

afterEach(function (): void {
    putenv('VET_CACHE_DIR');
});

it('holds a package name that traverses inside the cache root', function (): void {
    $root = cacheRoot();

    $path = CacheArtifact::default()->path('archives', '../../../../etc', '1.0.0-abcdef');

    expect($path)->toBe($root.'/archives/..-..-..-..-etc/1.0.0-abcdef');
});

it('holds a package version that traverses inside the cache root', function (): void {
    $root = cacheRoot();

    $path = CacheArtifact::default()->path('archives', 'acme/widget', 'dev-../../../../victim/x-abcdef');

    expect($path)->toBe($root.'/archives/acme-widget/dev-..-..-..-..-victim-x-abcdef');
});

it('names a segment that holds no readable character', function (): void {
    $root = cacheRoot();

    expect(CacheArtifact::default()->path('downloads', '..'))->toBe($root.'/downloads/unnamed');
});

it('keeps a package name and a version that traverse nowhere', function (): void {
    $root = cacheRoot();

    $path = CacheArtifact::default()->path('archives', 'acme/widget', '2.0.0-rc.1-abcdef');

    expect($path)->toBe($root.'/archives/acme-widget/2.0.0-rc.1-abcdef');
});
