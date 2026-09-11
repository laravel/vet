<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class PackageNotInstalledException extends RuntimeException implements VetException
{
    public static function named(string $name): self
    {
        return new self(sprintf('The package [%s] is not present in the installed package list.', $name));
    }
}
