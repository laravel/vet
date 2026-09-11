<?php

declare(strict_types=1);

use App\Actions\ColdCacheArtifact;
use App\Actions\DiskCacheArtifact;
use App\Actions\FetchArchive;
use App\Actions\RequestUrl;
use App\Exceptions\FailureException;
use App\Support\ProgressDots;
use App\ValueObjects\Package;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Fixtures\CraftedArchive;
use Tests\Fixtures\FakeHttp;
use Tests\Fixtures\Warnings;

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

    return new FetchArchive(new RequestUrl('vet-test', [], $http->client), DiskCacheArtifact::default(), silentDots());
}

afterEach(function (): void {
    putenv('VET_CACHE_DIR');
});

it('refuses an archive whose bytes the lock file does not record', function (): void {
    $served = (string) file_get_contents(widgetArchive());
    $package = widgetPackage(sha1('the bytes that composer installs'));

    expect(fn (): string => fetcherServing($served)->handle($package))
        ->toThrow(FailureException::class, 'Those are not the bytes that composer installs.');
});

it('reads an archive whose bytes the lock file records', function (): void {
    $served = (string) file_get_contents(widgetArchive());
    $package = widgetPackage(sha1($served));

    $directory = fetcherServing($served)->handle($package);

    expect(file_get_contents($directory.'/src/Widget.php'))->toBe("<?php // the widget\n");
});

it('reads an archive that the lock file records no checksum for', function (): void {
    $served = (string) file_get_contents(widgetArchive());
    $package = widgetPackage('');

    $directory = fetcherServing($served)->handle($package);

    expect(file_get_contents($directory.'/src/Widget.php'))->toBe("<?php // the widget\n");
});

it('reads the bytes again when the user refuses the cache', function (): void {
    $served = (string) file_get_contents(widgetArchive());
    $package = widgetPackage(sha1($served));

    putenv('VET_CACHE_DIR='.sys_get_temp_dir().'/vet-fetch-'.bin2hex(random_bytes(6)));

    $http = new FakeHttp([FakeHttp::body($served), FakeHttp::body($served)]);

    $fetcher = new FetchArchive(
        new RequestUrl('vet-test', [], $http->client),
        new ColdCacheArtifact(DiskCacheArtifact::default()),
        silentDots(),
    );

    $fetcher->handle($package);
    $fetcher->handle($package);

    expect($http->requests)->toHaveCount(2);
});

it('names the directory that it cannot move the extracted archive into', function (): void {
    $served = (string) file_get_contents(widgetArchive());
    $package = widgetPackage(sha1($served));
    $fetcher = fetcherServing($served);

    $directory = DiskCacheArtifact::default()->forPackage(
        'archives',
        'acme/widget',
        '1.0.0-'.mb_substr(hash('sha256', 'https://example.test/acme-widget-1.0.0.zip|aaaa1111'), 0, 16),
    );

    File::ensureDirectoryExists(dirname($directory));
    file_put_contents($directory, 'a read-only file where the tree belongs');
    chmod($directory, 0o444);

    try {
        expect(static function () use ($fetcher, $package): void {
            Warnings::silenced(static fn (): string => $fetcher->handle($package));
        })->toThrow(FailureException::class, sprintf('Could not move the extracted archive into [%s].', $directory));
    } finally {
        chmod($directory, 0o644);
        File::deleteDirectory((string) getenv('VET_CACHE_DIR'));
    }
});

it('marks each download with a dot, and no read of the cache', function (): void {
    $served = (string) file_get_contents(widgetArchive());
    $package = widgetPackage(sha1($served));
    $buffer = new BufferedOutput;

    putenv('VET_CACHE_DIR='.sys_get_temp_dir().'/vet-fetch-'.bin2hex(random_bytes(6)));

    $fetcher = new FetchArchive(
        new RequestUrl('vet-test', [], new FakeHttp([FakeHttp::body($served)])->client),
        DiskCacheArtifact::default(),
        new ProgressDots(new OutputStyle(new ArrayInput([]), $buffer)),
    );

    $fetcher->handle($package);
    $fetcher->handle($package);

    expect($buffer->fetch())->toBe('  .');
});
