<?php

declare(strict_types=1);

namespace App\ValueObjects;

final readonly class AgentFinding
{
    public function __construct(
        public string $path,
        public string $reason,
    ) {}

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'reason' => $this->reason,
        ];
    }
}
