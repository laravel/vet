<?php

declare(strict_types=1);

use App\Actions\CacheArtifact;
use App\Actions\ColdCacheArtifact;
use App\Actions\DiskCacheArtifact;
use App\Actions\FetchPackageMetadata;
use App\Actions\RequestUrl;
use App\Exceptions\FailureException;
use App\Support\Json;
use GuzzleHttp\Psr7\Response;
use Tests\Fixtures\FakeHttp;

/**
 * @param  array<int, string>  $versions
 */
function packagistDocument(array $versions): string
{
    return Json::encode([
        'packages' => [
            'acme/widget' => array_map(static fn (string $version): array => [
                'name' => 'acme/widget',
                'version' => $version,
                'type' => 'library',
                'dist' => [
                    'type' => 'zip',
                    'url' => 'https://packages.test/acme/widget/'.$version.'.zip',
                    'reference' => $version,
                ],
            ], $versions),
        ],
    ]);
}

function seededCache(string $document): CacheArtifact
{
    $directory = sys_get_temp_dir().'/vet-'.bin2hex(random_bytes(6));

    putenv('VET_CACHE_DIR='.$directory);

    register_shutdown_function(static function () use ($directory): void {
        $file = $directory.'/metadata/acme/widget/index.json';

        if (is_file($file)) {
            unlink($file);
        }

        foreach ([dirname($file), dirname($file, 2), dirname($file, 3), $directory] as $path) {
            if (is_dir($path)) {
                rmdir($path);
            }
        }
    });

    $cache = DiskCacheArtifact::default();

    $cache->put($cache->forPackage('metadata', 'acme/widget', 'index.json'), $document);

    return $cache;
}

function cachedPackagist(string $document, FakeHttp $http): FetchPackageMetadata
{
    return new FetchPackageMetadata(new RequestUrl('vet (tests)', [], $http->client), seededCache($document));
}

function coldPackagist(string $document, FakeHttp $http): FetchPackageMetadata
{
    return new FetchPackageMetadata(
        new RequestUrl('vet (tests)', [], $http->client),
        new ColdCacheArtifact(seededCache($document)),
    );
}

afterEach(function (): void {
    putenv('VET_CACHE_DIR');
});

it('reads packagist again when the cached document holds no such version', function (): void {
    $http = new FakeHttp([new Response(200, [], packagistDocument(['2.0.0', '1.0.0']))]);

    $packagist = cachedPackagist(packagistDocument(['1.0.0']), $http);

    expect($packagist->version('acme/widget', '2.0.0')->version)->toBe('2.0.0')
        ->and($http->urls())->toBe(['https://repo.packagist.org/p2/acme/widget.json']);
});

it('reads no packagist when the cached document holds the version', function (): void {
    $http = new FakeHttp([]);

    $packagist = cachedPackagist(packagistDocument(['2.0.0', '1.0.0']), $http);

    expect($packagist->version('acme/widget', '1.0.0')->version)->toBe('1.0.0')
        ->and($http->requests)->toBeEmpty();
});

it('names the versions that packagist knows when it knows no such version', function (): void {
    $http = new FakeHttp([new Response(404, [], 'Not Found')]);

    $packagist = cachedPackagist(packagistDocument(['2.0.0', '1.0.0']), $http);

    expect(static fn (): mixed => $packagist->version('acme/widget', '9.9.9'))
        ->toThrow(FailureException::class, 'Packagist has no version [9.9.9] of [acme/widget]');
});

it('reads the release before a version that php stores as a number', function (): void {
    $packagist = cachedPackagist(packagistDocument(['3', '2', '1']), new FakeHttp([]));

    expect($packagist->previousVersion('acme/widget', '3'))->toBe('2');
});

it('reads the stability of a version as composer reads it', function (string $version, bool $stable): void {
    expect(FetchPackageMetadata::isStable($version))->toBe($stable);
})->with([
    ['1.0.0', true],
    ['v2.1.3', true],
    ['1.0.0-patch1', true],
    ['1.0.0-pl1', true],
    ['1.0.0-p1', true],
    ['dev-main', false],
    ['1.0.x-dev', false],
    ['1.0.0-RC1', false],
    ['1.0.0-beta.2', false],
    ['1.0.0-b1', false],
    ['v2.0.0-alpha', false],
    ['1.0.0-a1', false],
]);

it('reads packagist again when the user refuses the cache', function (): void {
    $http = new FakeHttp([new Response(200, [], packagistDocument(['2.0.0', '1.0.0']))]);

    $packagist = coldPackagist(packagistDocument(['1.0.0']), $http);

    expect(array_keys($packagist->versions('acme/widget')))->toBe(['2.0.0', '1.0.0'])
        ->and($http->requests)->toHaveCount(1);
});

it('reads the dist of each version of a minified document', function (): void {
    $document = Json::encode([
        'minified' => 'composer/2.0',
        'packages' => [
            'acme/widget' => [
                ['name' => 'acme/widget', 'version' => '2.0.0', 'type' => 'library', 'dist' => ['type' => 'zip', 'url' => 'https://packages.test/acme/widget/2.0.0.zip', 'reference' => 'bbbb2222']],
                ['version' => '1.0.0', 'dist' => ['type' => 'zip', 'url' => 'https://packages.test/acme/widget/1.0.0.zip', 'reference' => 'aaaa1111']],
            ],
        ],
    ]);

    $package = cachedPackagist($document, new FakeHttp([]))->version('acme/widget', '1.0.0');

    expect($package->name)->toBe('acme/widget')
        ->and($package->type)->toBe('library')
        ->and($package->distUrl)->toBe('https://packages.test/acme/widget/1.0.0.zip')
        ->and($package->distReference)->toBe('aaaa1111');
});

it('skips a pre-release when it reads the release before a stable version', function (): void {
    $packagist = cachedPackagist(packagistDocument(['2.0.0', '2.0.0-RC2', '2.0.0-RC1', '1.0.0']), new FakeHttp([]));

    expect($packagist->previousVersion('acme/widget', '2.0.0'))->toBe('1.0.0')
        ->and($packagist->previousVersion('acme/widget', 'v2.0.0'))->toBe('1.0.0')
        ->and($packagist->previousVersion('acme/widget', '2.0.0-RC2'))->toBe('2.0.0-RC1')
        ->and($packagist->previousVersion('acme/widget', '1.0.0'))->toBeNull()
        ->and($packagist->previousVersion('acme/widget', '9.9.9'))->toBeNull();
});

it('refuses a package name that is not vendor/name before it reads packagist', function (string $package): void {
    $http = new FakeHttp([]);

    expect(fn (): array => coldPackagist(packagistDocument(['1.0.0']), $http)->versions($package))
        ->toThrow(FailureException::class, sprintf('[%s] is not a valid package name', $package))
        ->and($http->requests)->toBeEmpty();
})->with([
    '../../etc/passwd',
    'acme/widget?page=2',
    'acme',
    'acme/widget/extra',
]);

it('refuses metadata that holds no version of the package', function (string $body, string $message): void {
    $http = new FakeHttp([FakeHttp::body($body)]);

    expect(fn (): array => coldPackagist(packagistDocument(['1.0.0']), $http)->versions('acme/widget'))
        ->toThrow(FailureException::class, $message);
})->with([
    'no json' => ['<html>maintenance</html>', 'Packagist returned unreadable metadata for [acme/widget].'],
    'no package' => ['{"packages":{}}', 'Packagist knows no released versions of [acme/widget].'],
    'no version' => ['{"packages":{"acme/widget":[{"name":"acme/widget"}]}}', 'Packagist returned no usable version entries for [acme/widget].'],
]);

it('skips an entry of packagist that is not a version', function (): void {
    $packagist = cachedPackagist('{"packages":{"acme/widget":["not a version",{"name":"acme/widget","version":"1.0.0"}]}}', new FakeHttp([]));

    expect(array_keys($packagist->versions('acme/widget')))->toBe(['1.0.0']);
});
