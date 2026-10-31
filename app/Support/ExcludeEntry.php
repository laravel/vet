<?php

declare(strict_types=1);

namespace App\Support;

use App\ValueObjects\Package;
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

    public static function matches(string $entry, Package $package): bool
    {
        return fnmatch(self::patternOf($entry), $package->name) && Semver::satisfies($package->version, self::constraintOf($entry));
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
