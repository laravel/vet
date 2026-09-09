<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\FailureException;
use App\Support\Path;
use ZipArchive;

final class ExtractZip
{
    /**
     * @return int the number of files written
     */
    public static function handle(string $archive, string $destination): int
    {
        if (! class_exists(ZipArchive::class)) {
            throw new FailureException('The zip extension is required to read package archives.');
        }

        $zip = new ZipArchive;
        $opened = $zip->open($archive);

        if ($opened !== true) {
            throw new FailureException(sprintf('Could not open the archive [%s] (zip error [%d]).', $archive, (int) $opened));
        }

        try {
            $names = [];

            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = $zip->getNameIndex($index);

                if ($name !== false) {
                    $names[$index] = $name;
                }
            }

            if ($names === []) {
                throw new FailureException(sprintf('The archive [%s] is empty.', $archive));
            }

            $prefix = self::commonRoot($names);
            $written = 0;

            foreach ($names as $index => $name) {
                if (str_ends_with($name, '/')) {
                    continue;
                }

                $relative = $prefix === null ? $name : mb_substr($name, mb_strlen($prefix));
                $relative = self::safeRelativePath($relative, $archive);

                $target = $destination.'/'.$relative;
                $directory = dirname($target);

                if (! is_dir($directory) && ! @mkdir($directory, 0o777, true) && ! is_dir($directory)) {
                    throw new FailureException(sprintf('Could not create the directory [%s].', $directory));
                }

                if (file_exists($target)) {
                    throw new FailureException(sprintf(
                        'The archive [%s] writes two entries to [%s], so vet cannot read which bytes composer installs.',
                        $archive,
                        $relative,
                    ));
                }

                $stream = $zip->getStreamIndex($index);

                if ($stream === false) {
                    throw new FailureException(sprintf('Could not read [%s] from the archive [%s].', $name, $archive));
                }

                $handle = @fopen($target, 'wb');

                if ($handle === false) {
                    fclose($stream);

                    throw new FailureException(sprintf('Could not write to [%s].', $target));
                }

                stream_copy_to_stream($stream, $handle);
                fclose($stream);
                fclose($handle);

                $written++;
            }

            if ($written === 0) {
                throw new FailureException(sprintf('The archive [%s] contained no files.', $archive));
            }

            return $written;
        } finally {
            $zip->close();
        }
    }

    /**
     * @param  array<int, string>  $names
     */
    private static function commonRoot(array $names): ?string
    {
        $root = null;

        foreach ($names as $name) {
            $slash = mb_strpos($name, '/');

            if ($slash === false) {
                return null;
            }

            $segment = mb_substr($name, 0, $slash + 1);

            if ($root === null) {
                $root = $segment;
            } elseif ($root !== $segment) {
                return null;
            }
        }

        return $root;
    }

    private static function safeRelativePath(string $relative, string $archive): string
    {
        $relative = Path::toRelativeForm($relative);

        if ($relative === '' || str_starts_with($relative, '/') || preg_match('#^[a-zA-Z]:#', $relative) === 1) {
            throw new FailureException(sprintf('The archive [%s] contains an absolute path [%s].', $archive, $relative));
        }

        foreach (explode('/', $relative) as $segment) {
            if ($segment === '..') {
                throw new FailureException(sprintf('The archive [%s] contains a traversing path [%s].', $archive, $relative));
            }
        }

        return $relative;
    }
}
