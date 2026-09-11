<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\Gutter;
use App\Support\Bytes;
use App\ValueObjects\AgentFinding;
use App\ValueObjects\AgentReview;
use App\ValueObjects\UnreadFile;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Str;
use Symfony\Component\Console\Formatter\OutputFormatter;

final readonly class RenderAgentReview
{
    private const int MAX_FINDINGS = 5;

    private const int MAX_UNREAD = 5;

    private const string INDENT = '        ';

    private const string SKIP = '<fg=white;bg=gray;options=bold> SKIP </>';

    public function __construct(
        private OutputStyle $output,
        private Gutter $gutter,
    ) {}

    public function verdict(AgentReview $agentReview): void
    {
        $this->output->writeln($this->gutter->line(sprintf(
            '%s  %s',
            $agentReview->verdict->badge(),
            OutputFormatter::escape($agentReview->summary),
        )));

        $shown = array_slice($agentReview->findings, 0, self::MAX_FINDINGS);

        foreach ($shown as $finding) {
            $this->finding($finding);
        }

        $this->more(count($agentReview->findings) - count($shown), 'finding');

        if ($agentReview->unread !== []) {
            $this->unread($agentReview);
        }
    }

    public function noChange(): void
    {
        $this->notSent('No file changed, so vet sent nothing to the agent.');
    }

    public function noEarlierTree(): void
    {
        $this->notSent('Vet cannot read the files of this package, so it sent nothing to the agent.');
    }

    private function finding(AgentFinding $agentFinding): void
    {
        $this->output->writeln($this->gutter->line(sprintf(
            self::INDENT.'<fg=yellow>%s</>  <fg=gray>%s</>',
            OutputFormatter::escape($agentFinding->path),
            OutputFormatter::escape($agentFinding->reason),
        )));
    }

    private function unread(AgentReview $agentReview): void
    {
        $counts = $agentReview->unreadCounts();

        $this->output->writeln($this->gutter->line(sprintf(
            self::INDENT.'<fg=yellow>%s</>',
            $this->unreadSentence(count($agentReview->unread), $counts),
        )));

        $shown = array_slice($agentReview->unread, 0, self::MAX_UNREAD);
        $width = max(0, ...array_map(static fn (UnreadFile $file): int => mb_strlen($file->path), $shown));

        foreach ($shown as $file) {
            $this->output->writeln($this->gutter->line(sprintf(
                self::INDENT.'%s  <fg=gray>%s</>',
                OutputFormatter::escape(Str::padRight($file->path, $width)),
                count($counts) === 1
                    ? Bytes::human($file->bytes)
                    : sprintf('%s · %s', $file->reason->label(), Bytes::human($file->bytes)),
            )));
        }

        $this->more(count($agentReview->unread) - count($shown), 'file');
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function unreadSentence(int $total, array $counts): string
    {
        if (count($counts) === 1) {
            return sprintf(
                'The agent did not read [%d] %s, because %s %s. Read %s yourself:',
                $total,
                Str::plural('file', $total),
                $total === 1 ? 'it is' : 'they are',
                array_key_first($counts),
                $total === 1 ? 'it' : 'them',
            );
        }

        $parts = [];

        foreach ($counts as $label => $count) {
            $parts[] = sprintf('[%d] %s', $count, $label);
        }

        return sprintf('The agent did not read [%d] files: %s. Read them yourself:', $total, implode(', ', $parts));
    }

    private function more(int $hidden, string $noun): void
    {
        if ($hidden > 0) {
            $this->output->writeln($this->gutter->line(sprintf(
                self::INDENT.'<fg=gray>and %d more %s</>',
                $hidden,
                Str::plural($noun, $hidden),
            )));
        }
    }

    private function notSent(string $reason): void
    {
        $this->output->writeln($this->gutter->line(sprintf(
            '%s  %s',
            self::SKIP,
            OutputFormatter::escape($reason),
        )));
    }
}
