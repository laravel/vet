<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Console\View\Components\Factory;

final class ControlSafeComponents extends Factory
{
    /**
     * @param  string  $method
     * @param  array<int, mixed>  $parameters
     */
    public function __call($method, $parameters): mixed
    {
        return parent::__call($method, array_map(self::readable(...), $parameters));
    }

    public function tip(string $message): void
    {
        new TipLine($this->output)->render('tip', ControlSafe::text($message));
    }

    private static function readable(mixed $parameter): mixed
    {
        if (is_string($parameter)) {
            return ControlSafe::text($parameter);
        }

        if (is_array($parameter)) {
            return array_map(self::readable(...), $parameter);
        }

        return $parameter;
    }
}
