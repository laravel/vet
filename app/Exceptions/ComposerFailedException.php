<?php

declare(strict_types=1);

namespace App\Exceptions;

final class ComposerFailedException extends VetException
{
    /**
     * @param  array<int, string>  $output
     */
    private function __construct(string $message, public readonly array $output)
    {
        parent::__construct($message);
    }

    public static function missing(string $binary): self
    {
        return new self(sprintf(
            'Could not find [%s] on your PATH. Install Composer, or name the binary in [VET_COMPOSER_BINARY].',
            $binary,
        ), []);
    }

    public static function planFailed(string $rootPath, ?int $exitCode, string $output): self
    {
        $lines = preg_split('/\R/', trim($output));

        return new self(sprintf(
            '[composer update --dry-run] failed in [%s] with exit code [%s]. Composer says:',
            $rootPath,
            $exitCode === null ? 'unknown' : (string) $exitCode,
        ), $lines === false ? [] : array_values(array_filter($lines, static fn (string $line): bool => trim($line) !== '')));
    }
}
