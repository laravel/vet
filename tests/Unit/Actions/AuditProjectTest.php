<?php

declare(strict_types=1);

use App\Actions\AuditProject;
use App\Enums\AuditStatus;
use App\ValueObjects\Change;
use App\ValueObjects\PackageAudit;
use App\ValueObjects\Project;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\File;
use Tests\Fixtures\FakeHttp;
use Tests\Fixtures\PendingUpdate;
use Tests\Fixtures\StaleProject;

it('reads no whole tree of a package whose bytes it cannot read, or that the project does not install', function (): void {
    $project = StaleProject::create();

    try {
        $auditor = AuditProject::forProject(Project::at($project->rootPath));

        $unknown = $auditor->wholeTree(new PackageAudit('acme/widget', '2.0.0', null, false, AuditStatus::Unknown, 0, 0));
        $missing = $auditor->wholeTree(new PackageAudit('acme/missing', '1.0.0', null, false, AuditStatus::Ungranted, 0, 0));
    } finally {
        $project->remove();
    }

    expect($unknown)->toBeNull()
        ->and($missing)->toBeNull();
});

it('reads no whole tree of an installed package whose bytes it cannot fetch', function (): void {
    $project = StaleProject::create();

    File::deleteDirectory($project->rootPath.'/vendor/acme/widget');
    app()->instance(ClientInterface::class, (new FakeHttp([new Response(404, [], 'missing')]))->client);

    try {
        $delta = AuditProject::forProject(Project::at($project->rootPath))
            ->wholeTree(new PackageAudit('acme/widget', '2.0.0', null, false, AuditStatus::Changed, 0, 0));
    } finally {
        $project->remove();
    }

    expect($delta)->toBeNull();
});

it('reads the whole tree that composer would install', function (): void {
    $project = PendingUpdate::create();
    $project->lockAt(PendingUpdate::TARGET_VERSION);

    try {
        $auditor = AuditProject::forProject(Project::at($project->rootPath));
        $delta = $auditor->wholeTree($auditor->auditOfName(PendingUpdate::PACKAGE));
    } finally {
        $project->remove();
    }

    expect($delta?->firstInstall)->toBeTrue()
        ->and($delta?->to)->toBe(PendingUpdate::TARGET_VERSION)
        ->and(array_map(static fn (Change $change): string => $change->path, $delta?->changes() ?? []))->toContain('src/Widget.php');
});
