<?php

declare(strict_types=1);

namespace Tests\Fixtures;

final class UnreadableDirectory
{
    public const string ROOT = self::SCHEME.'://tree';

    private const string SCHEME = 'unreadable';

    public mixed $context;

    /**
     * @var list<string>
     */
    private array $names = [];

    public static function register(): void
    {
        stream_wrapper_register(self::SCHEME, self::class);
    }

    public static function unregister(): void
    {
        stream_wrapper_unregister(self::SCHEME);
    }

    /**
     * @return array{mode: int}
     */
    public function url_stat(): array
    {
        return ['mode' => 0o040755];
    }

    public function dir_opendir(string $path): bool
    {
        if ($path !== self::ROOT) {
            return false;
        }

        $this->names = ['.', '..', 'locked'];

        return true;
    }

    public function dir_readdir(): string|false
    {
        return array_shift($this->names) ?? false;
    }

    public function dir_closedir(): bool
    {
        return true;
    }
}
