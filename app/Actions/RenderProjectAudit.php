<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AgentVerdict;
use App\Enums\AuditStatus;
use App\Enums\BucketType;
use App\Enums\Gutter;
use App\Exceptions\VetException;
use App\Support\Bytes;
use App\Support\ControlSafeComponents;
use App\Support\Invitation;
use App\Support\Json;
use App\ValueObjects\AgentBatch;
use App\ValueObjects\AgentReview;
use App\ValueObjects\AuditReport;
use App\ValueObjects\ComposerOperation;
use App\ValueObjects\Delta;
use App\ValueObjects\LockDiscrepancy;
use App\ValueObjects\PackageAudit;
use App\ValueObjects\PackageReview;
use Illuminate\Console\OutputStyle;
use Symfony\Component\Console\Output\OutputInterface;

final readonly class RenderProjectAudit
{
    private const int SUCCESS = 0;

    private const int FAILURE = 1;

    private ControlSafeComponents $components;

    private RenderDelta $renderer;

    private RenderAgentReview $agentRenderer;

    /**
     * @param  array<string, PackageAudit>  $failing
     * @param  array<string, PackageReview>  $reviews
     * @param  array<string, AgentReview>  $agentReviews
     */
    private function __construct(
        private OutputStyle $output,
        private AuditProject $auditor,
        private AuditReport $report,
        private Invitation $invitation,
        private array $failing,
        private array $reviews,
        private array $agentReviews,
    ) {
        $this->components = new ControlSafeComponents($output);
        $this->renderer = new RenderDelta($output, $invitation, Gutter::Package);
        $this->agentRenderer = new RenderAgentReview($output, Gutter::Package);
    }

    public static function of(OutputStyle $output, AuditProject $auditor, AuditReport $report, Invitation $invitation): self
    {
        $failing = $report->failing();
        $reviews = [];

        foreach ($failing as $audit) {
            $reviews[$audit->package] = self::review($auditor, $audit);
        }

        uasort($failing, static fn (PackageAudit $a, PackageAudit $b): int => [
            $a->status->weight(),
            $reviews[$b->package]->files,
            $a->package,
        ] <=> [
            $b->status->weight(),
            $reviews[$a->package]->files,
            $b->package,
        ]);

        return new self($output, $auditor, $report, $invitation, $failing, $reviews, []);
    }

    /**
     * @param  array<string, AgentReview>  $agentReviews
     */
    public function withAgentReviews(array $agentReviews): self
    {
        return new self($this->output, $this->auditor, $this->report, $this->invitation, $this->failing, $this->reviews, $agentReviews);
    }

    /**
     * @return array<string, ?Delta>
     */
    public function deltas(): array
    {
        $deltas = [];

        foreach ($this->reviews as $package => $review) {
            $deltas[$package] = $review->delta;
        }

        return $deltas;
    }

    public function agentBatch(): AgentBatch
    {
        $deltas = [];

        foreach ($this->deltas() as $package => $delta) {
            $deltas[$package] = $delta instanceof Delta
                ? $delta
                : $this->auditor->wholeTree($this->failing[$package]);
        }

        return AgentBatch::of($deltas);
    }

    public function renderOverBudgetTip(AgentBatch $batch): void
    {
        $this->components->tip($this->overBudgetTip($batch));
    }

    /**
     * @return array<string, PackageAudit>
     */
    public function failing(): array
    {
        return $this->failing;
    }

    /**
     * @return array<string, AgentReview>
     */
    public function agentReviews(): array
    {
        return $this->agentReviews;
    }

    public function renderAgentReviews(): void
    {
        foreach ($this->failing as $audit) {
            $this->renderRow($audit, $this->reviews[$audit->package]);
            $this->renderAgent($audit, $this->reviews[$audit->package], true);
        }

        $this->output->newLine();
    }

    /**
     * @param  array<int, LockDiscrepancy>  $discrepancies
     */
    public function json(array $discrepancies): int
    {
        $reviews = $this->reviews;
        $renderer = $this->renderer;
        $agentReviews = $this->agentReviews;

        $this->output->write(Json::encode([
            'total' => $this->report->total(),
            'covered' => $this->report->coveredCount(),
            'percentage' => $this->report->percentage(),
            'counts' => $this->report->counts(),
            'lock_discrepancies' => array_map(static fn (LockDiscrepancy $discrepancy): array => $discrepancy->toArray(), $discrepancies),
            'unaudited' => array_values(array_map(static function (PackageAudit $audit) use ($reviews, $renderer, $agentReviews): array {
                $review = $reviews[$audit->package];

                return [
                    'package' => $audit->package,
                    'version' => $audit->version,
                    'status' => $audit->status->value,
                    'state' => $audit->state->value,
                    'from' => $audit->from,
                    'dev' => $audit->dev,
                    'files' => $audit->files,
                    'files_to_review' => $review->files,
                    'scope' => $review->scope->value,
                    'delta' => $review->delta instanceof Delta ? $renderer->toArray($review->delta) : null,
                    'agent' => ($agentReviews[$audit->package] ?? null)?->toArray(),
                ];
            }, $this->failing)),
        ]), false, OutputInterface::OUTPUT_RAW);

        return $this->verdict($discrepancies);
    }

    /**
     * @param  array<int, LockDiscrepancy>  $discrepancies
     */
    public function render(array $discrepancies, bool $agentAsked): int
    {
        $this->output->newLine();

        foreach ($discrepancies as $discrepancy) {
            $this->components->error($discrepancy->message());
        }

        if ($discrepancies !== []) {
            $this->components->error('The installed tree does not match composer.lock. Run [composer install] to install what composer.lock holds.');
        }

        if ($this->failing === []) {
            $this->components->info(sprintf('All [%d] packages are covered.', $this->report->total()));

            return $this->verdict($discrepancies);
        }

        $this->renderFailing($agentAsked);
        $this->renderAudited();
        $this->renderVerdict($agentAsked);

        return self::FAILURE;
    }

    public function renderReport(bool $agentAsked): void
    {
        $this->output->newLine();

        if ($this->failing === []) {
            $this->components->info(sprintf('All [%d] packages are covered.', $this->report->total()));

            return;
        }

        $this->renderFailing($agentAsked);
        $this->renderAudited();
    }

    private static function review(AuditProject $auditor, PackageAudit $audit): PackageReview
    {
        if ($audit->status === AuditStatus::Unknown) {
            return PackageReview::unreadable();
        }

        if ($audit->pending()) {
            return self::pendingReview($auditor, $audit);
        }

        $from = $auditor->trustFile->grantFor($audit->package)?->version;

        if ($from === null) {
            return PackageReview::ofWholePackage($audit->files);
        }

        try {
            $delta = ResolveDelta::forProject($auditor->project)->resolveInstalled($audit->package, $from);
        } catch (VetException) {
            return PackageReview::ofWholePackage($audit->files);
        }

        return PackageReview::ofDelta($delta);
    }

    private static function pendingReview(AuditProject $auditor, PackageAudit $audit): PackageReview
    {
        $operation = $auditor->plan()->of($audit->package);
        $delta = $operation instanceof ComposerOperation ? $auditor->incomingTree($audit, $operation) : null;

        return $delta instanceof Delta
            ? PackageReview::ofDelta($delta)
            : PackageReview::ofWholePackage($audit->files);
    }

    private function overBudgetTip(AgentBatch $batch): string
    {
        return sprintf(
            'Hand a few packages at a time to your coding agent with [vet <package> --agent]. One run reads [%d] package(s) or %s, and this batch holds [%d] package(s) and %s.',
            AgentBatch::MAX_PROMPTS,
            Bytes::human(AgentBatch::MAX_BYTES),
            $batch->count(),
            Bytes::human($batch->bytes()),
        );
    }

    private function renderFailing(bool $agentAsked): void
    {
        $this->output->writeln(sprintf('  <options=bold>to review</> <fg=gray>(%d, worst first)</>', count($this->failing)));
        $this->output->newLine();

        $endsWithDelta = false;

        foreach ($this->failing as $audit) {
            $review = $this->reviews[$audit->package];

            $this->renderRow($audit, $review);
            $this->renderAgent($audit, $review, $agentAsked);

            $endsWithDelta = $review->delta instanceof Delta && $this->readsDelta($audit->package, $agentAsked);

            if ($endsWithDelta) {
                $this->output->writeln(Gutter::Package->blank());
                $this->renderer->buckets($review->delta, BucketType::inReviewOrder());
            }
        }

        if (! $endsWithDelta) {
            $this->output->newLine();
        }
    }

    private function renderRow(PackageAudit $audit, PackageReview $review): void
    {
        $this->components->twoColumnDetail(
            sprintf(
                '<fg=%s>%s</> <fg=gray>%s</>%s  <fg=gray>%s</>',
                $audit->status->color(),
                $audit->package,
                $audit->versions(),
                $audit->dev ? ' <fg=gray>(dev)</>' : '',
                $audit->reason(),
            ),
            $audit->status === AuditStatus::Unknown
                ? '<fg=red>bytes not readable</>'
                : sprintf(
                    '<fg=gray>%d files (%s)  ·  %s</>',
                    $review->files,
                    $review->label(),
                    Bytes::human($audit->bytes),
                ),
        );
    }

    private function readsDelta(string $package, bool $agentAsked): bool
    {
        if (! $agentAsked || $this->output->isVerbose()) {
            return true;
        }

        return ($this->agentReviews[$package] ?? null)?->verdict !== AgentVerdict::Clear;
    }

    private function renderAgent(PackageAudit $audit, PackageReview $review, bool $agentAsked): void
    {
        $agentReview = $this->agentReviews[$audit->package] ?? null;

        if ($agentReview instanceof AgentReview) {
            $this->agentRenderer->verdict($agentReview);

            return;
        }

        if ($agentAsked) {
            $review->delta instanceof Delta
                ? $this->agentRenderer->noChange()
                : $this->agentRenderer->noEarlierTree();
        }
    }

    private function renderAudited(): void
    {
        $this->components->twoColumnDetail(
            '<options=bold>audited</>',
            sprintf(
                '%d / %d  <fg=gray>(%s%%)</>',
                $this->report->coveredCount(),
                $this->report->total(),
                $this->report->percentage(),
            ),
        );
        $this->output->newLine();
    }

    private function renderVerdict(bool $agentAsked): void
    {
        $this->components->error($this->readsEveryChange()
            ? sprintf(
                '[%d] package(s) are not covered. Read every change with [%s]. Run [vet] in a terminal to record the ones that you trust.',
                count($this->failing),
                $this->invitation->command,
            )
            : sprintf('[%d] package(s) are not covered. Run [vet] in a terminal to record the ones that you trust.', count($this->failing)));

        if (! $agentAsked) {
            $this->components->tip($this->tip());
        }
    }

    private function readsEveryChange(): bool
    {
        return ! $this->output->isVerbose() && $this->holdsDelta();
    }

    private function tip(): string
    {
        if (! $this->holdsDelta()) {
            return 'No earlier tree exists to compare these bytes to. Hand one whole package to your coding agent with [vet <package> --agent].';
        }

        $batch = $this->agentBatch();

        return $batch->fitsOneRun()
            ? 'Hand every change to your coding agent with [vet --agent].'
            : $this->overBudgetTip($batch);
    }

    private function holdsDelta(): bool
    {
        return array_any($this->reviews, fn (PackageReview $review): bool => $review->delta instanceof Delta);
    }

    /**
     * @param  array<int, LockDiscrepancy>  $discrepancies
     */
    private function verdict(array $discrepancies): int
    {
        return $this->failing === [] && $discrepancies === [] ? self::SUCCESS : self::FAILURE;
    }
}
