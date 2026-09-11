<?php

declare(strict_types=1);

use App\Support\Path;

it('keeps the drive of a windows path when it normalizes the path', function (): void {
    expect(Path::normalize('C:\\Users\\acme\\..\\vendor\\widget'))->toBe('C:/Users/vendor/widget')
        ->and(Path::normalize('d:/project/./vendor'))->toBe('d:/project/vendor');
});
