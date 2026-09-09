<?php

declare(strict_types=1);

use App\Composer\Gate;
use App\Support\Invitation;

afterEach(function (): void {
    putenv(Gate::ENVIRONMENT);
});

it('invites the command of vet outside composer', function (): void {
    expect(Invitation::verbose())->toBe('-v')
        ->and(Invitation::verbose('vet audit -v'))->toBe('vet audit -v');
});

it('invites the verbose flag of composer inside composer', function (): void {
    putenv(Gate::ENVIRONMENT.'=1');

    expect(Invitation::verbose())->toBe('composer update -v')
        ->and(Invitation::verbose('vet audit -v'))->toBe('composer update -v');
});
