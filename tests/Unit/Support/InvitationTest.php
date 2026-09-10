<?php

declare(strict_types=1);

use App\Support\Invitation;

it('invites the preview command to read a plan', function (): void {
    expect(Invitation::toReadThePlan()->command)->toBe('vet preview -v');
});

it('invites the audit command to read the installed tree', function (): void {
    expect(Invitation::toReadTheInstalledTree()->command)->toBe('vet audit -v');
});
