<?php

declare(strict_types=1);

namespace App\Exceptions;

final class FetchFailedException extends VetException
{
    public static function status(string $url, int $status, string $body = ''): self
    {
        $hint = match (true) {
            $status === 403 && str_contains($url, 'api.github.com') => ' GitHub rate-limits unauthenticated requests to 60/hour; set GITHUB_TOKEN to raise it.',
            $status === 404 => ' The package or version may not exist.',
            default => '',
        };

        return new self(sprintf(
            'Request to [%s] failed with HTTP [%d].%s%s',
            $url,
            $status,
            $hint,
            $body === '' ? '' : ' Response: '.mb_substr(trim($body), 0, 200),
        ));
    }

    public static function transport(string $url, string $reason): self
    {
        return new self(sprintf('Request to [%s] failed: %s', $url, $reason));
    }

    public static function empty(string $url): self
    {
        return new self(sprintf('Request to [%s] returned an empty body; refusing to treat that as "no changes".', $url));
    }
}
