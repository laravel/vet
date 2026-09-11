<?php

declare(strict_types=1);

namespace App\ValueObjects;

use App\Enums\AgentVerdict;

final readonly class AgentReview
{
    /**
     * @param  array<int, AgentFinding>  $findings
     * @param  array<int, UnreadFile>  $unread
     */
    public function __construct(
        public string $package,
        public AgentVerdict $verdict,
        public string $summary,
        public array $findings,
        public array $unread,
    ) {}

    /**
     * @return array<string, int>
     */
    public function unreadCounts(): array
    {
        return array_count_values(array_map(
            static fn (UnreadFile $file): string => $file->reason->label(),
            $this->unread,
        ));
    }

    public function unreadNote(): string
    {
        $counts = $this->unreadCounts();
        $total = count($this->unread);

        return match (count($counts)) {
            0 => '',
            1 => sprintf('%d %s %s', $total, $total === 1 ? 'file' : 'files', array_key_first($counts)),
            default => sprintf('%d files not read', $total),
        };
    }
}
