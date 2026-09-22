<?php

declare(strict_types=1);

namespace App\Support;

final readonly class PhpSource
{
    private const string EXTENSION = 'php';

    private const array STRING_TOKENS = [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE];

    public static function holdsNoSource(string $path, string $contents): bool
    {
        if (! str_contains($contents, "\0")) {
            return false;
        }

        if (mb_strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== self::EXTENSION) {
            return true;
        }

        return self::holdsNulOutsideString($contents);
    }

    private static function holdsNulOutsideString(string $contents): bool
    {
        foreach (token_get_all($contents) as $token) {
            $text = is_array($token) ? $token[1] : $token;

            if (! str_contains($text, "\0")) {
                continue;
            }

            if (! is_array($token) || ! in_array($token[0], self::STRING_TOKENS, true)) {
                return true;
            }
        }

        return false;
    }
}
