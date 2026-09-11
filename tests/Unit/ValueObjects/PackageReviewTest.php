<?php

declare(strict_types=1);

use App\ValueObjects\PackageReview;

it('names the scope of a review that reads no delta', function (): void {
    expect(PackageReview::unreadable()->label())->toBe('not readable')
        ->and(PackageReview::ofWholePackage(3)->label())->toBe('whole package');
});
