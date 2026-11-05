<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class LocalPackageException extends RuntimeException implements VetException
{
    public static function named(string $name): self
    {
        return new self(sprintf(
            'The package [%s] comes from a local path repository. Composer takes it from your disk, thus vet reads no bytes.',
            $name,
        ));
    }
}
