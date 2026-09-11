<?php

declare(strict_types=1);

use App\Actions\AuditProject;
use App\Enums\AuditStatus;
use App\Enums\ComposerChangeType;
use App\Enums\InstallSourceType;
use App\Enums\PackageStatus;
use App\ValueObjects\Change;
use App\ValueObjects\ComposerOperation;
use App\ValueObjects\PackageAudit;
use App\ValueObjects\Project;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\File;
use Tests\Fixtures\FakeHttp;
use Tests\Fixtures\PendingUpdate;
use Tests\Fixtures\StaleProject;

function unreadAudit(string $package, AuditStatus $status): PackageAudit
{
    return new PackageAudit(
        package: $package,
        version: '2.0.0',
        hash: null,
        dev: false,
        status: $status,
        files: 0,
        bytes: 0,
        grant: null,
        source: InstallSourceType::Dist,
        state: PackageStatus::Installed,
        from: null,
        cause: null,
        path: null,
    );
}

it('reads no whole tree of a package whose bytes it cannot read, or that the project does not install', function (): void {
    $project = StaleProject::create();

    try {
        $auditor = AuditProject::forProject(Project::at($project->rootPath));

        $unknown = $auditor->wholeTree(unreadAudit('acme/widget', AuditStatus::Unknown));
        $missing = $auditor->wholeTree(unreadAudit('acme/missing', AuditStatus::Ungranted));
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
            ->wholeTree(unreadAudit('acme/widget', AuditStatus::Changed));
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

it('reads no incoming tree of a package that vendor/ does not hold', function (): void {
    $project = PendingUpdate::create();

    try {
        $delta = AuditProject::forProject(Project::at($project->rootPath))->incomingTree(
            unreadAudit('acme/gadget', AuditStatus::Ungranted),
            new ComposerOperation('acme/gadget', ComposerChangeType::Install, null, '2.0.0', null, null, null),
        );
    } finally {
        $project->remove();
    }

    expect($delta)->toBeNull();
});
