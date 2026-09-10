<?php

declare(strict_types=1);

namespace App\ValueObjects;

final readonly class AgentPrompt
{
    /**
     * @param  array<int, string>  $unread  the paths whose bytes the prompt does not hold
     * @param  array<int, string>  $paths  every path that the delta holds
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
}
