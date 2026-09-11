<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AuditStatus;
use App\Enums\Gutter;
use App\Enums\PatchExtent;
use App\Exceptions\VetException;
use App\Support\ControlSafeComponents;
use App\Support\Invitation;
use App\ValueObjects\AgentBatch;
use App\ValueObjects\AgentReview;
use App\ValueObjects\AuditReport;
use App\ValueObjects\ComposerOperation;
use App\ValueObjects\Delta;
use App\ValueObjects\LockDiscrepancy;
use App\ValueObjects\PackageAudit;
use App\ValueObjects\PackageReview;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Str;
use Symfony\Component\Console\Formatter\OutputFormatter;

final readonly class RenderProjectAudit
{
    private const int SUCCESS = 0;

    private const int FAILURE = 1;

    private const int MAX_DELTAS = 10;

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
        $this->renderer = new RenderDelta($output, $invitation, Gutter::Package, PatchExtent::Abridged);
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

    /**
     * @return array<string, PackageAudit>
     */
    public function failing(): array
    {
        return $this->failing;
    }

    public function renderAgentReviews(): void
    {
        foreach ($this->failing as $audit) {
            $this->renderRow($audit, $this->reviews[$audit->package]);
            $this->renderAgent($audit, $this->reviews[$audit->package]);
            $this->output->newLine();
        }
    }

    /**
     * @param  array<int, LockDiscrepancy>  $discrepancies
     */
    public function render(array $discrepancies): int
    {
        $this->output->newLine();

        foreach ($discrepancies as $discrepancy) {
            $this->components->error($discrepancy->message());
        }

        if ($discrepancies !== []) {
            $this->components->error('The installed tree does not match composer.lock. Run [composer install] to install what composer.lock holds.');
        }

        if ($this->failing === []) {
            $this->components->info(sprintf('All [%d] packages are trusted.', $this->report->total()));

            return $this->verdict($discrepancies);
        }

        $this->renderFailing();
        $this->renderSummary();
        $this->renderVerdict();

        return self::FAILURE;
    }

    public function renderReport(): void
    {
        $this->output->newLine();

        if ($this->failing === []) {
            $this->components->info(sprintf('All [%d] packages are trusted.', $this->report->total()));

            return;
        }

        $this->renderFailing();
        $this->renderSummary();
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

    private function renderFailing(): void
    {
        $this->output->writeln(sprintf('  <options=bold>to review</> <fg=gray>(%d)</>', count($this->failing)));
        $this->output->newLine();

        $collapsed = $this->collapsesDeltas();
        $endsWithBlank = false;

        foreach ($this->failing as $audit) {
            $review = $this->reviews[$audit->package];

            $this->renderRow($audit, $review);

            $endsWithBlank = $review->delta instanceof Delta && ! $collapsed;

            if ($endsWithBlank) {
                $this->output->writeln(Gutter::Package->blank());
                $this->renderer->buckets($review->delta);
            }
        }

        if (! $endsWithBlank) {
            $this->output->newLine();
        }

        if ($collapsed) {
            $this->output->writeln(sprintf('  <fg=gray>%s</>', OutputFormatter::escape('Read the changes of one package with [vet <package>].')));
            $this->output->newLine();
        }
    }

    private function renderRow(PackageAudit $audit, PackageReview $review): void
    {
        $note = $audit->note();

        $this->components->twoColumnDetail(
            sprintf(
                '<fg=%s>%s</> <fg=gray>%s</>%s%s',
                $audit->status->color(),
                $audit->package,
                $audit->versions(),
                $audit->dev ? ' <fg=gray>(dev)</>' : '',
                $note === '' ? '' : sprintf('  <fg=%s>%s</>', $audit->status === AuditStatus::Ungranted ? 'gray' : 'red', $note),
            ),
            sprintf(
                '<fg=%s>%s</>',
                $audit->status === AuditStatus::Unknown ? 'red' : 'gray',
                $review->label(),
            ),
        );
    }

    private function renderAgent(PackageAudit $audit, PackageReview $review): void
    {
        $agentReview = $this->agentReviews[$audit->package] ?? null;

        if ($agentReview instanceof AgentReview) {
            $this->agentRenderer->verdict($agentReview);

            return;
        }

        $review->delta instanceof Delta
            ? $this->agentRenderer->noChange()
            : $this->agentRenderer->noEarlierTree();
    }

    private function renderSummary(): void
    {
        $this->output->writeln(sprintf(
            '  <options=bold>Packages:</> <fg=yellow;options=bold>%d to review</><fg=gray>,</> <fg=green;options=bold>%d trusted</>',
            count($this->failing),
            $this->report->coveredCount(),
        ));
        $this->output->newLine();
    }

    private function renderVerdict(): void
    {
        $count = count($this->failing);
        $subject = sprintf('[%d] %s %s not trusted.', $count, Str::plural('package', $count), $count === 1 ? 'is' : 'are');

        $this->components->error($this->readsEveryChange()
            ? sprintf(
                '%s Read every change with [%s]. Run [vet] in a terminal to pick the ones that you trust.',
                $subject,
                $this->invitation->command,
            )
            : sprintf('%s Run [vet] in a terminal to pick the ones that you trust.', $subject));

        $this->components->tip($this->tip());
    }

    private function readsEveryChange(): bool
    {
        return ! $this->output->isVerbose() && $this->holdsDelta();
    }

    private function tip(): string
    {
        if (! $this->holdsDelta()) {
            return 'Vet holds no earlier version to compare these packages to. Run [vet] in a terminal to hand the whole packages to your coding agent.';
        }

        return 'Run [vet] in a terminal to hand every change to your coding agent.';
    }

    private function collapsesDeltas(): bool
    {
        return count($this->failing) > self::MAX_DELTAS && ! $this->output->isVerbose() && $this->holdsDelta();
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
