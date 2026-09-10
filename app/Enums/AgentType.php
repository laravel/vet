<?php

declare(strict_types=1);

namespace App\Enums;

use App\ValueObjects\AgentAnswer;
use App\ValueObjects\AgentModel;

enum AgentType: string
{
    case Claude = 'claude';

    case Codex = 'codex';

    case Gemini = 'gemini';

    public static function of(string $binary): ?self
    {
        return self::tryFrom(basename($binary));
    }

    /**
     * @return array<int, string>
     */
    public function arguments(string $schemaFile, AgentModel $model): array
    {
        return [...$this->flags($schemaFile), ...$model->arguments()];
    }

    /**
     * @return array<int, string>
     */
    public function models(): array
    {
        return match ($this) {
            self::Claude => ['fable', 'opus', 'sonnet', 'haiku'],
            self::Codex => ['gpt-6-astra', 'gpt-5.6-sol', 'gpt-5.6-terra', 'gpt-5.6-luna', 'gpt-5.3-codex-spark'],
            self::Gemini => ['gemini-3.1-pro-preview', 'gemini-2.5-pro', 'gemini-2.5-flash'],
        };
    }

    public function answerOf(string $output): string
    {
        return match ($this) {
            self::Claude => $this->envelope($output, ['structured_output', 'result']),
            self::Gemini => $this->envelope($output, ['response']),
            self::Codex => $output,
        };
    }

    /**
     * @return array<int, string>
     */
    private function flags(string $schemaFile): array
    {
        return match ($this) {
            self::Claude => [
                '--print',
                '--tools', '',
                '--no-session-persistence',
                '--output-format', 'json',
                '--json-schema', AgentAnswer::schema(),
            ],
            self::Codex => [
                'exec', '-',
                '--sandbox', 'read-only',
                '--skip-git-repo-check',
                '--output-schema', $schemaFile,
            ],
            self::Gemini => [
                '--output-format', 'json',
                '--approval-mode', 'plan',
            ],
        };
    }

    /**
     * @param  array<int, string>  $keys
     */
    private function envelope(string $output, array $keys): string
    {
        $decoded = json_decode($output, true);

        if (! is_array($decoded)) {
            return $output;
        }

        foreach ($keys as $key) {
            $value = $decoded[$key] ?? null;

            if (is_array($value)) {
                $encoded = json_encode($value);

                if ($encoded !== false) {
                    return $encoded;
                }
            }

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return $output;
    }
}
