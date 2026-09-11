<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Closure;

final class Warnings
{
    /**
     * @param  Closure(): mixed  $callback
     */
    public static function silenced(Closure $callback): void
    {
        $level = error_reporting(E_ALL & ~E_WARNING);

        try {
            $callback();
        } finally {
            error_reporting($level);
        }
    }
}
