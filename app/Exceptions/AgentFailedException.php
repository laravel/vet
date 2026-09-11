<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\AgentType;
use RuntimeException;

final class AgentFailedException extends RuntimeException implements VetException
{
    public static function missing(): self
    {
        return new self(sprintf(
            'Could not find an agent on your PATH. Install one of [%s], or name the binary in [VET_AGENT_BINARY].',
            implode('], [', array_column(AgentType::cases(), 'value')),
        ));
    }

    public static function noModelFlag(string $binary): self
    {
        return new self(sprintf(
            'Could not pass a model to [%s]. Name one of [%s] in [VET_AGENT_BINARY], or drop the model.',
            $binary,
            implode('], [', array_column(AgentType::cases(), 'value')),
        ));
    }

    public static function notExecutable(string $binary): self
    {
        return new self(sprintf(
            'Could not run [%s]. Name an agent binary that exists in [VET_AGENT_BINARY].',
            $binary,
        ));
    }
}
