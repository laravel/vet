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
