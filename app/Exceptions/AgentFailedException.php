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
}
