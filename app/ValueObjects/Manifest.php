<?php

declare(strict_types=1);

namespace App\ValueObjects;

use App\Exceptions\EmptyTreeException;
use App\Exceptions\FailureException;
use App\Support\LocalState;
use App\Support\Path;

final readonly class Manifest
{
    /**
     * @param  array<string, string>  $entries
     */
    private function __construct(
        private array $entries,
        private int $bytes,
    ) {}

    public static function ofDirectory(string $directory): self
    {
        return self::ofDirectoryIgnoring($directory, []);
    }

    /**
     * @param  list<string>  $ignored
     */
    public static function ofDirectoryIgnoring(string $directory, array $ignored): self
    {
        if (! is_dir($directory)) {
            throw EmptyTreeException::missing($directory);
        }

        $entries = [];
        $bytes = 0;

        self::walk($directory, '', $ignored, $entries, $bytes);

        if ($entries === []) {
            throw EmptyTreeException::at($directory);
        }

        uksort($entries, strcmp(...));

        return new self($entries, $bytes);
    }

    /**
     * @return array<string, string>
     */
    public function entries(): array
    {
        return $this->entries;
    }

    public function count(): int
    {
        return count($this->entries);
    }

    public function bytes(): int
    {
        return $this->bytes;
    }

    public function toString(): string
    {
        $lines = '';

        foreach ($this->entries as $path => $hash) {
            $lines .= $hash.'  '.$this->escape($path)."\n";
        }

        return $lines;
    }

    public function hash(): TreeHash
    {
        return TreeHash::fromManifest($this->toString());
    }

    /**
     * @param  list<string>  $ignored
     * @param  array<string, string>  $entries
     */
    private static function walk(string $root, string $relative, array $ignored, array &$entries, int &$bytes): void
    {
        $directory = $relative === '' ? $root : $root.DIRECTORY_SEPARATOR.$relative;

        $names = @scandir($directory);

        if ($names === false) {
            throw new FailureException(sprintf('Could not read the directory [%s].', $directory));
        }

        foreach ($names as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $full = $directory.DIRECTORY_SEPARATOR.$name;
            $path = $relative === '' ? $name : $relative.DIRECTORY_SEPARATOR.$name;

            if (LocalState::covers($path) || in_array(Path::toRelativeForm($path), $ignored, true)) {
                continue;
            }

            if (is_link($full)) {
                $entries[Path::toRelativeForm($path)] = hash('sha256', (string) readlink($full));

                continue;
            }

            if (is_dir($full)) {
                self::walk($root, $path, $ignored, $entries, $bytes);

                continue;
            }

            $hash = @hash_file('sha256', $full);

            if ($hash === false) {
                throw new FailureException(sprintf('Could not read the file [%s].', $full));
            }

            $entries[Path::toRelativeForm($path)] = $hash;
            $bytes += (int) @filesize($full);
        }
    }

    private function escape(string $path): string
    {
        return str_replace(['\\', "\n", "\r"], ['\\\\', '\\n', '\\r'], $path);
    }
}
