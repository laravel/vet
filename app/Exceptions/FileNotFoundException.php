<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class FileNotFoundException extends RuntimeException implements VetException
{
    public static function at(string $path, string $what): self
    {
        return new self(sprintf('Could not read [%s]: [%s] does not exist or is not readable.', $what, $path));
    }
}
