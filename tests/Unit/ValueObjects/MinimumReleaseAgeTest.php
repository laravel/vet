<?php

declare(strict_types=1);

use App\Exceptions\FailureException;
use App\ValueObjects\MinimumReleaseAge;
use App\ValueObjects\ReleaseDate;

it('holds a release until it reaches the minimum age, in UTC', function (): void {
    $age = MinimumReleaseAge::from(7, null);
    $released = ReleaseDate::fromEntry(['time' => '2026-09-20T10:00:00+02:00']);

    expect($age->holdsUntil('acme/widget', $released, new DateTimeImmutable('2026-09-21T00:00:00+00:00'))?->format('Y-m-d H:i T'))
        ->toBe('2026-09-27 08:00 UTC')
        ->and($age->holdsUntil('acme/widget', $released, new DateTimeImmutable('2026-09-27T08:00:01+00:00')))
        ->toBeNull();
});

it('holds nothing when it has no days, names no date, or excludes the package', function (MinimumReleaseAge $age, ReleaseDate $released): void {
    expect($age->holdsUntil('acme/widget', $released, new DateTimeImmutable('2026-09-21T00:00:00+00:00')))->toBeNull();
})->with([
    'no days' => [MinimumReleaseAge::from(null, null), ReleaseDate::fromEntry(['time' => '2026-09-20T00:00:00+00:00'])],
    'zero days' => [MinimumReleaseAge::from(0, []), ReleaseDate::fromEntry(['time' => '2026-09-20T00:00:00+00:00'])],
    'no date' => [MinimumReleaseAge::from(7, []), ReleaseDate::fromEntry([])],
    'a blank date' => [MinimumReleaseAge::from(7, []), ReleaseDate::fromEntry(['time' => ' '])],
    'an unreadable date' => [MinimumReleaseAge::from(7, []), ReleaseDate::fromEntry(['time' => 'yesterday-ish'])],
    'an excluded package' => [MinimumReleaseAge::from(7, ['acme/*']), ReleaseDate::fromEntry(['time' => '2026-09-20T00:00:00+00:00'])],
]);

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
        ->toThrow(FailureException::class, 'The [minimum-release-age-exclude] entry needs a list of package names, such as [laravel/*].');
})->with([
    'a string' => ['acme/widget'],
    'an object' => [['acme' => 'acme/widget']],
    'a number in the list' => [[1]],
    'an empty name' => [['']],
]);

it('holds back no release of vet itself', function (): void {
    expect(MinimumReleaseAge::from(7, [])->holdsUntil(
        'laravel/vet',
        ReleaseDate::fromEntry(['time' => '2026-09-20T00:00:00+00:00']),
        new DateTimeImmutable('2026-09-21T00:00:00+00:00'),
    ))->toBeNull();
});
