<?php

declare(strict_types=1);

use App\Enums\InstallSourceType;
use App\ValueObjects\Delta;
use App\ValueObjects\TreeHash;

it('asks for a review of a delta that holds no change', function (): void {
    $delta = new Delta(
        package: 'acme/widget',
        from: '1.0.0',
        to: '2.0.0',
        fromHash: TreeHash::fromManifest('one'),
        toHash: TreeHash::fromManifest('one'),
        source: InstallSourceType::Dist,
        changes: [],
        manifestChange: null,
        firstInstall: false,
    );

    expect($delta->isEmpty())->toBeTrue()
        ->and($delta->needsNoReview())->toBeFalse()
        ->and($delta->isInertOnly())->toBeFalse();
});

it('reads a downgrade from the two versions', function (string $from, string $to, bool $firstInstall, bool $toIsLocalInstall, bool $downgrade): void {
    $delta = new Delta(
        package: 'acme/widget',
        from: $from,
        to: $to,
        fromHash: TreeHash::fromManifest('one'),
        toHash: TreeHash::fromManifest('two'),
        source: InstallSourceType::Dist,
        changes: [],
        manifestChange: null,
        firstInstall: $firstInstall,
        toIsLocalInstall: $toIsLocalInstall,
    );

    expect($delta->isDowngrade())->toBe($downgrade);
})->with([
    'upgrade' => ['1.0.0', '2.0.0', false, false, false],
    'downgrade' => ['2.0.0', '1.0.0', false, false, true],
    'downgrade with a v prefix' => ['v4.4.4', 'v4.4.3', false, false, true],
    'downgrade with one v prefix' => ['4.4.4', 'v4.4.3', false, false, true],
    'same version' => ['1.0.0', '1.0.0', false, false, false],
    'dev branch' => ['dev-main', 'dev-main', false, false, false],
    'first install' => ['', '1.0.0', true, false, false],
    'published to installed' => ['1.0.0', '1.0.0', false, true, false],
]);
