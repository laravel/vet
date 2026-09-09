<?php

declare(strict_types=1);

namespace App\Support;

final class Path
{
    public static function join(string ...$segments): string
    {
        $parts = [];

        foreach ($segments as $index => $segment) {
            $segment = $index === 0 ? rtrim($segment, '/\\') : trim($segment, '/\\');

            if ($segment !== '') {
                $parts[] = $segment;
            }
        }

        return implode('/', $parts);
    }

    public static function normalize(string $path): string
    {
        $isAbsolute = str_starts_with($path, '/');
        $prefix = '';

        if (preg_match('#^([a-zA-Z]:)[/\\\\]#', $path, $matches) === 1) {
            $prefix = $matches[1];
            $isAbsolute = true;
            $path = mb_substr($path, mb_strlen($prefix));
        }

        $segments = [];

        foreach (preg_split('#[/\\\\]+#', $path) ?: [] as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..' && $segments !== [] && end($segments) !== '..') {
                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        return $prefix.($isAbsolute ? '/' : '').implode('/', $segments);
    }

    public static function toRelativeForm(string $path): string
    {
        return str_replace(DIRECTORY_SEPARATOR, '/', $path);
    }
}
