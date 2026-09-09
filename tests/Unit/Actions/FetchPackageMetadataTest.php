<?php

declare(strict_types=1);

use App\Actions\CacheArtifact;
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

function cachedPackagist(string $document, FakeHttp $http): FetchPackageMetadata
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

    $cache = CacheArtifact::default();

    $cache->put($cache->forPackage('metadata', 'acme/widget', 'index.json'), $document);

    return new FetchPackageMetadata(new RequestUrl('vet (tests)', [], $http->client), $cache);
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

it('reads packagist again when the user refuses the cache', function (): void {
    $http = new FakeHttp([new Response(200, [], packagistDocument(['2.0.0', '1.0.0']))]);

    $packagist = cachedPackagist(packagistDocument(['1.0.0']), $http);
    $packagist->refresh('acme/widget');

    expect(array_keys($packagist->versions('acme/widget')))->toBe(['2.0.0', '1.0.0'])
        ->and($http->requests)->toHaveCount(1);
});
