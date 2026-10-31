<?php

declare(strict_types=1);

namespace App\Support;

use Composer\Semver\Semver;
use Composer\Semver\VersionParser;
use UnexpectedValueException;

final class ExcludeEntry
{
    public static function readable(string $entry): bool
    {
        try {
            new VersionParser()->parseConstraints(self::constraintOf($entry));
        } catch (UnexpectedValueException) {
            return false;
        }

        return true;
    }

    public static function matches(string $entry, string $package, string $version): bool
    {
        return fnmatch(self::patternOf($entry), $package) && Semver::satisfies($version, self::constraintOf($entry));
    }

    private static function patternOf(string $entry): string
    {
        return explode(':', $entry, 2)[0];
    }

    private static function constraintOf(string $entry): string
    {
        return explode(':', $entry, 2)[1] ?? '*';
    }
}
