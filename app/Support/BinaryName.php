<?php

declare(strict_types=1);

namespace App\Support;

final readonly class BinaryName
{
    private const array WINDOWS_SUFFIXES = ['.cmd', '.bat', '.exe'];

    public static function of(string $binary): string
    {
        $name = basename(str_replace('\\', '/', $binary));

        foreach (self::WINDOWS_SUFFIXES as $suffix) {
            if (str_ends_with(strtolower($name), $suffix)) {
                return substr($name, 0, -strlen($suffix));
            }
        }

        return $name;
    }
}
