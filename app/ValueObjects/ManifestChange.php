<?php

declare(strict_types=1);

namespace App\ValueObjects;

use App\Support\Json;

final readonly class ManifestChange
{
    private const array WATCHED = [
        'scripts',
        'bin',
        'type',
        'autoload',
        'require',
        'extra.class',
        'extra.plugin-modifies-downloads',
        'extra.plugin-modifies-install-path',
        'extra.laravel',
        'config.allow-plugins',
    ];

    private const array EXECUTION_KEYS = [
        'scripts',
        'bin',
        'type',
        'autoload',
        'extra.class',
        'extra.laravel',
    ];

    /**
     * @param  array<int, string>  $changedKeys
     * @param  array<string, array{old: mixed, new: mixed}>  $transitions
     */
    private function __construct(
        private array $changedKeys,
        private array $transitions,
    ) {}

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    public static function between(array $old, array $new): self
    {
        $changed = [];
        $transitions = [];

        foreach (self::WATCHED as $key) {
            $before = self::dig($old, $key);
            $after = self::dig($new, $key);

            if ($before !== $after) {
                $changed[] = $key;
                $transitions[$key] = ['old' => $before, 'new' => $after];
            }
        }

        return new self($changed, $transitions);
    }

    /**
     * @return array<int, string>
     */
    public function changedKeys(): array
    {
        return $this->changedKeys;
    }

    public function isEmpty(): bool
    {
        return $this->changedKeys === [];
    }

    public function touchesExecution(): bool
    {
        return array_intersect($this->changedKeys, self::EXECUTION_KEYS) !== [];
    }

    public function render(string $key): string
    {
        $transition = $this->transitions[$key] ?? null;

        if ($transition === null) {
            return '';
        }

        return sprintf('%s → %s', $this->inline($transition['old']), $this->inline($transition['new']));
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private static function dig(array $manifest, string $key): mixed
    {
        $current = $manifest;

        foreach (explode('.', $key) as $segment) {
            if (! is_array($current) || ! array_key_exists($segment, $current)) {
                return null;
            }

            $current = $current[$segment];
        }

        return $current;
    }

    private function inline(mixed $setting): string
    {
        if ($setting === null) {
            return '(absent)';
        }

        if (is_scalar($setting)) {
            return (string) (is_bool($setting) ? ($setting ? 'true' : 'false') : $setting);
        }

        return trim(str_replace("\n", ' ', Json::encode(is_array($setting) ? $setting : [$setting])));
    }
}
