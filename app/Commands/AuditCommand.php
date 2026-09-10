<?php

declare(strict_types=1);

namespace App\Commands;

use App\Actions\AuditProject;
use App\Actions\RenderAgentReview;
use App\Actions\RenderDelta;
use App\Actions\RenderProjectAudit;
use App\Actions\ResolveDelta;
use App\Enums\AuditScreen;
use App\Enums\AuditStatus;
use App\Enums\BucketType;
use App\Exceptions\VetException;
use App\Support\Bytes;
use App\Support\Invitation;
use App\Support\Json;
use App\ValueObjects\AgentReview;
use App\ValueObjects\ComposerOperation;
use App\ValueObjects\ComposerPlan;
use App\ValueObjects\Delta;
use App\ValueObjects\PackageAudit;
use App\ValueObjects\Project;
use Symfony\Component\Console\Output\OutputInterface;

final class AuditCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'audit
        {package? : Audit one installed package, as vendor/name}
        {--from= : Show the delta from this version rather than the trusted one}
        {--to= : The version to compare to (defaults to the installed one)}
        {--path= : The project directory to audit (defaults to the current one)}
        {--bucket= : Limit the delta to one bucket: install-manifest, opaque, runtime-source, inert}
        {--plan= : Audit the operations that this composer plan file holds}
        {--agent : Hand each delta to your coding agent, and show the verdict it writes}
        {--no-cache : Re-download archives instead of reusing the cache}
        {--json : Emit machine-readable output}';

    /**
     * @var string
     */
    protected $description = 'Show what is unaudited, worst first, or audit one package';

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
        } catch (VetException $vetException) {
            $this->components->error($vetException->getMessage());

            return self::FAILURE;
        }

        $package = $this->argument('package');
        assert($package === null || is_string($package));

        return $package === null
            ? $this->auditProject($project)
            : $this->auditPackage($project, $package);
    }

    private function useCache(): bool
    {
        return $this->option('no-cache') !== true;
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

    private function invitation(): Invitation
    {
        return $this->option('plan') === null
            ? AuditScreen::Installed->invitation()
            : AuditScreen::Planned->invitation();
    }

    private function plan(): ?ComposerPlan
    {
        $path = $this->option('plan');
        assert($path === null || is_string($path));

        return $path === null ? null : ComposerPlan::fromFile($path);
    }

    private function auditProject(Project $project): int
    {
        try {
            $auditor = AuditProject::forProject($project, $this->plan(), $this->useCache());

            $discrepancies = $auditor->lockDiscrepancies();

            $screen = new RenderProjectAudit(
                $this->output,
                $project,
                $auditor,
                $auditor->report(),
                $this->useCache(),
                AuditScreen::Installed,
                $this->invitation(),
            );

            $screen->withAgentReviews($this->agentReviews($screen->deltas($this->option('agent') === true)));
        } catch (VetException $vetException) {
            $this->components->error($vetException->getMessage());

            return self::FAILURE;
        }

        return $this->option('json') === true
            ? $screen->json($discrepancies)
            : $screen->render($discrepancies, $this->option('agent') === true);
    }

    private function auditPackage(Project $project, string $package): int
    {
        try {
            $auditor = AuditProject::forProject($project, $this->plan(), $this->useCache());
            $audit = $auditor->auditOfName($package);
        } catch (VetException $vetException) {
            $this->components->error($vetException->getMessage());

            return self::FAILURE;
        }

        $requested = $this->option('from') !== null;
        $renderer = new RenderDelta($this->output, $this->invitation());
        $delta = null;
        $unresolved = null;

        if ($audit->status === AuditStatus::Unknown) {
            $this->newLine();
            $this->components->error($audit->cause ?? 'Vet cannot read those bytes.');

            return self::FAILURE;
        }

        $from = $this->deltaFrom($audit);

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
                    useCache: $this->useCache(),
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

        $agentDelta = $delta ?? ($this->option('agent') === true ? $auditor->wholeTree($audit) : null);

        try {
            $agentReviews = $this->agentReviews([$audit->package => $agentDelta]);
        } catch (VetException $vetException) {
            $this->components->error($vetException->getMessage());

            return self::FAILURE;
        }

        $agentReview = $agentReviews[$audit->package] ?? null;
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

        $this->newLine();
        $this->components->twoColumnDetail(
            sprintf('<fg=default;options=bold>%s</>', $audit->package),
            sprintf('<fg=gray>%s</>', $audit->versions()),
        );

        if ($audit->package !== $package) {
            $this->components->twoColumnDetail('provides', $package);
        }

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

        if ($agentReview instanceof AgentReview) {
            $this->newLine();
            (new RenderAgentReview($this->output))->verdict($agentReview);
        } elseif ($this->option('agent') === true) {
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

        if (! $covered) {
            $this->components->info(sprintf('Record these bytes with [vet trust %s].', $audit->package));
        }

        return $covered ? self::SUCCESS : self::FAILURE;
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
                useCache: $this->useCache(),
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

        return $audit->status === AuditStatus::Covered ? null : $audit->grant?->version;
    }
}
