<?php

declare(strict_types=1);

namespace App\Support;

use Laravel\Prompts\SuggestPrompt;

final class RevertibleSuggestPrompt extends SuggestPrompt
{
    use RevertsOnEscape;

    /**
     * @param  array<int, string>  $options
     */
    public function __construct(string $label, array $options, string $placeholder, string $hint)
    {
        parent::__construct(label: $label, options: $options, placeholder: $placeholder, hint: $hint);

        $this->revertOnEscape();
    }

    public static function shouldFallback(): bool
    {
        return SuggestPrompt::shouldFallback();
    }

    public function fallback(): mixed
    {
        return self::$fallbacks[SuggestPrompt::class]($this);
    }
}
