<?php

declare(strict_types=1);

namespace App\Support;

final readonly class ControlSafe
{
    private const string CONTROL_CHARACTERS = '/[\x01-\x08\x0b-\x1f\x7f]/';

    private const string INVISIBLE_CHARACTERS = '/[\x{00ad}\x{200b}-\x{200f}\x{2028}-\x{202e}\x{2060}-\x{2064}\x{2066}-\x{2069}\x{feff}]/u';

    public static function text(string $text): string
    {
        $readable = (string) preg_replace(self::CONTROL_CHARACTERS, '?', $text);

        return preg_replace(self::INVISIBLE_CHARACTERS, '?', $readable) ?? $readable;
    }
}
