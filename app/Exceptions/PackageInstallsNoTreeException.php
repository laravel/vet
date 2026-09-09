<?php

declare(strict_types=1);

namespace App\Exceptions;

final class PackageInstallsNoTreeException extends VetException
{
    public static function named(string $name): self
    {
        return new self(sprintf(
            'The package [%s] is a metapackage. Composer writes no file into vendor/ for it, thus vet reads no bytes.',
            $name,
        ));
    }
}
