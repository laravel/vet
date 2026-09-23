<?php

declare(strict_types=1);

use Laravel\Vet\Composer\ReleaseAge;

function releaseAgeOf(string $contents): ReleaseAge
{
    $path = sys_get_temp_dir().'/vet-release-age-'.bin2hex(random_bytes(6)).'.json';

    file_put_contents($path, $contents);

    try {
        return ReleaseAge::fromTrustFile($path);
    } finally {
        unlink($path);
    }
}

it('allows a release once it reaches the minimum age', function (): void {
    $age = releaseAgeOf('{"minimum-release-age":7}');
    $now = new DateTimeImmutable('2026-09-23T12:00:00+00:00');

    expect($age->holdsBack())->toBeTrue()
        ->and($age->days)->toBe(7)
        ->and($age->allows('acme/widget', new DateTimeImmutable('2026-09-16T12:00:01+00:00'), $now))->toBeFalse()
        ->and($age->allows('acme/widget', new DateTimeImmutable('2026-09-16T12:00:00+00:00'), $now))->toBeTrue();
});

it('allows a recent release of a package that the exclude list names', function (): void {
    $age = releaseAgeOf('{"minimum-release-age":7,"minimum-release-age-exclude":["laravel/*"]}');
    $now = new DateTimeImmutable('2026-09-23T12:00:00+00:00');

    expect($age->allows('laravel/framework', $now, $now))->toBeTrue()
        ->and($age->allows('acme/widget', $now, $now))->toBeFalse();
});

it('holds back nothing when the trust file asks for no age, or cannot say one', function (string $contents): void {
    $age = releaseAgeOf($contents);
    $now = new DateTimeImmutable('2026-09-23T12:00:00+00:00');

    expect($age->holdsBack())->toBeFalse()
        ->and($age->allows('acme/widget', $now, $now))->toBeTrue();
})->with([
    'no setting' => ['{}'],
    'zero days' => ['{"minimum-release-age":0}'],
    'a string' => ['{"minimum-release-age":"7"}'],
    'an exclude string' => ['{"minimum-release-age":7,"minimum-release-age-exclude":"laravel/*"}'],
    'an exclude object' => ['{"minimum-release-age":7,"minimum-release-age-exclude":{"a":"laravel/*"}}'],
    'an empty exclude name' => ['{"minimum-release-age":7,"minimum-release-age-exclude":[""]}'],
    'no json' => ['{'],
]);

it('holds back nothing when the project holds no trust file', function (): void {
    expect(ReleaseAge::fromTrustFile(sys_get_temp_dir().'/vet-no-such-file.json')->holdsBack())->toBeFalse();
});

it('allows a recent release of vet itself', function (): void {
    $now = new DateTimeImmutable('2026-09-23T12:00:00+00:00');

    expect(releaseAgeOf('{"minimum-release-age":7}')->allows('laravel/vet', $now, $now))->toBeTrue();
});
