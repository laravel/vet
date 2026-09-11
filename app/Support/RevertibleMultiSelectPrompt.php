<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use Laravel\Prompts\Exceptions\FormRevertedException;
use Laravel\Prompts\Key;
use Laravel\Prompts\MultiSelectPrompt;

final class RevertibleMultiSelectPrompt extends MultiSelectPrompt
{
    /**
     * @param  array<string, string>  $options
     * @param  array<int, string>  $default
     */
    public function __construct(string $label, array $options, array $default, int $scroll, string $hint)
    {
        parent::__construct(label: $label, options: $options, default: $default, scroll: $scroll, hint: $hint);

        $this->on('key', function (string $key): void {
            if ($key !== Key::ESCAPE || ! self::$revertUsing instanceof Closure) {
                return;
            }

            $this->state = 'cancel';
            $this->cancelMessage = 'Reverted.';
            $this->render();

            throw new FormRevertedException;
        });
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
