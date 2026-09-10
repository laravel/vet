<?php

declare(strict_types=1);

namespace App\Actions;

use App\ValueObjects\AgentFinding;
use App\ValueObjects\AgentReview;
use Illuminate\Console\OutputStyle;
use Symfony\Component\Console\Formatter\OutputFormatter;

final readonly class RenderAgentReview
{
    private const int MAX_FINDINGS = 5;

    public function __construct(
        private OutputStyle $output,
    ) {}

    public function verdict(AgentReview $agentReview): void
    {
        $this->output->writeln(sprintf(
            '    <fg=gray>agent</>  <fg=%s;options=bold>%s</>  <fg=gray>%s</>',
            $agentReview->verdict->color(),
            $agentReview->verdict->label(),
            OutputFormatter::escape($agentReview->summary),
        ));

        $shown = array_slice($agentReview->findings, 0, self::MAX_FINDINGS);

        foreach ($shown as $finding) {
            $this->finding($finding);
        }

        $hidden = count($agentReview->findings) - count($shown);

        if ($hidden > 0) {
            $this->output->writeln(sprintf('           <fg=gray>and %d more finding(s)</>', $hidden));
        }
    }

    public function noChange(): void
    {
        $this->notSent('This delta holds no change, so vet sent nothing.');
    }

    public function noEarlierTree(): void
    {
        $this->notSent('Vet cannot read the bytes of this package, so it sent nothing.');
    }

    private function finding(AgentFinding $agentFinding): void
    {
        $this->output->writeln(sprintf(
            '           <fg=yellow>%s</>  <fg=gray>%s</>',
            OutputFormatter::escape($agentFinding->path),
            OutputFormatter::escape($agentFinding->reason),
        ));
    }

    private function notSent(string $reason): void
    {
        $this->output->writeln(sprintf(
            '    <fg=gray>agent</>  <fg=yellow;options=bold>not sent</>  <fg=gray>%s</>',
            OutputFormatter::escape($reason),
        ));
    }
}
