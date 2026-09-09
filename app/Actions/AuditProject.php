<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AuditStatus;
use App\Enums\PackageStatus;
use App\Exceptions\PackageInstallsNoTreeException;
use App\Exceptions\VetException;
use App\ValueObjects\AuditReport;
use App\ValueObjects\ComposerOperation;
use App\ValueObjects\ComposerPlan;
use App\ValueObjects\Fingerprint;
use App\ValueObjects\Grant;
use App\ValueObjects\InstalledRepository;
use App\ValueObjects\LockFile;
use App\ValueObjects\Package;
use App\ValueObjects\PackageAudit;
use App\ValueObjects\Project;
use App\ValueObjects\TrustFile;

final readonly class AuditProject
{
    public function __construct(
        public Project $project,
        public TrustFile $trustFile,
        private InstalledRepository $installed,
        private FingerprintPackage $fingerprinter,
        private LockFile $lock,
        private ComposerPlan $plan,
        private FetchPackageMetadata $packagist,
        private bool $useCache = true,
    ) {}

    public static function forProject(Project $project, ?ComposerPlan $plan = null, bool $useCache = true): self
    {
        $installed = InstalledRepository::fromProject($project);
        $lock = LockFile::fromProject($project);

        return new self(
            project: $project,
            trustFile: TrustFile::forProject($project),
            installed: $installed,
            fingerprinter: new FingerprintPackage(FetchArchive::default()),
            lock: $lock,
            plan: $plan ?? ComposerPlan::between($lock, $installed),
            packagist: FetchPackageMetadata::default(),
            useCache: $useCache,
        );
    }

    public function installed(): InstalledRepository
    {
        return $this->installed;
    }

    public function plan(): ComposerPlan
    {
        return $this->plan;
    }

    public function report(): AuditReport
    {
        $results = [];

        foreach ($this->installed->all() as $name => $package) {
            if ($this->plan->touches($name) || ! $this->installsTree($name)) {
                continue;
            }

            $results[$name] = $this->auditOf($package);
        }

        foreach ($this->plan->incoming() as $operation) {
            if (! $this->installsTree($operation->package)) {
                continue;
            }

            $results[$operation->package] = $this->auditOfIncoming($operation);
        }

        ksort($results, SORT_STRING);

        return new AuditReport($results);
    }

    public function auditOfName(string $name): PackageAudit
    {
        if (! $this->installsTree($name)) {
            throw PackageInstallsNoTreeException::named($name);
        }

        $operation = $this->plan->of($name);

        return $operation instanceof ComposerOperation && $operation->to !== null
            ? $this->auditOfIncoming($operation)
            : $this->auditOf($this->installed->get($name));
    }

    public function auditOf(Package $package): PackageAudit
    {
        $fingerprint = $this->fingerprinter->ofPackage($package);
        $grant = $this->trustFile->grantFor($package->name);

        return new PackageAudit(
            package: $package->name,
            version: $fingerprint->version,
            hash: $fingerprint->hash,
            dev: $package->dev,
            status: $this->statusOf($grant, $fingerprint),
            files: $fingerprint->files,
            bytes: $fingerprint->bytes,
            grant: $grant,
            source: $fingerprint->source,
            path: $fingerprint->path,
        );
    }

    public function auditOfIncoming(ComposerOperation $operation): PackageAudit
    {
        $grant = $this->trustFile->grantFor($operation->package);
        $version = $operation->to ?? '';
        $dev = $this->isDev($operation->package);

        try {
            $target = $this->target($operation, $version, $dev);
            $fingerprint = $this->fingerprinter->ofIncoming($target, $this->useCache);
        } catch (VetException $vetException) {
            return new PackageAudit(
                package: $operation->package,
                version: $version,
                hash: null,
                dev: $dev,
                status: AuditStatus::Unknown,
                files: 0,
                bytes: 0,
                grant: $grant,
                state: PackageStatus::Pending,
                from: $operation->from,
                cause: sprintf(
                    'composer would install [%s] and vet cannot read those bytes: %s',
                    $version,
                    $vetException->getMessage(),
                ),
            );
        }

        return new PackageAudit(
            package: $operation->package,
            version: $fingerprint->version,
            hash: $fingerprint->hash,
            dev: $dev,
            status: $this->statusOf($grant, $fingerprint),
            files: $fingerprint->files,
            bytes: $fingerprint->bytes,
            grant: $grant,
            source: $fingerprint->source,
            state: PackageStatus::Pending,
            from: $operation->from,
            path: $fingerprint->path,
        );
    }

    public function target(ComposerOperation $operation, string $version, bool $dev): Package
    {
        $locked = $this->lock->packages()[$operation->package] ?? null;

        $metadata = $locked instanceof Package && $locked->version === $version
            ? $locked
            : $this->packagist->version($operation->package, $version);

        return $metadata
            ->withDist($operation->distUrl, $operation->distReference, $operation->distShasum)
            ->withDev($dev);
    }

    /**
     * @return array<int, string> the discrepancies found
     */
    public function lockDiscrepancies(): array
    {
        $locked = $this->lock->packages();
        $installed = $this->installed->all();

        $problems = [];

        $explained = $this->plan->explains();

        foreach ($locked as $name => $package) {
            if ($explained && $this->plan->touches($name)) {
                continue;
            }

            if (! isset($installed[$name])) {
                $problems[] = sprintf('[%s] is in composer.lock at [%s] but is not installed', $name, $package->version);

                continue;
            }

            if ($installed[$name]->version !== $package->version) {
                $problems[] = sprintf(
                    '[%s] is installed at [%s] but composer.lock says [%s]',
                    $name,
                    $installed[$name]->version,
                    $package->version,
                );
            }
        }

        foreach ($installed as $name => $package) {
            if (! isset($locked[$name]) && (! $explained || ! $this->plan->touches($name))) {
                $problems[] = sprintf('[%s] is installed at [%s] but is not in composer.lock', $name, $package->version);
            }
        }

        return $problems;
    }

    private function installsTree(string $package): bool
    {
        $locked = $this->lock->packages()[$package] ?? null;

        if ($locked instanceof Package) {
            return $locked->installsTree();
        }

        return ! $this->installed->has($package) || $this->installed->get($package)->installsTree();
    }

    private function isDev(string $package): bool
    {
        $locked = $this->lock->packages()[$package] ?? null;

        if ($locked instanceof Package) {
            return $locked->dev;
        }

        return $this->installed->has($package) && $this->installed->get($package)->dev;
    }

    private function statusOf(?Grant $grant, Fingerprint $fingerprint): AuditStatus
    {
        if (! $grant instanceof Grant) {
            return AuditStatus::Ungranted;
        }

        return $grant->covers($fingerprint->hash) ? AuditStatus::Covered : AuditStatus::Changed;
    }
}
