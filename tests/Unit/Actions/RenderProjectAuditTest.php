<?php

declare(strict_types=1);

use App\Actions\AuditProject;
use App\Actions\RenderProjectAudit;
use App\Enums\AuditStatus;
use App\Enums\InstallSourceType;
use App\Enums\PackageStatus;
use App\Support\Invitation;
use App\ValueObjects\AuditReport;
use App\ValueObjects\PackageAudit;
use App\ValueObjects\Project;
use Illuminate\Console\OutputStyle;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Fixtures\Fixture;
use Tests\Fixtures\PendingUpdate;
use Tests\Fixtures\StaleProject;

function projectAuditScreen(AuditProject $auditor, AuditReport $report, BufferedOutput $buffer): RenderProjectAudit
{
    return RenderProjectAudit::of(
        new OutputStyle(new ArrayInput([]), $buffer),
        $auditor,
        $report,
        Invitation::toReadTheInstalledTree(),
    );
}

it('reports that every package is covered', function (): void {
    $fixture = Fixture::open('audited-project');
    $buffer = new BufferedOutput;

    try {
        $auditor = AuditProject::forProject(Project::at($fixture->rootPath));
        $screen = projectAuditScreen($auditor, $auditor->report(), $buffer);

        $screen->renderReport();
    } finally {
        $fixture->remove();
    }

    expect($screen->failing())->toBe([])
        ->and($buffer->fetch())->toContain('All [2] packages are trusted.');
});

it('says why the agent read no delta of a package', function (): void {
    $project = StaleProject::amongUngranted(1);
    $buffer = new BufferedOutput;

    try {
        $auditor = AuditProject::forProject(Project::at($project->rootPath));
        $screen = projectAuditScreen($auditor, $auditor->report(), $buffer);

        $screen->renderAgentReviews();
    } finally {
        $project->remove();
    }

    expect($buffer->fetch())
        ->toContain('SKIP   No file changed, so vet sent nothing to the agent.')
        ->toContain('SKIP   Vet cannot read the files of this package, so it sent nothing to the agent.');
});

it('reviews the whole package of a pending audit that the plan does not hold', function (): void {
    $project = PendingUpdate::create();

    try {
        $auditor = AuditProject::forProject(Project::at($project->rootPath));

        $deltas = projectAuditScreen($auditor, new AuditReport([
            PendingUpdate::PACKAGE => new PackageAudit(
                package: PendingUpdate::PACKAGE,
                version: PendingUpdate::TARGET_VERSION,
                hash: null,
                dev: false,
                status: AuditStatus::Ungranted,
                files: 4,
                bytes: 0,
                grant: null,
                source: InstallSourceType::Dist,
                state: PackageStatus::Pending,
                from: null,
                cause: null,
                path: null,
            ),
        ]), new BufferedOutput)->deltas();
    } finally {
        $project->remove();
    }

    expect($deltas)->toBe([PendingUpdate::PACKAGE => null]);
});
