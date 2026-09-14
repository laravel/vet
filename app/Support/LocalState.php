<?php

declare(strict_types=1);

namespace App\Support;

final class LocalState
{
    private const array NAMES_IN_EACH_DIRECTORY = [
        '.ds_store',
        'thumbs.db',
    ];

    private const array NAMES_AT_THE_ROOT = [
        '.temp',
        '.pest',
        '.phpunit.cache',
        '.phpunit.result.cache',
        '.php-cs-fixer.cache',
        '.idea',
        '.vscode',
    ];

    public static function covers(string $path): bool
    {
        $relative = Path::toRelativeForm($path);
        $name = mb_strtolower(basename($relative));

        if (in_array($name, self::NAMES_IN_EACH_DIRECTORY, true)) {
            return true;
        }

        return ! str_contains($relative, '/') && in_array($name, self::NAMES_AT_THE_ROOT, true);
    }
}
