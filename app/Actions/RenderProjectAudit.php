<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AgentVerdict;
use App\Enums\AuditStatus;
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
use App\ValueObjects\Project;
use Illuminate\Console\OutputStyle;
use Symfony\Component\Console\Output\OutputInterface;

final class RenderProjectAudit
{
    private const int SUCCESS = 0;

    private const int FAILURE = 1;

    private readonly ControlSafeComponents $components;

    private readonly RenderDelta $renderer;

    private readonly RenderAgentReview $agentRenderer;

    /**
     * @var array<string, PackageAudit>
     */
    private array $failing = [];

    /**
     * @var array<string, PackageReview>
     */
    private array $reviews = [];

    /**
     * @var array<string, AgentReview>
     */
    private array $agentReviews = [];

    public function __construct(
        private readonly OutputStyle $output,
        private readonly Project $project,
        private readonly AuditProject $auditor,
        private readonly AuditReport $report,
        private readonly Invitation $invitation,
    ) {
        $this->components = new ControlSafeComponents($output);
        $this->renderer = new RenderDelta($output, $invitation);
        $this->agentRenderer = new RenderAgentReview($output);
    }

    /**
     * @return array<string, ?Delta>
     */
    public function deltas(): array
    {
        if ($this->reviews === []) {
            $this->collectReviews();
        }

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

    /**
     * @param  array<string, AgentReview>  $agentReviews
     */
    public function withAgentReviews(array $agentReviews): void
    {
        $this->agentReviews = $agentReviews;
    }

    public function renderAgentReviews(): void
    {
        foreach ($this->failing as $audit) {
            $this->renderRow($audit, $this->reviews[$audit->package]);
            $this->renderAgent($audit, $this->reviews[$audit->package]->delta, true);
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

        $this->renderBaselineWarning();

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

        $this->renderBaselineWarning();

        if ($this->failing === []) {
            $this->components->info(sprintf('All [%d] packages are covered.', $this->report->total()));

            return;
        }

        $this->renderFailing($agentAsked);
        $this->renderAudited();
    }

    private static function statusWeight(AuditStatus $status): int
    {
        return match ($status) {
            AuditStatus::Unknown => 0,
            AuditStatus::Changed => 1,
            AuditStatus::Ungranted => 2,
            AuditStatus::Covered => 3,
        };
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

    private function collectReviews(): void
    {
        $this->failing = $this->report->failing();

        foreach ($this->failing as $audit) {
            $this->reviews[$audit->package] = $this->review($audit);
        }

        $reviews = $this->reviews;

        uasort($this->failing, static fn (PackageAudit $a, PackageAudit $b): int => [
            self::statusWeight($a->status),
            $reviews[$b->package]->files,
            $a->package,
        ] <=> [
            self::statusWeight($b->status),
            $reviews[$a->package]->files,
            $b->package,
        ]);
    }

    private function renderBaselineWarning(): void
    {
        if ($this->auditor->trustFile->exists()) {
            return;
        }

        $this->components->warn(sprintf(
            'No trust file yet. [vet --fresh] records every installed package in [%s].',
            $this->project->relativePath($this->auditor->trustFile->path),
        ));
    }

    private function renderFailing(bool $agentAsked): void
    {
        $this->output->writeln(sprintf('  <options=bold>to review</> <fg=gray>(%d, worst first)</>', count($this->failing)));
        $this->output->newLine();

        $endsWithDelta = false;

        foreach ($this->failing as $audit) {
            $review = $this->reviews[$audit->package];

            $this->renderRow($audit, $review);
            $this->renderAgent($audit, $review->delta, $agentAsked);

            $endsWithDelta = $review->delta instanceof Delta && $this->readsDelta($audit->package, $agentAsked);

            if ($endsWithDelta) {
                $this->output->newLine();
                $this->renderer->buckets($review->delta);
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
                $this->statusColor($audit->status),
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

    private function renderAgent(PackageAudit $audit, ?Delta $delta, bool $agentAsked): void
    {
        $agentReview = $this->agentReviews[$audit->package] ?? null;

        if ($agentReview instanceof AgentReview) {
            $this->agentRenderer->verdict($agentReview);

            return;
        }

        if ($agentAsked) {
            $delta instanceof Delta
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
        if (! $this->auditor->trustFile->exists()) {
            return 'No earlier tree exists to compare these bytes to. Record this one as your baseline with [vet --fresh], thus the next [composer update] shows a delta.';
        }

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
        foreach ($this->reviews as $review) {
            if ($review->delta instanceof Delta) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, LockDiscrepancy>  $discrepancies
     */
    private function verdict(array $discrepancies): int
    {
        return $this->failing === [] && $discrepancies === [] ? self::SUCCESS : self::FAILURE;
    }

    private function statusColor(AuditStatus $status): string
    {
        return match ($status) {
            AuditStatus::Unknown, AuditStatus::Changed => 'red',
            AuditStatus::Ungranted, AuditStatus::Covered => 'yellow',
        };
    }

    private function review(PackageAudit $audit): PackageReview
    {
        if ($audit->status === AuditStatus::Unknown) {
            return PackageReview::unreadable();
        }

        if ($audit->pending()) {
            return $this->pendingReview($audit);
        }

        $from = $this->auditor->trustFile->grantFor($audit->package)?->version;

        if ($from === null) {
            return PackageReview::ofWholePackage($audit->files);
        }

        try {
            $delta = ResolveDelta::forProject($this->project)->resolve(
                package: $audit->package,
                from: $from,
            );
        } catch (VetException) {
            return PackageReview::ofWholePackage($audit->files);
        }

        return PackageReview::ofDelta($delta);
    }

    private function pendingReview(PackageAudit $audit): PackageReview
    {
        $delta = $this->incomingDelta($audit);

        return $delta instanceof Delta
            ? PackageReview::ofDelta($delta)
            : PackageReview::ofWholePackage($audit->files);
    }

    private function incomingDelta(PackageAudit $audit): ?Delta
    {
        $operation = $this->auditor->plan()->of($audit->package);

        if (! $operation instanceof ComposerOperation) {
            return null;
        }

        $installed = $this->auditor->installed();

        try {
            return ResolveDelta::forProject($this->project)->incoming(
                target: $this->auditor->target($operation, $audit->version, $audit->dev),
                installed: $installed->has($audit->package) ? $installed->get($audit->package) : null,
            );
        } catch (VetException) {
            return null;
        }
    }
}
