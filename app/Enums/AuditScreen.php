<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Invitation;

enum AuditScreen: string
{
    case Installed = 'installed';

    case Planned = 'planned';

    public function invitation(): Invitation
    {
        return match ($this) {
            self::Installed => Invitation::toReadTheInstalledTree(),
            self::Planned => Invitation::toReadThePlan(),
        };
    }

    public function command(): string
    {
        return match ($this) {
            self::Installed => 'vet audit',
            self::Planned => 'vet preview',
        };
    }

    public function allCovered(int $total): string
    {
        return match ($this) {
            self::Installed => sprintf('All [%d] packages are covered.', $total),
            self::Planned => $total === 0
                ? 'The next [composer update] changes nothing in vendor/.'
                : sprintf('The next [composer update] changes [%d] package(s), and you trust every one.', $total),
        };
    }

    public function nextStep(): string
    {
        return match ($this) {
            self::Installed => 'Record them with [vet trust].',
            self::Planned => 'Run [composer update], then record them with [vet trust].',
        };
    }

    public function pendingNotice(): ?string
    {
        return match ($this) {
            self::Installed => 'composer holds those bytes out of vendor/ until you record them. Then run [composer install].',
            self::Planned => null,
        };
    }
}
