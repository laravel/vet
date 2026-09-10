<?php

declare(strict_types=1);

namespace App\Commands;

use App\Actions\AuditProject;
use App\Actions\RenderAgentReview;
use App\Actions\RenderDelta;
use App\Actions\RenderProjectAudit;
use App\Actions\ResolveDelta;
use App\Enums\AgentVerdict;
use App\Enums\AuditStatus;
use App\Enums\BucketType;
use App\Exceptions\FailureException;
use App\Exceptions\VetException;
use App\Support\Bytes;
use App\Support\ControlSafe;
use App\Support\Invitation;
use App\Support\Json;
use App\ValueObjects\AgentReview;
use App\ValueObjects\ComposerOperation;
use App\ValueObjects\ComposerPlan;
use App\ValueObjects\Delta;
use App\ValueObjects\Grant;
use App\ValueObjects\PackageAudit;
use App\ValueObjects\Project;
use App\ValueObjects\TreeHash;
use Illuminate\Support\Collection;
use Symfony\Component\Console\Output\OutputInterface;

use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\select;

final class VetCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'vet
        {packages?* : Audit these packages, as vendor/name}
        {--fresh : Record every package that vendor/ holds today, and start the trust file from them}
        {--agent : Hand each delta to your coding agent, and show the verdict it writes}
        {--model= : The model that the coding agent uses (defaults to the one of the agent)}
        {--from= : Show the delta from this version rather than the trusted one}
        {--to= : The version to compare to (defaults to the installed one)}
        {--notes= : A note to record alongside the entry}
        {--path= : The project directory to audit (defaults to the current one)}
        {--bucket= : Limit the delta to one bucket: install-manifest, opaque, runtime-source, inert}
        {--plan= : Audit the operations that this composer plan file holds}
        {--no-cache : Re-download archives instead of reusing the cache}
        {--json : Emit machine-readable output}';

    /**
     * @var string
     */
    protected $description = 'Audit what vendor/ holds, then record the packages that you trust';

    public function handle(): int
    {
        if (! $this->bucketIsKnown()) {
            $this->components->error(sprintf(
                'The [--bucket] option accepts [%s].',
                implode('], [', array_column(BucketType::cases(), 'value')),
            ));

            return self::FAILURE;
        }

        $path = $this->option('path');
        assert($path === null || is_string($path));

        try {
            $project = Project::locate($path ?? (string) getcwd());
            $auditor = AuditProject::forProject($project, $this->plan());
        } catch (VetException $vetException) {
            $this->components->error($vetException->getMessage());

            return self::FAILURE;
        }

        $packages = $this->packages();
        $fresh = $this->option('fresh') === true;

        if ($packages !== [] && $fresh) {
            $this->components->error('The [--fresh] option takes no package. Run [vet --fresh] or [vet <package>].');

            return self::FAILURE;
        }

        if ($packages !== []) {
            return $this->auditPackages($project, $auditor, $packages);
        }

        if ($this->option('from') !== null || $this->option('to') !== null) {
            $this->components->error('The [--from] and [--to] options need one package. Run [vet <package> --from=<version>].');

            return self::FAILURE;
        }

        if ($fresh) {
            return $this->trustInstalled($project, $auditor);
        }

        return $this->auditProject($project, $auditor);
    }

    private function bucket(): ?string
    {
        $bucket = $this->option('bucket');
        assert($bucket === null || is_string($bucket));

        return $bucket;
    }

    private function bucketIsKnown(): bool
    {
        $bucket = $this->bucket();

        return $bucket === null || BucketType::tryFrom($bucket) instanceof BucketType;
    }

    private function plan(): ?ComposerPlan
    {
        $path = $this->option('plan');
        assert($path === null || is_string($path));

        return $path === null ? null : ComposerPlan::fromFile($path);
    }

    private function auditProject(Project $project, AuditProject $auditor): int
    {
        $agentAsked = $this->option('agent') === true;

        try {
            $discrepancies = $auditor->lockDiscrepancies();

            $screen = new RenderProjectAudit(
                $this->output,
                $project,
                $auditor,
                $auditor->report(),
                Invitation::toReadTheInstalledTree(),
            );

            $screen->deltas();

            if ($agentAsked) {
                $screen->withAgentReviews($this->agentReviews($screen->agentDeltas()));
            }
        } catch (VetException $vetException) {
            $this->components->error($vetException->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            return $screen->json($discrepancies);
        }

        if (! $this->asksQuestions() || $discrepancies !== []) {
            return $screen->render($discrepancies, $agentAsked);
        }

        $screen->renderReport($agentAsked);

        if ($screen->failing() === []) {
            return self::SUCCESS;
        }

        $reviews = $agentAsked ? $screen->agentReviews() : [];

        if (! $agentAsked && $auditor->trustFile->exists() && $this->wantsAgentFirst()) {
            $reviews = $this->reviewWithAgent($screen);
        }

        return $this->pickPackages($auditor, $screen, $reviews);
    }

    /**
     * @param  array<string, AgentReview>  $reviews
     */
    private function pickPackages(AuditProject $auditor, RenderProjectAudit $screen, array $reviews): int
    {
        $failing = new Collection($screen->failing());

        $readable = $failing->filter(static fn (PackageAudit $audit): bool => $audit->hash instanceof TreeHash);

        if ($readable->isEmpty()) {
            return self::FAILURE;
        }

        $picked = new Collection(multiselect(
            label: 'Which packages do you trust?',
            options: $this->choices($readable, $reviews),
            default: $this->clearPackages($readable, $reviews),
            scroll: 10,
            hint: 'Press the space bar to pick a package, ctrl+a to pick every package, and enter to record the ones that you picked.',
        ));

        $recorded = $readable->filter(
            static fn (PackageAudit $audit): bool => $picked->contains($audit->package),
        );

        if ($recorded->isEmpty()) {
            $this->newLine();
            $this->components->info('Recorded nothing.');

            return self::FAILURE;
        }

        try {
            foreach ($recorded as $audit) {
                $auditor->trustFile->record($this->grantOf($audit));
            }

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

        return $recorded->count() === $failing->count() ? self::SUCCESS : self::FAILURE;
    }

    private function wantsAgentFirst(): bool
    {
        return select(
            label: 'How do you want to review these packages?',
            options: [
                'manual' => 'Manually, and pick the packages that I trust',
                'agent' => 'Automatically, with my coding agent reading the changes first',
            ],
            default: 'manual',
        ) === 'agent';
    }

    /**
     * @return array<string, AgentReview>
     */
    private function reviewWithAgent(RenderProjectAudit $screen): array
    {
        try {
            $reviews = $this->agentReviews($screen->agentDeltas());
        } catch (VetException $vetException) {
            $this->components->error($vetException->getMessage());

            return [];
        }

        $screen->withAgentReviews($reviews);
        $screen->renderAgentReviews();

        return $reviews;
    }

    /**
     * @param  Collection<string, PackageAudit>  $targets
     * @param  array<string, AgentReview>  $reviews
     * @return array<int, string>
     */
    private function clearPackages(Collection $targets, array $reviews): array
    {
        return $targets
            ->filter(static fn (PackageAudit $audit): bool => ($reviews[$audit->package] ?? null)?->verdict === AgentVerdict::Clear)
            ->keys()
            ->values()
            ->all();
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
                ControlSafe::text($audit->package),
                ControlSafe::text($audit->versions()),
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
            'composer would write [%d] package(s) that vendor/ does not hold. Run [vet] in a terminal to read them, or run [composer install] first.',
            $incoming->count(),
        ));
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
    private function auditPackages(Project $project, AuditProject $auditor, array $names): int
    {
        if (count($names) > 1 && ($this->option('from') !== null || $this->option('to') !== null)) {
            $this->components->error('The [--from] and [--to] options need one package. Run [vet <package> --from=<version>].');

            return self::FAILURE;
        }

        if (count($names) > 1 && $this->option('json') === true) {
            $this->components->error('The [--json] option needs one package. Run [vet <package> --json].');

            return self::FAILURE;
        }

        /** @var Collection<string, PackageAudit> $recorded */
        $recorded = new Collection;
        $status = self::SUCCESS;

        foreach ($names as $name) {
            if ($this->auditPackage($project, $auditor, $name, $recorded) === self::FAILURE) {
                $status = self::FAILURE;
            }
        }

        if ($recorded->isEmpty()) {
            return $status;
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

        return $status;
    }

    /**
     * @param  Collection<string, PackageAudit>  $recorded
     */
    private function auditPackage(Project $project, AuditProject $auditor, string $package, Collection $recorded): int
    {
        try {
            $audit = $auditor->auditOfName($package);
        } catch (VetException $vetException) {
            $this->components->error($vetException->getMessage());

            return self::FAILURE;
        }

        if ($audit->status === AuditStatus::Unknown) {
            $this->newLine();
            $this->components->error($audit->cause ?? 'Vet cannot read those bytes.');

            return self::FAILURE;
        }

        $requested = $this->option('from') !== null || $this->option('to') !== null;
        $renderer = new RenderDelta($this->output, Invitation::toReadTheInstalledTree());
        $delta = null;
        $unresolved = null;

        $from = $this->deltaFrom($audit);

        if ($from === null && $this->option('to') !== null) {
            $this->components->error(sprintf(
                'The [--to] option needs [--from], because vet holds no trusted version of [%s].',
                $audit->package,
            ));

            return self::FAILURE;
        }

        if ($audit->pending() && ! $requested) {
            $delta = $this->incomingDelta($project, $auditor, $audit);
        } elseif ($from !== null) {
            $to = $this->option('to');
            assert($to === null || is_string($to));

            try {
                $delta = ResolveDelta::forProject($project)->resolve(
                    package: $audit->package,
                    from: $from,
                    to: $to ?? ($audit->pending() ? $audit->version : null),
                );
            } catch (VetException $vetException) {
                if ($requested) {
                    $this->components->error($vetException->getMessage());

                    return self::FAILURE;
                }

                $unresolved = sprintf(
                    'Could not build the delta from the granted [%s]: %s',
                    $from,
                    $vetException->getMessage(),
                );
            }
        }

        $agentAsked = $this->option('agent') === true;
        $agentReview = null;

        if ($agentAsked) {
            try {
                $agentReviews = $this->agentReviews([$audit->package => $delta ?? $auditor->wholeTree($audit)]);
            } catch (VetException $vetException) {
                $this->components->error($vetException->getMessage());

                return self::FAILURE;
            }

            $agentReview = $agentReviews[$audit->package] ?? null;
        }

        $covered = $audit->status === AuditStatus::Covered;

        if ($this->option('json') === true) {
            $this->output->write(Json::encode([
                'package' => $audit->package,
                'version' => $audit->version,
                'status' => $audit->status->value,
                'state' => $audit->state->value,
                'from' => $audit->from,
                'source' => $audit->source->value,
                'hash' => (string) $audit->hash,
                'files' => $audit->files,
                'bytes' => $audit->bytes,
                'path' => $audit->path,
                'delta' => $delta instanceof Delta ? $renderer->toArray($delta) : null,
                'agent' => $agentReview?->toArray(),
            ]), false, OutputInterface::OUTPUT_RAW);

            return $covered ? self::SUCCESS : self::FAILURE;
        }

        $this->renderSubject($project, $audit);

        if ($audit->package !== $package) {
            $this->components->twoColumnDetail('provides', $package);
        }

        if ($agentReview instanceof AgentReview) {
            $this->newLine();
            (new RenderAgentReview($this->output))->verdict($agentReview);
        } elseif ($agentAsked) {
            $this->newLine();
            $agentRenderer = new RenderAgentReview($this->output);

            $delta instanceof Delta ? $agentRenderer->noChange() : $agentRenderer->noEarlierTree();
        }

        if ($delta instanceof Delta) {
            $renderer->report($delta, $this->bucket());
        } else {
            $this->newLine();

            if ($unresolved !== null) {
                $this->components->warn($unresolved);
            }
        }

        if ($covered) {
            return $this->recordNote($auditor, $audit, $recorded);
        }

        if ($this->option('notes') !== null) {
            $this->components->error(sprintf(
                '[%s] is not covered, so vet holds no entry for the note. Run [vet] to record it first.',
                $audit->package,
            ));

            return self::FAILURE;
        }

        $this->components->info('Record these bytes with [vet].');

        return self::FAILURE;
    }

    /**
     * @param  Collection<string, PackageAudit>  $recorded
     */
    private function recordNote(AuditProject $auditor, PackageAudit $audit, Collection $recorded): int
    {
        if ($this->option('notes') === null) {
            $this->components->info(sprintf(
                '[%s] [%s] is already covered (%s).',
                $audit->package,
                $audit->version,
                $audit->reason(),
            ));

            return self::SUCCESS;
        }

        $auditor->trustFile->record($this->grantOf($audit));

        $recorded->put($audit->package, $audit);

        return self::SUCCESS;
    }

    private function renderSubject(Project $project, PackageAudit $audit): void
    {
        $this->newLine();
        $this->components->twoColumnDetail(
            sprintf('<fg=default;options=bold>%s</>', $audit->package),
            sprintf('<fg=gray>%s</>', $audit->versions()),
        );

        if ($audit->pending()) {
            $this->components->twoColumnDetail('state', 'composer would write these bytes to vendor/');
        }

        $this->components->twoColumnDetail('hash', (string) $audit->hash);
        $this->components->twoColumnDetail('source', $audit->source->value);
        $this->components->twoColumnDetail(
            'contents',
            sprintf('%d files, %s', $audit->files, Bytes::human($audit->bytes)),
        );
        $this->components->twoColumnDetail(
            'path',
            $project->relativePath($audit->path ?? ''),
        );
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
        } catch (VetException) {
            return null;
        }
    }

    private function deltaFrom(PackageAudit $audit): ?string
    {
        $requested = $this->option('from');
        assert($requested === null || is_string($requested));

        if ($requested !== null) {
            return $requested;
        }

        if ($audit->status !== AuditStatus::Covered) {
            return $audit->grant?->version;
        }

        if ($this->option('to') === null) {
            return null;
        }

        return $audit->grant instanceof Grant ? $audit->grant->version : $audit->version;
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
