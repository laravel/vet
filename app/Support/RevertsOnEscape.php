<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use Laravel\Prompts\Exceptions\FormRevertedException;
use Laravel\Prompts\Key;

trait RevertsOnEscape
{
    private function revertOnEscape(): void
    {
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
}
