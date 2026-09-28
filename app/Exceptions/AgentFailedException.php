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
            'Could not find an agent on your PATH. Install one of [%s].',
            implode('], [', array_column(AgentType::cases(), 'value')),
        ));
    }

    public static function unknown(string $name): self
    {
        return new self(sprintf(
            'The agent [%s] is not supported. Choose one of [%s].',
            $name,
            implode('], [', array_column(AgentType::cases(), 'value')),
        ));
    }

    public static function missingConfigured(AgentType $type): self
    {
        return new self(sprintf(
            'Could not find the configured agent [%s] on your PATH.',
            $type->value,
        ));
    }
}
