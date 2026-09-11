<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class InvalidJsonException extends RuntimeException implements VetException
{
    public static function at(string $path, string $reason): self
    {
        return new self(sprintf('The file [%s] does not contain valid JSON: %s', $path, $reason));
    }

    public static function structure(string $path, string $expectation): self
    {
        return new self(sprintf('Unexpected structure in [%s]: %s', $path, $expectation));
    }
}
