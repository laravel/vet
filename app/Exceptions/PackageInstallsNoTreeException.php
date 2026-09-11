<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class PackageInstallsNoTreeException extends RuntimeException implements VetException
{
    public static function named(string $name): self
    {
        return new self(sprintf(
            'The package [%s] is a metapackage. Composer writes no file into vendor/ for it, thus vet reads no bytes.',
            $name,
        ));
    }
}
