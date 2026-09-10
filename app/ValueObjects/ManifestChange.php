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
     * @param  array<string, array{old: mixed, new: mixed}>  $values
     */
    private function __construct(
        private array $changedKeys,
        private array $values,
    ) {}

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    public static function between(array $old, array $new): self
    {
        $changed = [];
        $values = [];

        foreach (self::WATCHED as $key) {
            $before = self::dig($old, $key);
            $after = self::dig($new, $key);

            if ($before !== $after) {
                $changed[] = $key;
                $values[$key] = ['old' => $before, 'new' => $after];
            }
        }

        return new self($changed, $values);
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
        $value = $this->values[$key] ?? null;

        if ($value === null) {
            return '';
        }

        return sprintf('%s → %s', $this->inline($value['old']), $this->inline($value['new']));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function dig(array $data, string $key): mixed
    {
        $current = $data;

        foreach (explode('.', $key) as $segment) {
            if (! is_array($current) || ! array_key_exists($segment, $current)) {
                return null;
            }

            $current = $current[$segment];
        }

        return $current;
    }

    private function inline(mixed $value): string
    {
        if ($value === null) {
            return '(absent)';
        }

        if (is_scalar($value)) {
            return (string) (is_bool($value) ? ($value ? 'true' : 'false') : $value);
        }

        return trim(str_replace("\n", ' ', Json::encode(is_array($value) ? $value : [$value])));
    }
}
