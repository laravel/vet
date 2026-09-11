<?php

declare(strict_types=1);

namespace App\Actions;

final class ExpandMinifiedMetadata
{
    private const string UNSET = '__unset';

    /**
     * @param  array<int, mixed>  $versions
     * @return array<int, array<string, mixed>>
     */
    public static function handle(array $versions): array
    {
        $expanded = [];
        $current = null;

        foreach ($versions as $version) {
            if (! is_array($version)) {
                continue;
            }

            /** @var array<string, mixed> $version */
            if ($current === null) {
                $current = array_filter($version, static fn (mixed $field): bool => $field !== self::UNSET);
                $expanded[] = $current;

                continue;
            }

            foreach ($version as $key => $field) {
                if ($field === self::UNSET) {
                    unset($current[$key]);
                } else {
                    $current[$key] = $field;
                }
            }

            $expanded[] = $current;
        }

        return $expanded;
    }

    /**
     * @param  array<string, mixed>  $document
     */
    public static function isMinified(array $document): bool
    {
        return ($document['minified'] ?? null) === 'composer/2.0';
    }
}
