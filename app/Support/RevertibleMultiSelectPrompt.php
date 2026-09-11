<?php

declare(strict_types=1);

namespace App\Support;

use Laravel\Prompts\MultiSelectPrompt;

final class RevertibleMultiSelectPrompt extends MultiSelectPrompt
{
    use RevertsOnEscape;

    /**
     * @param  array<string, string>  $options
     * @param  array<int, string>  $default
     */
    public function __construct(string $label, array $options, array $default, int $scroll, string $hint)
    {
        parent::__construct(label: $label, options: $options, default: $default, scroll: $scroll, hint: $hint);

        $this->revertOnEscape();
    }

    public static function shouldFallback(): bool
    {
        return MultiSelectPrompt::shouldFallback();
    }

    public function fallback(): mixed
    {
        return self::$fallbacks[MultiSelectPrompt::class]($this);
    }
}
