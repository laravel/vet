<?php

declare(strict_types=1);

namespace App\Support;

final readonly class Invitation
{
    private function __construct(
        public string $command,
    ) {}

    public static function toReadThePlan(): self
    {
        return new self('vet preview -v');
    }

    public static function toReadTheInstalledTree(): self
    {
        return new self('vet audit -v');
    }
}
