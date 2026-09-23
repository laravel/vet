<?php

declare(strict_types=1);

namespace App\ValueObjects;

use App\Exceptions\FailureException;
use App\Support\Path;

final readonly class IgnoredFiles
{
    /**
     * @param  array<string, list<string>>  $paths
     */
    private function __construct(
        private array $paths,
    ) {}

    /**
     * @param  array<array-key, mixed>  $section
     */
    public static function fromArray(array $section): self
    {
        $paths = [];

        foreach ($section as $package => $entries) {
            if (! is_string($package) || ! is_array($entries) || ! array_is_list($entries)) {
                throw new FailureException(sprintf('The [ignore] entry for [%s] needs a list of paths.', $package));
            }

            foreach ($entries as $entry) {
                if (! is_string($entry) || trim($entry, '/') === '') {
                    throw new FailureException(sprintf('The [ignore] entry for [%s] needs a list of paths.', $package));
                }

                $paths[$package][] = trim(Path::toRelativeForm($entry), '/');
            }
        }

        return new self($paths);
    }

    /**
     * @return list<string>
     */
    public function of(string $package): array
    {
        return $this->paths[$package] ?? [];
    }
}
