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
    public static function readFile(string $path, string $what = 'file'): array
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
            throw InvalidJsonException::shape($path, 'expected a JSON object at the top level.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function encode(array $data): string
    {
        $encoded = json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );

        return $encoded."\n";
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function string(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public static function array(array $data, string $key): array
    {
        $value = $data[$key] ?? null;

        return is_array($value) ? $value : [];
    }
}
