<?php

declare(strict_types=1);

namespace App\ValueObjects;

use App\Actions\BuildAgentPrompt;

final readonly class AgentBatch
{
    /**
     * @param  array<string, AgentPrompt>  $prompts
     */
    public function __construct(
        public array $prompts,
    ) {}

    /**
     * @param  array<string, ?Delta>  $deltas
     */
    public static function of(array $deltas): self
    {
        $builder = new BuildAgentPrompt;
        $prompts = [];

        foreach ($deltas as $package => $delta) {
            if ($delta instanceof Delta && ! $delta->isEmpty()) {
                $prompts[$package] = $builder->handle($delta);
            }
        }

        return new self($prompts);
    }

    public function count(): int
    {
        return count($this->prompts);
    }

    public function isEmpty(): bool
    {
        return $this->prompts === [];
    }

    public function bytes(): int
    {
        $bytes = 0;

        foreach ($this->prompts as $prompt) {
            $bytes += $prompt->bytes();
        }

        return $bytes;
    }
}
