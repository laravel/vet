<?php

declare(strict_types=1);

namespace App\ValueObjects;

final readonly class HttpResponse
{
    private function __construct(
        public string $body,
        public ?string $redirect,
    ) {}

    public static function body(string $body): self
    {
        return new self($body, null);
    }

    public static function redirect(string $url): self
    {
        return new self('', $url);
    }
}
