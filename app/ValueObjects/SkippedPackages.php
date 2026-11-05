<?php

declare(strict_types=1);

namespace App\ValueObjects;

use App\Exceptions\FailureException;

final readonly class SkippedPackages
{
    public const string SECTION = 'skip';

    /**
     * @param  list<string>  $patterns
     */
    private function __construct(
        private array $patterns,
    ) {}

    public static function from(mixed $patterns): self
    {
        $patterns ??= [];

        if (! is_array($patterns) || ! array_is_list($patterns) || ! array_all($patterns, static fn (mixed $name): bool => is_string($name) && $name !== '')) {
            throw new FailureException(sprintf('The [%s] entry needs a list of package names, such as [laravel/*].', self::SECTION));
        }

        /** @var list<string> $patterns */
        return new self($patterns);
    }

    public function matches(string $package): bool
    {
        return array_any($this->patterns, static fn (string $pattern): bool => fnmatch($pattern, $package));
    }

    /**
     * @param  list<string>  $patterns
     */
    public function with(array $patterns): self
    {
        $merged = $this->patterns;

        foreach ($patterns as $pattern) {
            if (! in_array($pattern, $merged, true)) {
                $merged[] = $pattern;
            }
        }

        sort($merged, SORT_STRING);

        return new self($merged);
    }

    /**
     * @return list<string>
     */
    public function patterns(): array
    {
        return $this->patterns;
    }
}
