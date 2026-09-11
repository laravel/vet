<?php

declare(strict_types=1);

namespace App\ValueObjects;

final readonly class AgentPrompt
{
    /**
     * @param  array<int, string>  $unread
     * @param  array<int, string>  $paths
     */
    public function __construct(
        public string $text,
        public array $unread,
        public array $paths,
    ) {}

    public function holds(string $path): bool
    {
        return in_array($path, $this->paths, true);
    }

    public function bytes(): int
    {
        return mb_strlen($this->text, '8bit');
    }
}
