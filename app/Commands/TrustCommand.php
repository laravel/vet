<?php

declare(strict_types=1);

namespace App\Commands;

use App\Actions\AuditProject;
use App\Actions\RenderDelta;
use App\Actions\ResolveDelta;
use App\Enums\AuditStatus;
use App\Exceptions\FailureException;
use App\Exceptions\VetException;
use App\ValueObjects\ComposerOperation;
use App\ValueObjects\Delta;
use App\ValueObjects\Grant;
use App\ValueObjects\PackageAudit;
use App\ValueObjects\Project;
use App\ValueObjects\TreeHash;

final class TrustCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'trust
        {packages?* : Trust these packages, as vendor/name}
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

        return $packages === []
            ? $this->trustProject($project, $auditor)
            : $this->trustPackages($project, $auditor, $packages);
    }

    private function trustProject(Project $project, AuditProject $auditor): int
    {
        if ($this->option('from') !== null) {
            $this->components->error('The --from option needs one package. Run `vet trust <package>`.');

            return self::FAILURE;
        }

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
            $this->newLine();

            return self::SUCCESS;
        }

        $this->renderTargets($targets);

        $created = ! $auditor->trustFile->exists();
        $unreadable = array_filter($targets, static fn (PackageAudit $audit): bool => ! $audit->hash instanceof TreeHash);
        $recorded = array_filter($targets, static fn (PackageAudit $audit): bool => $audit->hash instanceof TreeHash);

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
        $this->components->info($created
            ? sprintf(
                'Trusted [%d] package(s), and wrote [%s].',
                count($recorded),
                $this->relative($project->rootPath, $auditor->trustFile->path),
            )
            : sprintf('Trusted [%d] package(s).', count($recorded)));

        if ($this->holdsPending($recorded)) {
            $this->components->info('Run `composer install` to write those bytes to vendor/.');
        }

        foreach ($unreadable as $audit) {
            $this->components->error(sprintf('[%s] stays unrecorded: %s', $audit->package, $audit->reason()));
        }

        return $unreadable === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string, PackageAudit>  $targets
     */
    private function renderTargets(array $targets): void
    {
        $this->line(sprintf('  <options=bold>to trust</> <fg=gray>(%d)</>', count($targets)));
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
            $this->components->error('The --from option needs one package. Run `vet trust <package> --from=<version>`.');

            return self::FAILURE;
        }

        $recorded = [];

        foreach ($names as $name) {
            try {
                $audit = $auditor->auditOfName($name);
            } catch (VetException $vetException) {
                $this->components->error($vetException->getMessage());

                return self::FAILURE;
            }

            if ($audit->status === AuditStatus::Unknown) {
                $this->newLine();
                $this->components->error($audit->cause ?? 'vet cannot read those bytes.');

                return self::FAILURE;
            }

            if ($audit->status === AuditStatus::Covered) {
                $this->newLine();
                $this->components->info(sprintf(
                    '[%s] [%s] is already covered (%s).',
                    $audit->package,
                    $audit->version,
                    $audit->reason(),
                ));

                continue;
            }

            $this->renderSubject($audit);

            $delta = $this->delta($auditor, $project, $audit);

            if ($delta instanceof Delta) {
                (new RenderDelta($this->output))->report($delta);
            } else {
                $this->newLine();
                $this->components->warn(sprintf('Review the tree at [%s] before you trust it.', $audit->path ?? ''));
            }

            $grant = $this->grantOf($audit);

            $auditor->trustFile->record($grant);

            $recorded[$audit->package] = $audit;
        }

        if ($recorded === []) {
            return self::SUCCESS;
        }

        try {
            $auditor->trustFile->save();
        } catch (VetException $vetException) {
            $this->components->error($vetException->getMessage());

            return self::FAILURE;
        }

        $first = array_values($recorded)[0];

        $this->components->info(count($recorded) === 1
            ? sprintf(
                'Recorded [%s] [%s] at [%s].',
                $first->package,
                $first->version,
                $first->hash instanceof TreeHash ? $first->hash->short() : '',
            )
            : sprintf('Recorded [%d] package(s).', count($recorded)));

        if ($this->holdsPending($recorded)) {
            $this->components->info('Run `composer install` to write those bytes to vendor/.');
        }

        return self::SUCCESS;
    }

    private function grantOf(PackageAudit $audit): Grant
    {
        if (! $audit->hash instanceof TreeHash) {
            throw new FailureException(sprintf('The bytes of [%s] were never read.', $audit->package));
        }

        $notes = $this->option('notes');
        assert($notes === null || is_string($notes));

        return new Grant(
            package: $audit->package,
            version: $audit->version,
            hash: $audit->hash,
            dev: $audit->dev,
            notes: $notes ?? $audit->grant?->notes,
        );
    }

    /**
     * @param  array<string, PackageAudit>  $audits
     */
    private function holdsPending(array $audits): bool
    {
        foreach ($audits as $audit) {
            if ($audit->pending()) {
                return true;
            }
        }

        return false;
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

        return is_array($packages)
            ? array_values(array_filter($packages, static fn (mixed $package): bool => is_string($package) && $package !== ''))
            : [];
    }

    private function relative(string $root, string $path): string
    {
        return str_starts_with($path, $root.'/') ? mb_substr($path, mb_strlen($root) + 1) : $path;
    }
}
