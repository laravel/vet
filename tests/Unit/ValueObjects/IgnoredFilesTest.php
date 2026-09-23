<?php

declare(strict_types=1);

use App\Exceptions\FailureException;
use App\ValueObjects\IgnoredFiles;

it('gives the paths that it ignores for each package', function (): void {
    $ignored = IgnoredFiles::fromArray([
        'laravel/framework' => ['/config/cache.php', 'config/queue.php'],
    ]);

    expect($ignored->of('laravel/framework'))->toBe(['config/cache.php', 'config/queue.php'])
        ->and($ignored->of('acme/widget'))->toBe([]);
});

it('refuses an entry that is not a list of paths', function (mixed $entries): void {
    expect(fn (): IgnoredFiles => IgnoredFiles::fromArray(['laravel/framework' => $entries]))
        ->toThrow(FailureException::class, 'The [ignore] entry for [laravel/framework] needs a list of paths.');
})->with([
    'a string' => ['config/cache.php'],
    'an object' => [['file' => 'config/cache.php']],
    'a number in the list' => [[1]],
    'an empty path' => [['/']],
]);

it('refuses an entry that names no package', function (): void {
    expect(fn (): IgnoredFiles => IgnoredFiles::fromArray([['config/cache.php']]))
        ->toThrow(FailureException::class, 'The [ignore] entry for [0] needs a list of paths.');
});
