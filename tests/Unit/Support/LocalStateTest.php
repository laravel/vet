<?php

declare(strict_types=1);

use App\Support\LocalState;

it('covers the state that a tool writes at the root of a package', function (string $path): void {
    expect(LocalState::covers($path))->toBeTrue();
})->with([
    '.temp',
    '.pest',
    '.phpunit.cache',
    '.phpunit.result.cache',
    '.php-cs-fixer.cache',
    '.idea',
    '.vscode',
]);

it('covers the file that a file browser writes in each directory', function (): void {
    expect(LocalState::covers('.DS_Store'))->toBeTrue()
        ->and(LocalState::covers('src/.DS_Store'))->toBeTrue()
        ->and(LocalState::covers('src'.DIRECTORY_SEPARATOR.'Thumbs.db'))->toBeTrue();
});

it('covers no path of the package below its root', function (): void {
    expect(LocalState::covers('src/.temp'))->toBeFalse()
        ->and(LocalState::covers('tests/.phpunit.result.cache'))->toBeFalse();
});

it('covers no path that a package ships', function (): void {
    expect(LocalState::covers('src/Widget.php'))->toBeFalse()
        ->and(LocalState::covers('.github/workflows/tests.yml'))->toBeFalse()
        ->and(LocalState::covers('.gitattributes'))->toBeFalse()
        ->and(LocalState::covers('composer.json'))->toBeFalse();
});
