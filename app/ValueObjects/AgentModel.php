<?php

declare(strict_types=1);

namespace App\ValueObjects;

final readonly class AgentModel
{
    private function __construct(
        public string $name,
    ) {}

    public static function default(): self
    {
        return new self('');
    }

    public static function of(string $name): self
    {
        return new self(trim($name));
    }

    public function isDefault(): bool
    {
        return $this->name === '';
    }

    /**
     * @return array<int, string>
     */
    public function arguments(): array
    {
        return $this->isDefault() ? [] : ['--model', $this->name];
    }
}
