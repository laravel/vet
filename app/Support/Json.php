<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\FileNotFoundException;
use App\Exceptions\InvalidJsonException;
use JsonException;

final class Json
{
    /**
     * @return array<string, mixed>
     */
    public static function readFile(string $path, string $what): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw FileNotFoundException::at($path, $what);
        }

        $contents = file_get_contents($path);

        if ($contents === false || $contents === '') {
            throw FileNotFoundException::at($path, $what);
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $jsonException) {
            throw InvalidJsonException::at($path, $jsonException->getMessage());
        }

        if (! is_array($decoded)) {
            throw InvalidJsonException::structure($path, 'expected a JSON object at the top level.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param  array<array-key, mixed>  $document
     */
    public static function encode(array $document): string
    {
        $encoded = json_encode(
            $document,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );

        return $encoded."\n";
    }

    /**
     * @param  array<array-key, mixed>  $document
     */
    public static function string(array $document, string $key): ?string
    {
        $field = $document[$key] ?? null;

        return is_string($field) ? $field : null;
    }

    /**
     * @param  array<array-key, mixed>  $document
     * @return array<array-key, mixed>
     */
    public static function array(array $document, string $key): array
    {
        $field = $document[$key] ?? null;

        return is_array($field) ? $field : [];
    }
}
