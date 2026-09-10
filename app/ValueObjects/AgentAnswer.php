<?php

declare(strict_types=1);

namespace App\ValueObjects;

use JsonException;

final readonly class AgentAnswer
{
    public const int MAX_SUMMARY = 100;

    private const int MAX_FINDINGS = 20;

    private const string SCHEMA = '{"type":"object","additionalProperties":false,"required":["verdict","summary","findings"],'
        .'"properties":{'
        .'"verdict":{"type":"string","enum":["clear","risk"]},'
        .'"summary":{"type":"string","maxLength":'.self::MAX_SUMMARY.'},'
        .'"findings":{"type":"array","maxItems":'.self::MAX_FINDINGS.',"items":{"type":"object","additionalProperties":false,'
        .'"required":["path","reason"],"properties":{"path":{"type":"string"},"reason":{"type":"string"}}}}'
        .'}}';

    /**
     * @param  array<int, AgentFinding>  $findings
     */
    private function __construct(
        public string $verdict,
        public string $summary,
        public array $findings,
    ) {}

    public static function schema(): string
    {
        return self::SCHEMA;
    }

    public static function shape(): string
    {
        return '{"verdict": "clear or risk", "summary": "one sentence of '
            .self::MAX_SUMMARY
            .' characters at the most", "findings": [{"path": "a path that this prompt holds", "reason": "what that change does"}]}';
    }

    public static function read(string $output): ?self
    {
        foreach (self::candidates($output) as $candidate) {
            $answer = self::of($candidate);

            if ($answer instanceof self) {
                return $answer;
            }
        }

        return null;
    }

    private static function of(string $candidate): ?self
    {
        try {
            $decoded = json_decode($candidate, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($decoded) || ! is_string($decoded['verdict'] ?? null)) {
            return null;
        }

        $summary = $decoded['summary'] ?? '';

        return new self(
            $decoded['verdict'],
            is_string($summary) ? $summary : '',
            self::findings($decoded['findings'] ?? []),
        );
    }

    /**
     * @return array<int, AgentFinding>
     */
    private static function findings(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $findings = [];

        foreach (array_slice($value, 0, self::MAX_FINDINGS) as $item) {
            if (! is_array($item) || ! is_string($item['path'] ?? null)) {
                continue;
            }

            $reason = $item['reason'] ?? '';

            $findings[] = new AgentFinding($item['path'], is_string($reason) ? $reason : '');
        }

        return $findings;
    }

    /**
     * @return array<int, string>
     */
    private static function candidates(string $output): array
    {
        $candidates = [trim($output)];

        if (preg_match_all('/```(?:json)?\s*(\{.*?\})\s*```/s', $output, $matches) >= 1) {
            foreach (array_reverse($matches[1]) as $block) {
                $candidates[] = $block;
            }
        }

        $start = mb_strpos($output, '{');
        $end = mb_strrpos($output, '}');

        if ($start !== false && $end !== false && $end > $start) {
            $candidates[] = mb_substr($output, $start, $end - $start + 1);
        }

        return $candidates;
    }
}
