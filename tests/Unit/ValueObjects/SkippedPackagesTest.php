<?php

declare(strict_types=1);

use App\Exceptions\FailureException;
use App\ValueObjects\SkippedPackages;

it('matches a package name and a pattern', function (): void {
    $skipped = SkippedPackages::from(['laravel/*', 'acme/widget']);

    expect($skipped->matches('laravel/framework'))->toBeTrue()
        ->and($skipped->matches('acme/widget'))->toBeTrue()
        ->and($skipped->matches('acme/other'))->toBeFalse()
        ->and(SkippedPackages::from(null)->matches('laravel/framework'))->toBeFalse();
});

it('keeps one copy of a pattern', function (): void {
    $skipped = SkippedPackages::from(['laravel/*'])->with(['acme/widget', 'laravel/*']);

    expect($skipped->patterns())->toBe(['acme/widget', 'laravel/*']);
});

it('rejects a skip section that is not a list of names', function (): void {
    expect(fn (): SkippedPackages => SkippedPackages::from('laravel/*'))
        ->toThrow(FailureException::class, 'The [skip] entry needs a list of package names, such as [laravel/*].');
});
