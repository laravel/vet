<?php

declare(strict_types=1);

use App\Actions\ClassifyPath;
use App\Enums\BucketType;
use App\ValueObjects\Package;

function packageAutoloading(string $path): Package
{
    return Package::fromLockEntry([
        'name' => 'acme/widget',
        'version' => '1.0.0',
        'autoload' => ['psr-4' => ['Acme\\Widget\\' => $path]],
        'bin' => ['bin/widget'],
    ], false);
}

it('reads an autoload path that traverses as the directory that it lands in', function (): void {
    expect(packageAutoloading('src/../src/')->runtimeRoots())->toBe(['src', 'bin/widget']);
});

it('reads a file of an autoload path that traverses as runtime source', function (): void {
    $bucket = ClassifyPath::forPackages(packageAutoloading('src/../src/'))->handle('src/Widget.php');

    expect($bucket)->toBe(BucketType::RuntimeSource);
});

it('reads an autoload path that holds two slashes as one root', function (): void {
    expect(packageAutoloading('src//')->runtimeRoots())->toBe(['src', 'bin/widget']);
});

it('reads a package that autoloads a path that lands on its own root', function (): void {
    expect(packageAutoloading('src/..')->autoloadsPackageRoot())->toBeTrue()
        ->and(packageAutoloading('./')->autoloadsPackageRoot())->toBeTrue()
        ->and(packageAutoloading('src/')->autoloadsPackageRoot())->toBeFalse();
});

it('carries the checksum that the lock file records', function (): void {
    $package = Package::fromLockEntry([
        'name' => 'acme/widget',
        'version' => '1.0.0',
        'dist' => ['url' => 'https://example.test/widget.zip', 'reference' => 'aaaa1111', 'shasum' => 'bbbb2222'],
    ], false);

    expect($package->distShasum)->toBe('bbbb2222')
        ->and($package->withDist('https://example.test/other.zip', 'cccc3333', 'dddd4444')->distShasum)->toBe('dddd4444')
        ->and($package->withDist(null, null, null)->distShasum)->toBe('bbbb2222');
});

it('keeps the reference of the lock file when composer gives an empty reference', function (): void {
    $package = Package::fromLockEntry([
        'name' => 'acme/widget',
        'version' => '1.0.0',
        'dist' => ['url' => 'https://example.test/widget.zip', 'reference' => 'aaaa1111', 'shasum' => 'bbbb2222'],
    ], false);

    $moved = $package->withDist('https://example.test/other.zip', '', '');

    expect($moved->distUrl)->toBe('https://example.test/other.zip')
        ->and($moved->distReference)->toBe('aaaa1111')
        ->and($moved->distShasum)->toBeNull();
});

it('moves a package into the dev section and keeps the rest of it', function (): void {
    $package = packageAutoloading('src/');
    $dev = $package->withDev(true);

    expect($package->withDev(false))->toBe($package)
        ->and($dev->dev)->toBeTrue()
        ->and($dev->name)->toBe('acme/widget')
        ->and($dev->runtimeRoots())->toBe(['src', 'bin/widget']);
});
