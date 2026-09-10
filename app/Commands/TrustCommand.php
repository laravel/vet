<?php

declare(strict_types=1);

namespace App\Commands;

use App\Actions\AuditProject;
use App\Actions\RenderAgentReview;
use App\Actions\RenderDelta;
use App\Actions\ResolveDelta;
use App\Enums\AuditStatus;
use App\Exceptions\FailureException;
use App\Exceptions\VetException;
use App\Support\Invitation;
use App\ValueObjects\AgentReview;
use App\ValueObjects\ComposerOperation;
use App\ValueObjects\Delta;
use App\ValueObjects\Grant;
use App\ValueObjects\PackageAudit;
use App\ValueObjects\Project;
use App\ValueObjects\TreeHash;
use Illuminate\Support\Collection;

use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

final class TrustCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'trust
        {packages?* : Trust these packages, as vendor/name}
        {--all : Trust every package that vendor/ holds today}
        {--agent : Hand each delta to your coding agent, and show the verdict it writes}
        {--from= : Show the delta from this version rather than the trusted one}
        {--notes= : A note to record alongside the entry}
        {--path= : The project directory to audit (defaults to the current one)}';

    /**
     * @var string
     */
    protected $description = 'Record the bytes that you trust, on disk and on the way in';

    public function handle(): int
    {
        $path = $this->option('path');
        assert($path === null || is_string($path));

        try {
            $project = Project::locate($path ?? (string) getcwd());
            $auditor = AuditProject::forProject($project);
        } catch (VetException $vetException) {
            $this->components->error($vetException->getMessage());

            return self::FAILURE;
        }

        $packages = $this->packages();
        $all = $this->option('all') === true;

        if ($packages !== [] && $all) {
            $this->components->error('The [--all] option takes no package. Run [vet trust --all] or [vet trust <package>].');

            return self::FAILURE;
        }

        if ($packages !== []) {
            return $this->trustPackages($project, $auditor, $packages);
        }

        if ($this->option('from') !== null) {
            $this->components->error('The [--from] option needs one package. Run [vet trust <package> --from=<version>].');

            return self::FAILURE;
        }

        if ($all) {
            return $this->trustInstalled($project, $auditor);
        }

        if (! $this->asksQuestions()) {
            $this->components->error('Name one package, or trust every package. Run [vet trust <package>] or [vet trust --all].');

            return self::FAILURE;
        }

        return $this->pickPackages($project, $auditor);
    }

    private function asksQuestions(): bool
    {
        return $this->input->isInteractive()
            && ((defined('STDIN') && stream_isatty(STDIN)) || $this->laravel->runningUnitTests());
    }

    private function trustInstalled(Project $project, AuditProject $auditor): int
    {
        try {
            $report = $auditor->report();
        } catch (VetException $vetException) {
            $this->components->error($vetException->getMessage());

            return self::FAILURE;
        }

        $targets = $report->failing();

        $this->newLine();

        if ($targets === []) {
            $this->components->info(sprintf('All [%d] packages are already covered.', $report->total()));

            return self::SUCCESS;
        }

        [$installed, $incoming] = (new Collection($targets))
            ->partition(static fn (PackageAudit $audit): bool => ! $audit->pending());

        if ($installed->isNotEmpty()) {
            $this->renderTargets($installed->all(), 'to trust');
        }

        $created = ! $auditor->trustFile->exists();

        try {
            foreach ($installed as $audit) {
                $auditor->trustFile->record($this->grantOf($audit));
            }

            if ($installed->isNotEmpty()) {
                $auditor->trustFile->save();
            }
        } catch (VetException $vetException) {
            $this->components->error($vetException->getMessage());

            return self::FAILURE;
        }

        if ($installed->isNotEmpty()) {
            $this->components->info($created
                ? sprintf(
                    'Trusted [%d] package(s), and wrote [%s].',
                    $installed->count(),
                    $project->relativePath($auditor->trustFile->path),
                )
                : sprintf('Trusted [%d] package(s).', $installed->count()));
        }

        if ($incoming->isNotEmpty()) {
            $this->reportIncoming($incoming);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  Collection<string, PackageAudit>  $incoming
     */
    private function reportIncoming(Collection $incoming): void
    {
        $this->renderTargets($incoming->all(), 'to read first');

        $this->components->error(sprintf(
            'composer would write [%d] package(s) that vendor/ does not hold. Read them with [vet trust], or run [composer install] first.',
            $incoming->count(),
        ));
    }

    private function pickPackages(Project $project, AuditProject $auditor): int
    {
        try {
            $report = $auditor->report();
        } catch (VetException $vetException) {
            $this->components->error($vetException->getMessage());

            return self::FAILURE;
        }

        $targets = $report->failing();

        $this->newLine();

        if ($targets === []) {
            $this->components->info(sprintf('All [%d] packages are already covered.', $report->total()));

            return self::SUCCESS;
        }

        [$readable, $unreadable] = (new Collection($targets))
            ->partition(static fn (PackageAudit $audit): bool => $audit->hash instanceof TreeHash);

        foreach ($unreadable as $audit) {
            $this->components->error(sprintf('[%s] stays unrecorded: %s', $audit->package, $audit->reason()));
        }

        if ($readable->isEmpty()) {
            return self::FAILURE;
        }

        try {
            $deltas = $this->deltasOf($project, $auditor, $readable);
            $reviews = $this->agentReviews($deltas);
        } catch (VetException $vetException) {
            $this->components->error($vetException->getMessage());

            return self::FAILURE;
        }

        $picked = new Collection(multiselect(
            label: 'Which packages do you trust?',
            options: $this->choices($readable, $reviews),
            scroll: 10,
            hint: 'Press the space bar to pick a package, and enter to read the ones that you picked.',
        ));

        $recorded = $this->readAndRecord($project, $auditor, $readable->filter(
            static fn (PackageAudit $audit): bool => $picked->contains($audit->package),
        ), $deltas, $reviews);

        if ($recorded->isEmpty()) {
            $this->newLine();
            $this->components->info('Recorded nothing.');

            return $unreadable->isEmpty() ? self::SUCCESS : self::FAILURE;
        }

        try {
            $auditor->trustFile->save();
        } catch (VetException $vetException) {
            $this->components->error($vetException->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->announceRecorded($recorded);

        if ($this->holdsPending($recorded)) {
            $this->components->info('Run [composer install] to write those bytes to vendor/.');
        }

        return $unreadable->isEmpty() ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  Collection<string, PackageAudit>  $picked
     * @param  array<string, ?Delta>  $deltas
     * @param  array<string, AgentReview>  $reviews
     * @return Collection<string, PackageAudit>
     */
    private function readAndRecord(
        Project $project,
        AuditProject $auditor,
        Collection $picked,
        array $deltas,
        array $reviews,
    ): Collection {
        /** @var Collection<string, PackageAudit> $recorded */
        $recorded = new Collection;

        foreach ($picked as $audit) {
            $this->renderSubject($audit);

            $review = $reviews[$audit->package] ?? null;

            if ($review instanceof AgentReview) {
                $this->newLine();
                (new RenderAgentReview($this->output))->verdict($review);
            }

            $delta = array_key_exists($audit->package, $deltas)
                ? $deltas[$audit->package]
                : $this->delta($auditor, $project, $audit);

            if ($delta instanceof Delta) {
                (new RenderDelta($this->output, Invitation::toReadTheInstalledTree()))->report($delta);
            } else {
                $this->newLine();
                $this->components->warn(sprintf('Review the tree at [%s] before you trust it.', $audit->path ?? ''));
            }

            $answer = select(
                label: sprintf('Do you trust [%s] [%s]?', $audit->package, $audit->version),
                options: ['yes' => 'Yes', 'no' => 'No', 'notes' => 'Yes, and record a note'],
                default: 'yes',
            );

            if ($answer === 'no') {
                continue;
            }

            $auditor->trustFile->record($answer === 'notes'
                ? $this->grantWithNotes($audit, text(label: 'The note that vet records', required: true))
                : $this->grantOf($audit));

            $recorded->put($audit->package, $audit);
        }

        return $recorded;
    }

    /**
     * @param  Collection<string, PackageAudit>  $targets
     * @return array<string, ?Delta>
     */
    private function deltasOf(Project $project, AuditProject $auditor, Collection $targets): array
    {
        if ($this->option('agent') !== true) {
            return [];
        }

        $deltas = [];

        foreach ($targets as $audit) {
            $delta = $this->delta($auditor, $project, $audit);

            $deltas[$audit->package] = $delta instanceof Delta ? $delta : $auditor->wholeTree($audit);
        }

        return $deltas;
    }

    /**
     * @param  Collection<string, PackageAudit>  $targets
     * @param  array<string, AgentReview>  $reviews
     * @return array<string, string>
     */
    private function choices(Collection $targets, array $reviews): array
    {
        $choices = [];

        foreach ($targets as $audit) {
            $parts = [
                $audit->package,
                $audit->versions(),
                sprintf('%d files', $audit->files),
                $audit->pending() ? 'incoming' : 'installed',
            ];

            if ($audit->dev) {
                $parts[] = 'dev';
            }

            $review = $reviews[$audit->package] ?? null;

            if ($review instanceof AgentReview) {
                $parts[] = sprintf('agent: %s', $review->verdict->label());
            }

            $choices[$audit->package] = implode('  ', $parts);
        }

        return $choices;
    }

    /**
     * @param  array<string, PackageAudit>  $targets
     */
    private function renderTargets(array $targets, string $heading): void
    {
        $this->line(sprintf('  <options=bold>%s</> <fg=gray>(%d)</>', $heading, count($targets)));
        $this->newLine();

        foreach ($targets as $audit) {
            $this->components->twoColumnDetail(
                sprintf(
                    '<fg=%s>%s</> <fg=gray>%s</>%s',
                    $audit->status === AuditStatus::Ungranted ? 'yellow' : 'red',
                    $audit->package,
                    $audit->versions(),
                    $audit->dev ? ' <fg=gray>(dev)</>' : '',
                ),
                sprintf('<fg=gray>%s</>', $audit->reason()),
            );
        }

        $this->newLine();
    }

    /**
     * @param  array<int, string>  $names
     */
    private function trustPackages(Project $project, AuditProject $auditor, array $names): int
    {
        if (count($names) > 1 && $this->option('from') !== null) {
            $this->components->error('The [--from] option needs one package. Run [vet trust <package> --from=<version>].');

            return self::FAILURE;
        }

        /** @var Collection<string, PackageAudit> $recorded */
        $recorded = new Collection;

        foreach ($names as $name) {
            try {
                $audit = $auditor->auditOfName($name);
            } catch (VetException $vetException) {
                $this->components->error($vetException->getMessage());

                return self::FAILURE;
            }

            if ($audit->status === AuditStatus::Unknown) {
                $this->newLine();
                $this->components->error($audit->cause ?? 'Vet cannot read those bytes.');

                return self::FAILURE;
            }

            if ($audit->status === AuditStatus::Covered) {
                $this->newLine();

                if ($this->option('notes') === null) {
                    $this->components->info(sprintf(
                        '[%s] [%s] is already covered (%s).',
                        $audit->package,
                        $audit->version,
                        $audit->reason(),
                    ));

                    continue;
                }

                $auditor->trustFile->record($this->grantOf($audit));

                $recorded[$audit->package] = $audit;

                continue;
            }

            $this->renderSubject($audit);

            $delta = $this->delta($auditor, $project, $audit);

            if ($delta instanceof Delta) {
                (new RenderDelta($this->output, Invitation::toReadTheInstalledTree()))->report($delta);
            } else {
                $this->newLine();
                $this->components->warn(sprintf('Review the tree at [%s] before you trust it.', $audit->path ?? ''));
            }

            $grant = $this->grantOf($audit);

            $auditor->trustFile->record($grant);

            $recorded->put($audit->package, $audit);
        }

        if ($recorded->isEmpty()) {
            return self::SUCCESS;
        }

        try {
            $auditor->trustFile->save();
        } catch (VetException $vetException) {
            $this->components->error($vetException->getMessage());

            return self::FAILURE;
        }

        $this->announceRecorded($recorded);

        if ($this->holdsPending($recorded)) {
            $this->components->info('Run [composer install] to write those bytes to vendor/.');
        }

        return self::SUCCESS;
    }

    /**
     * @param  Collection<string, PackageAudit>  $recorded
     */
    private function announceRecorded(Collection $recorded): void
    {
        if ($recorded->count() !== 1) {
            $this->components->info(sprintf('Recorded [%d] package(s).', $recorded->count()));

            return;
        }

        $audit = $recorded->sole();

        $this->components->info(sprintf(
            'Recorded [%s] [%s] at [%s].',
            $audit->package,
            $audit->version,
            $audit->hash instanceof TreeHash ? $audit->hash->short() : '',
        ));
    }

    private function grantOf(PackageAudit $audit): Grant
    {
        $notes = $this->option('notes');
        assert($notes === null || is_string($notes));

        return new Grant(
            package: $audit->package,
            version: $audit->version,
            hash: $this->hashOf($audit),
            dev: $audit->dev,
            notes: $notes ?? $audit->grant?->notes,
        );
    }

    private function grantWithNotes(PackageAudit $audit, string $notes): Grant
    {
        return new Grant(
            package: $audit->package,
            version: $audit->version,
            hash: $this->hashOf($audit),
            dev: $audit->dev,
            notes: $notes,
        );
    }

    private function hashOf(PackageAudit $audit): TreeHash
    {
        if (! $audit->hash instanceof TreeHash) {
            throw new FailureException(sprintf('The bytes of [%s] were never read.', $audit->package));
        }

        return $audit->hash;
    }

    /**
     * @param  Collection<string, PackageAudit>  $audits
     */
    private function holdsPending(Collection $audits): bool
    {
        return $audits->contains(static fn (PackageAudit $audit): bool => $audit->pending());
    }

    private function delta(AuditProject $auditor, Project $project, PackageAudit $audit): ?Delta
    {
        $from = $this->option('from');
        assert($from === null || is_string($from));

        if ($audit->pending() && $from === null) {
            return $this->incomingDelta($project, $auditor, $audit);
        }

        $from ??= $audit->grant?->version;

        if ($from === null) {
            return null;
        }

        try {
            return ResolveDelta::forProject($project)->resolve(
                package: $audit->package,
                from: $from,
                to: $audit->pending() ? $audit->version : null,
            );
        } catch (VetException $vetException) {
            $this->components->warn(sprintf('Could not build a delta from [%s]: %s', $from, $vetException->getMessage()));

            return null;
        }
    }

    private function incomingDelta(Project $project, AuditProject $auditor, PackageAudit $audit): ?Delta
    {
        $operation = $auditor->plan()->of($audit->package);

        if (! $operation instanceof ComposerOperation) {
            return null;
        }

        $installed = $auditor->installed();

        try {
            return ResolveDelta::forProject($project)->incoming(
                target: $auditor->target($operation, $audit->version, $audit->dev),
                installed: $installed->has($audit->package) ? $installed->get($audit->package) : null,
            );
        } catch (VetException $vetException) {
            $this->components->warn(sprintf('Could not build a delta: %s', $vetException->getMessage()));

            return null;
        }
    }

    private function renderSubject(PackageAudit $audit): void
    {
        $this->newLine();
        $this->components->twoColumnDetail(
            sprintf('<options=bold>%s</>', $audit->package),
            sprintf(
                '%s <fg=gray>(%s)</>',
                $audit->versions(),
                $audit->status === AuditStatus::Ungranted ? 'never trusted' : 'bytes changed',
            ),
        );
        $this->components->twoColumnDetail(
            '<fg=gray>hash</>',
            sprintf('<fg=gray>%s (%s)</>', (string) $audit->hash, $audit->source->value),
        );
        $this->components->twoColumnDetail(
            '<fg=gray>contents</>',
            sprintf('<fg=gray>%d files</>', $audit->files),
        );

        if ($audit->pending()) {
            $this->components->twoColumnDetail(
                '<fg=gray>state</>',
                '<fg=gray>composer would write these bytes to vendor/</>',
            );
        }
    }

    /**
     * @return array<int, string>
     */
    private function packages(): array
    {
        $packages = $this->argument('packages');

        return (new Collection(is_array($packages) ? $packages : []))
            ->filter(static fn (mixed $package): bool => is_string($package) && $package !== '')
            ->values()
            ->all();
    }
}
