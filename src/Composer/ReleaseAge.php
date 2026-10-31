<?php

declare(strict_types=1);

namespace Laravel\Vet\Composer;

use Composer\Semver\Semver;
use Composer\Semver\VersionParser;
use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use UnexpectedValueException;

final readonly class ReleaseAge
{
    public const string DAYS = 'minimum-release-age';

    public const string EXCLUDE = 'minimum-release-age-exclude';

    private const string VET = 'laravel/vet';

    /**
     * @param  list<string>  $excluded
     */
    private function __construct(
        public int $days,
        private array $excluded,
    ) {}

    public static function fromTrustFile(string $path): self
    {
        $contents = is_file($path) ? file_get_contents($path) : false;
        $document = is_string($contents) ? json_decode($contents, true) : null;

        if (! is_array($document)) {
            return self::off();
        }

        $days = $document[self::DAYS] ?? 0;
        $excluded = $document[self::EXCLUDE] ?? [];

        if (! is_int($days) || $days < 1 || ! is_array($excluded) || ! array_is_list($excluded)) {
            return self::off();
        }

        if (! array_all($excluded, static fn (mixed $name): bool => is_string($name) && $name !== '' && self::readable($name))) {
            return self::off();
        }

        /** @var list<string> $excluded */
        return new self($days, $excluded);
    }

    public function holdsBack(): bool
    {
        return $this->days > 0;
    }

    public function allows(string $package, string $version, DateTimeInterface $released, DateTimeImmutable $now): bool
    {
        if (! $this->holdsBack() || $this->excludes($package, $version)) {
            return true;
        }

        return DateTimeImmutable::createFromInterface($released)->add(new DateInterval(sprintf('P%dD', $this->days))) <= $now;
    }

    private static function readable(string $entry): bool
    {
        try {
            new VersionParser()->parseConstraints(self::split($entry)[1]);
        } catch (UnexpectedValueException) {
            return false;
        }

        return true;
    }

    /**
     * @return array{string, string}
     */
    private static function split(string $entry): array
    {
        [$pattern, $constraint] = [...explode(':', $entry, 2), '*'];

        return [$pattern, $constraint];
    }

    private static function off(): self
    {
        return new self(0, []);
    }

    private function excludes(string $package, string $version): bool
    {
        return $package === self::VET || array_any($this->excluded, static function (string $entry) use ($package, $version): bool {
            [$pattern, $constraint] = self::split($entry);

            return fnmatch($pattern, $package) && Semver::satisfies($version, $constraint);
        });
    }
}
