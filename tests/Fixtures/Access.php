<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Symfony\Component\Process\Process;

final class Access
{
    private const string EVERYONE = '*S-1-1-0';

    public static function denyRead(string $path): void
    {
        if (self::windows()) {
            self::icacls($path, '/deny', self::EVERYONE.':(RD)');

            return;
        }

        chmod($path, 0o000);
    }

    public static function denyWrite(string $path): void
    {
        if (self::windows()) {
            self::icacls($path, '/deny', self::EVERYONE.':(WD,AD)');

            return;
        }

        chmod($path, 0o555);
    }

    public static function restore(string $path): void
    {
        if (self::windows()) {
            self::icacls($path, '/remove:d', self::EVERYONE);

            return;
        }

        chmod($path, is_dir($path) ? 0o755 : 0o644);
    }

    private static function icacls(string $path, string ...$arguments): void
    {
        new Process(['icacls', str_replace('/', DIRECTORY_SEPARATOR, $path), ...$arguments])->mustRun();
    }

    private static function windows(): bool
    {
        return PHP_OS_FAMILY === 'Windows';
    }
}
