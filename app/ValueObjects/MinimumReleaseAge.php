<?php

declare(strict_types=1);

namespace App\ValueObjects;

use App\Exceptions\FailureException;
use App\Support\ExcludeEntry;
use DateTimeImmutable;
use DateTimeZone;

final readonly class MinimumReleaseAge
{
    public const string DAYS = 'minimum-release-age';

    public const string EXCLUDE = 'minimum-release-age-exclude';

    private const string VET = 'laravel/vet';

    /**
     * @param  list<string>  $excluded
     */
    private function __construct(
        private int $days,
        private array $excluded,
    ) {}

    public static function from(mixed $days, mixed $excluded): self
    {
        $days ??= 0;

        if (! is_int($days) || $days < 0) {
            throw new FailureException(sprintf('The [%s] entry needs a whole number of days, such as [7].', self::DAYS));
        }

        $excluded ??= [];

        if (! is_array($excluded) || ! array_is_list($excluded) || ! array_all($excluded, static fn (mixed $name): bool => is_string($name) && $name !== '' && ExcludeEntry::readable($name))) {
            throw new FailureException(sprintf('The [%s] entry needs a list of package names, such as [laravel/*], each with an optional version constraint, such as [laravel/framework:^13.0].', self::EXCLUDE));
        }

        /** @var list<string> $excluded */
        return new self($days, $excluded);
    }

    public function holdsUntil(Package $package, DateTimeImmutable $now): ?DateTimeImmutable
    {
        if ($this->days === 0 || $this->excludes($package)) {
            return null;
        }

        $until = $package->released->plusDays($this->days);

        return $until instanceof DateTimeImmutable && $until > $now
            ? $until->setTimezone(new DateTimeZone('UTC'))
            : null;
    }

    private function excludes(Package $package): bool
    {
        return $package->name === self::VET || array_any($this->excluded, static fn (string $entry): bool => ExcludeEntry::matches($entry, $package));
    }
}
