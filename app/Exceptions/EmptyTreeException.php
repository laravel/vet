<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class EmptyTreeException extends RuntimeException implements VetException
{
    public static function at(string $directory): self
    {
        return new self(sprintf('The directory [%s] contains no files; refusing to hash an empty tree.', $directory));
    }

    public static function missing(string $directory): self
    {
        return new self(sprintf('The directory [%s] does not exist.', $directory));
    }
}
