<?php

declare(strict_types=1);

namespace App\Support;

final readonly class ControlSafe
{
    private const string CONTROL_BYTES = '/[\x01-\x08\x0b-\x1f\x7f]/';

    private const string INVISIBLE_CHARACTERS = '/[^\P{Cc}\x00\t\n]|[\p{Cf}\x{2028}\x{2029}]/u';

    private const string REPLACEMENT = '?';

    public static function text(string $text): string
    {
        $readable = (string) preg_replace(self::CONTROL_BYTES, self::REPLACEMENT, $text);

        return preg_replace(self::INVISIBLE_CHARACTERS, self::REPLACEMENT, $readable) ?? $readable;
    }
}
