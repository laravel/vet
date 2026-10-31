<?php

declare(strict_types=1);

use App\Exceptions\FailureException;
use App\ValueObjects\MinimumReleaseAge;
use App\ValueObjects\Package;

function releasedPackage(string $name, string $version, ?string $time): Package
{
    return Package::fromLockEntry(['name' => $name, 'version' => $version, 'time' => $time], false);
}

it('holds a release until it reaches the minimum age, in UTC', function (): void {
    $age = MinimumReleaseAge::from(7, null);
    $package = releasedPackage('acme/widget', '1.0.0', '2026-09-20T10:00:00+02:00');

    expect($age->holdsUntil($package, new DateTimeImmutable('2026-09-21T00:00:00+00:00'))?->format('Y-m-d H:i T'))
        ->toBe('2026-09-27 08:00 UTC')
        ->and($age->holdsUntil($package, new DateTimeImmutable('2026-09-27T08:00:01+00:00')))
        ->toBeNull();
});

it('holds nothing when it has no days, names no date, or excludes the package', function (MinimumReleaseAge $age, Package $package): void {
    expect($age->holdsUntil($package, new DateTimeImmutable('2026-09-21T00:00:00+00:00')))->toBeNull();
})->with([
    'no days' => [MinimumReleaseAge::from(null, null), releasedPackage('acme/widget', '1.0.0', '2026-09-20T00:00:00+00:00')],
    'zero days' => [MinimumReleaseAge::from(0, []), releasedPackage('acme/widget', '1.0.0', '2026-09-20T00:00:00+00:00')],
    'no date' => [MinimumReleaseAge::from(7, []), releasedPackage('acme/widget', '1.0.0', null)],
    'a blank date' => [MinimumReleaseAge::from(7, []), releasedPackage('acme/widget', '1.0.0', ' ')],
    'an unreadable date' => [MinimumReleaseAge::from(7, []), releasedPackage('acme/widget', '1.0.0', 'yesterday-ish')],
    'an excluded package' => [MinimumReleaseAge::from(7, ['acme/*']), releasedPackage('acme/widget', '1.0.0', '2026-09-20T00:00:00+00:00')],
]);

it('holds a recent release unless the exclude list names its version', function (): void {
    $age = MinimumReleaseAge::from(7, ['acme/widget:1.2.0']);
    $now = new DateTimeImmutable('2026-09-21T00:00:00+00:00');

    expect($age->holdsUntil(releasedPackage('acme/widget', '1.2.0', '2026-09-20T00:00:00+00:00'), $now))->toBeNull()
        ->and($age->holdsUntil(releasedPackage('acme/widget', '1.2.1', '2026-09-20T00:00:00+00:00'), $now))->toBeInstanceOf(DateTimeImmutable::class);
});

it('refuses a number of days that is not a whole number of zero or more', function (mixed $days): void {
    expect(fn (): MinimumReleaseAge => MinimumReleaseAge::from($days, null))
        ->toThrow(FailureException::class, 'The [minimum-release-age] entry needs a whole number of days, such as [7].');
})->with([
    'a string' => ['7'],
    'a fraction' => [1.5],
    'a negative number' => [-1],
]);

it('refuses an exclude entry that is not a list of package names', function (mixed $excluded): void {
    expect(fn (): MinimumReleaseAge => MinimumReleaseAge::from(7, $excluded))
        ->toThrow(FailureException::class, 'The [minimum-release-age-exclude] entry needs a list of package names, such as [laravel/*], each with an optional version constraint, such as [laravel/framework:^13.0].');
})->with([
    'a string' => ['acme/widget'],
    'an object' => [['acme' => 'acme/widget']],
    'a number in the list' => [[1]],
    'an empty name' => [['']],
    'an unreadable version constraint' => [['acme/widget:not a version']],
]);

it('holds back no release of vet itself', function (): void {
    expect(MinimumReleaseAge::from(7, [])->holdsUntil(
        releasedPackage('laravel/vet', '1.0.0', '2026-09-20T00:00:00+00:00'),
        new DateTimeImmutable('2026-09-21T00:00:00+00:00'),
    ))->toBeNull();
});
