<?php

declare(strict_types=1);

$core = [
    'App\Enums',
    'App\Exceptions',
    'App\ValueObjects',
];

arch('the data holds no dependency', function () use ($core): void {
    expect($core)->toOnlyUse([...$core, 'App\Actions', 'App\Support']);
});

arch('exceptions carry the suffix', function (): void {
    expect('App\Exceptions')->toHaveSuffix('Exception');
});
