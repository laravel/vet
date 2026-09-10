<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AgentVerdict;
use App\Enums\AuditScreen;
use App\Enums\AuditStatus;
use App\Exceptions\VetException;
use App\Support\Bytes;
use App\Support\ControlSafeComponents;
use App\Support\Invitation;
use App\Support\Json;
use App\ValueObjects\AgentReview;
use App\ValueObjects\AuditReport;
use App\ValueObjects\ComposerOperation;
use App\ValueObjects\Delta;
use App\ValueObjects\PackageAudit;
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
     * @var array<string, array{files: int, scope: string, delta: ?Delta}>
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
        private readonly bool $useCache,
        private readonly AuditScreen $screen,
    ) {
        $this->components = new ControlSafeComponents($output);
        $this->renderer = new RenderDelta($output);
        $this->agentRenderer = new RenderAgentReview($output);
    }

    /**
     * @return array<string, ?Delta>
     */
    public function deltas(bool $agentAsked): array
    {
        $this->failing = $this->report->failing();

        foreach ($this->failing as $audit) {
            $this->reviews[$audit->package] = $this->review($audit);
        }

        $reviews = $this->reviews;

        uasort($this->failing, static fn (PackageAudit $a, PackageAudit $b): int => [
            self::statusWeight($a->status),
            $reviews[$b->package]['files'],
            $a->package,
        ] <=> [
            self::statusWeight($b->status),
            $reviews[$a->package]['files'],
            $b->package,
        ]);

        $deltas = [];

        foreach ($this->reviews as $package => $review) {
            $deltas[$package] = $review['delta'] instanceof Delta || ! $agentAsked
                ? $review['delta']
                : $this->auditor->wholeTree($this->failing[$package]);
        }

        return $deltas;
    }

    /**
     * @param  array<string, AgentReview>  $agentReviews
     */
    public function withAgentReviews(array $agentReviews): void
    {
        $this->agentReviews = $agentReviews;
    }

    /**
     * @param  array<int, string>  $discrepancies
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
            'lock_discrepancies' => $discrepancies,
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
                    'files_to_review' => $review['files'],
                    'scope' => $review['scope'],
                    'delta' => $review['delta'] instanceof Delta ? $renderer->toArray($review['delta']) : null,
                    'agent' => ($agentReviews[$audit->package] ?? null)?->toArray(),
                ];
            }, $this->failing)),
        ]), false, OutputInterface::OUTPUT_RAW);

        return $this->verdict($discrepancies);
    }

    /**
     * @param  array<int, string>  $discrepancies
     */
    public function render(array $discrepancies, bool $agentAsked): int
    {
        $this->output->newLine();

        foreach ($discrepancies as $discrepancy) {
            $this->components->error($discrepancy);
        }

        if ($discrepancies !== []) {
            $this->components->error('The installed tree does not match composer.lock.');
        }

        if (! $this->auditor->trustFile->exists()) {
            $this->components->warn(sprintf(
                'No trust file yet. [vet trust] records every installed package in [%s].',
                $this->relative($this->auditor->trustFile->path),
            ));
        }

        if ($this->failing === []) {
            $this->components->info($this->screen->allCovered($this->report->total()));

            return $this->verdict($discrepancies);
        }

        $this->renderFailing($agentAsked);
        $this->renderVerdict($agentAsked);

        return self::FAILURE;
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

    private function renderFailing(bool $agentAsked): void
    {
        $this->output->writeln(sprintf('  <options=bold>to review</> <fg=gray>(%d, worst first)</>', count($this->failing)));
        $this->output->newLine();

        $endsWithDelta = false;

        foreach ($this->failing as $audit) {
            $review = $this->reviews[$audit->package];

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
                        $review['files'],
                        $review['scope'],
                        Bytes::human($audit->bytes),
                    ),
            );

            $this->renderAgent($audit, $review['delta'], $agentAsked);

            $endsWithDelta = $review['delta'] instanceof Delta && $this->readsDelta($audit->package, $agentAsked);

            if ($endsWithDelta) {
                $this->output->newLine();
                $this->renderer->buckets($review['delta']);
            }
        }

        if (! $endsWithDelta) {
            $this->output->newLine();
        }
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

    private function renderVerdict(bool $agentAsked): void
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

        $this->components->error($this->output->isVerbose()
            ? sprintf('[%d] package(s) are not covered. %s', count($this->failing), $this->screen->nextStep())
            : sprintf(
                '[%d] package(s) are not covered. Read every change with [%s]. %s',
                count($this->failing),
                Invitation::verbose($this->screen->command().' -v'),
                $this->screen->nextStep(),
            ));

        if (! $agentAsked) {
            $this->components->info(sprintf(
                'Hand every change to your coding agent with [%s --agent].',
                $this->screen->command(),
            ));
        }

        $notice = $this->screen->pendingNotice();

        if ($notice !== null && $this->holdsPending()) {
            $this->components->warn($notice);
        }
    }

    /**
     * @param  array<int, string>  $discrepancies
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

    private function holdsPending(): bool
    {
        foreach ($this->failing as $audit) {
            if ($audit->pending()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{files: int, scope: string, delta: ?Delta}
     */
    private function review(PackageAudit $audit): array
    {
        if ($audit->status === AuditStatus::Unknown) {
            return ['files' => 0, 'scope' => 'not readable', 'delta' => null];
        }

        if ($audit->pending()) {
            return $this->pendingReview($audit);
        }

        $from = $this->auditor->trustFile->grantFor($audit->package)?->version;

        if ($from === null) {
            return $this->wholePackage($audit);
        }

        try {
            $delta = ResolveDelta::forProject($this->project)->resolve(
                package: $audit->package,
                from: $from,
                useCache: $this->useCache,
            );
        } catch (VetException) {
            return $this->wholePackage($audit);
        }

        return [
            'files' => count($delta->changes()),
            'scope' => $this->scopeOf($delta),
            'delta' => $delta,
        ];
    }

    /**
     * @return array{files: int, scope: string, delta: ?Delta}
     */
    private function pendingReview(PackageAudit $audit): array
    {
        $delta = $this->incomingDelta($audit);

        return $delta instanceof Delta
            ? [
                'files' => count($delta->changes()),
                'scope' => $this->scopeOf($delta),
                'delta' => $delta,
            ]
            : $this->wholePackage($audit);
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
                useCache: $this->useCache,
            );
        } catch (VetException) {
            return null;
        }
    }

    private function scopeOf(Delta $delta): string
    {
        return $delta->comparesPublishedToInstalled()
            ? sprintf('delta from the published [%s]', $delta->from)
            : sprintf('delta from [%s]', $delta->from);
    }

    /**
     * @return array{files: int, scope: string, delta: null}
     */
    private function wholePackage(PackageAudit $audit): array
    {
        return ['files' => $audit->files, 'scope' => 'whole package', 'delta' => null];
    }

    private function relative(string $path): string
    {
        $root = $this->project->rootPath;

        return str_starts_with($path, $root.'/') ? mb_substr($path, mb_strlen($root) + 1) : $path;
    }
}
