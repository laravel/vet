<?php

declare(strict_types=1);

namespace App\Support;

final class GithubHost
{
    private const string CANONICAL = 'github.com';

    private const string SCHEME = 'https';

    public static function matches(string $url): bool
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        if (mb_strtolower((string) $parts['scheme']) !== self::SCHEME) {
            return false;
        }

        $host = mb_strtolower(rtrim((string) $parts['host'], '.'));

        return $host === self::CANONICAL || str_ends_with($host, '.'.self::CANONICAL);
    }
}
