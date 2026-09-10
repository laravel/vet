<?php

declare(strict_types=1);

namespace App\ValueObjects;

use App\Enums\AgentVerdict;

final readonly class AgentReview
{
    /**
     * @param  array<int, AgentFinding>  $findings
     */
    public function __construct(
        public string $package,
        public AgentVerdict $verdict,
        public string $summary,
        public array $findings,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'verdict' => $this->verdict->value,
            'summary' => $this->summary,
            'findings' => array_map(
                static fn (AgentFinding $finding): array => $finding->toArray(),
                $this->findings,
            ),
        ];
    }
}
