<?php

declare(strict_types=1);

namespace App\ValueObjects;

use App\Actions\BuildAgentPrompt;

final readonly class AgentBatch
{
    public const int MAX_PROMPTS = 20;

    public const int MAX_BYTES = 2 * 1024 * 1024;

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

    public function fitsOneRun(): bool
    {
        return $this->count() <= self::MAX_PROMPTS && $this->bytes() <= self::MAX_BYTES;
    }
}
