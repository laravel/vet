<?php

declare(strict_types=1);

namespace Tests\Fixtures;

final class CraftedArchive
{
    public const int IMPLODED = 6;

    private const int LOCAL_SIGNATURE = 0x04034B50;

    private const int CENTRAL_SIGNATURE = 0x02014B50;

    private const int END_SIGNATURE = 0x06054B50;

    private const int VERSION = 20;

    private const int STORED = 0;

    /**
     * @param  array<int, array{name: string, contents: string}>  $entries  the entries, in the order that the archive holds them
     */
    public static function write(string $path, array $entries): string
    {
        return self::writeWithMethod($path, $entries, self::STORED);
    }

    /**
     * @param  array<int, array{name: string, contents: string}>  $entries  the entries, in the order that the archive holds them
     */
    public static function writeWithMethod(string $path, array $entries, int $method): string
    {
        $local = '';
        $central = '';

        foreach ($entries as $entry) {
            $name = $entry['name'];
            $contents = $entry['contents'];
            $crc = crc32($contents);
            $size = strlen($contents);
            $offset = strlen($local);

            $local .= pack(
                'VvvvvvVVVvv',
                self::LOCAL_SIGNATURE,
                self::VERSION,
                0,
                $method,
                0,
                0,
                $crc,
                $size,
                $size,
                strlen($name),
                0,
            ).$name.$contents;

            $central .= pack(
                'VvvvvvvVVVvvvvvVV',
                self::CENTRAL_SIGNATURE,
                self::VERSION,
                self::VERSION,
                0,
                $method,
                0,
                0,
                $crc,
                $size,
                $size,
                strlen($name),
                0,
                0,
                0,
                0,
                0,
                $offset,
            ).$name;
        }

        $end = pack(
            'VvvvvVVv',
            self::END_SIGNATURE,
            0,
            0,
            count($entries),
            count($entries),
            strlen($central),
            strlen($local),
            0,
        );

        file_put_contents($path, $local.$central.$end);

        return $path;
    }
}
