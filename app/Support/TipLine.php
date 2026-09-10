<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Console\View\Components\Line;

final class TipLine extends Line
{
    /**
     * @var array<string, array<string, string>>
     */
    protected static $styles = [
        'tip' => [
            'bgColor' => 'blue',
            'fgColor' => 'white',
            'title' => 'tip',
        ],
    ];
}
