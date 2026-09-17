<?php

declare(strict_types=1);

namespace App\Support;

final readonly class ControlSafe
{
    private const string CONTROL_BYTES = '/\r(?!\n)|[\x01-\x08\x0b\x0c\x0e-\x1f\x7f]/';

    private const string INVISIBLE_CHARACTERS = '/[^\P{Cc}\x00\t\n\r]|[\p{Cf}\x{2028}\x{2029}]/u';

    private const string REPLACEMENT = '?';

    private const string INVALID_BYTE = "\u{FFFD}";

    public static function text(string $text): string
    {
        $readable = (string) preg_replace(self::CONTROL_BYTES, self::REPLACEMENT, self::decodable($text));

        return (string) preg_replace(self::INVISIBLE_CHARACTERS, self::REPLACEMENT, $readable);
    }

    private static function decodable(string $text): string
    {
        if (mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }

        return str_replace(self::INVALID_BYTE, self::REPLACEMENT, mb_scrub($text, 'UTF-8'));
    }
}
