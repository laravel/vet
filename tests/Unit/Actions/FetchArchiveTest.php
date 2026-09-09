<?php

declare(strict_types=1);

use App\Actions\CacheArtifact;
use App\Actions\FetchArchive;
use App\Actions\RequestUrl;
use App\Exceptions\FailureException;
use App\ValueObjects\Package;
use Tests\Fixtures\CraftedArchive;
use Tests\Fixtures\FakeHttp;

function widgetArchive(): string
{
    $path = sys_get_temp_dir().'/vet-served-'.bin2hex(random_bytes(6)).'.zip';

    CraftedArchive::write($path, [
        ['name' => 'widget/composer.json', 'contents' => "{}\n"],
        ['name' => 'widget/src/Widget.php', 'contents' => "<?php // the widget\n"],
    ]);

    register_shutdown_function(static function () use ($path): void {
        @unlink($path);
    });

    return $path;
}

function widgetPackage(string $shasum): Package
{
    return Package::fromLockEntry([
        'name' => 'acme/widget',
        'version' => '1.0.0',
        'dist' => [
            'url' => 'https://example.test/acme-widget-1.0.0.zip',
            'reference' => 'aaaa1111',
            'shasum' => $shasum,
        ],
    ], false);
}

function fetcherServing(string $bytes): FetchArchive
{
    putenv('VET_CACHE_DIR='.sys_get_temp_dir().'/vet-fetch-'.bin2hex(random_bytes(6)));

    $http = new FakeHttp([FakeHttp::body($bytes)]);

    return new FetchArchive(new RequestUrl('vet-test', [], $http->client), CacheArtifact::default());
}

afterEach(function (): void {
    putenv('VET_CACHE_DIR');
});

it('refuses an archive whose bytes the lock file does not record', function (): void {
    $served = (string) file_get_contents(widgetArchive());
    $package = widgetPackage(sha1('the bytes that composer installs'));

    expect(fn (): string => fetcherServing($served)->handle($package, true))
        ->toThrow(FailureException::class, 'Those are not the bytes that composer installs.');
});

it('reads an archive whose bytes the lock file records', function (): void {
    $served = (string) file_get_contents(widgetArchive());
    $package = widgetPackage(sha1($served));

    $directory = fetcherServing($served)->handle($package, true);

    expect(file_get_contents($directory.'/src/Widget.php'))->toBe("<?php // the widget\n");
});

it('reads an archive that the lock file records no checksum for', function (): void {
    $served = (string) file_get_contents(widgetArchive());
    $package = widgetPackage('');

    $directory = fetcherServing($served)->handle($package, true);

    expect(file_get_contents($directory.'/src/Widget.php'))->toBe("<?php // the widget\n");
});
